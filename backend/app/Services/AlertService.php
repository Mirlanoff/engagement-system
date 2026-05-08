<?php

namespace App\Services;

use App\Events\EngagementAlertTriggered;
use App\Models\AlertThreshold;
use App\Models\EngagementAlert;
use App\Models\LessonSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AlertService
{
    private const CRITICAL_THRESHOLD = 30.0;

    public function checkThresholds(LessonSession $session, array $snapshots, float $classAvg): void
    {
        $session->loadMissing('classroom.students');
        $thresholds = AlertThreshold::resolveFor($session->classroom);

        $this->checkLowClassEngagement($session, $thresholds, $classAvg);
        $this->checkLowStudentEngagement($session, $thresholds, $snapshots);
        $this->checkAbsence($session, $thresholds, $snapshots);
        $this->checkRapidDecline($session, $thresholds, $snapshots, $classAvg);
        $this->checkProlongedLow($session, $thresholds, $snapshots);
    }

    private function checkLowClassEngagement(LessonSession $session, AlertThreshold $thresholds, float $classAvg): void
    {
        $threshold = (float) $thresholds->low_class_threshold;
        if ($classAvg >= $threshold) {
            return;
        }

        $score = round($classAvg, 2);
        $this->fireAlert($session, null, 'low_class_engagement', [
            'severity'  => $score < self::CRITICAL_THRESHOLD ? 'critical' : 'warning',
            'score'     => $score,
            'threshold' => $threshold,
            'message'   => "Вовлечённость класса упала до {$score}%",
            'context'   => ['source' => 'rtsp', 'metric' => 'class_avg'],
        ]);
    }

    private function checkLowStudentEngagement(LessonSession $session, AlertThreshold $thresholds, array $snapshots): void
    {
        $threshold = (float) $thresholds->low_student_threshold;
        foreach ($snapshots as $snapshot) {
            $score = (float) $snapshot['engagement_score'];
            if ($score >= $threshold) {
                continue;
            }

            $studentId = (string) $snapshot['student_id'];
            $studentName = $this->studentName($session, $studentId);
            $score = round($score, 2);

            $this->fireAlert($session, $studentId, 'low_student_engagement', [
                'severity'  => $score < self::CRITICAL_THRESHOLD ? 'critical' : 'warning',
                'score'     => $score,
                'threshold' => $threshold,
                'message'   => "Низкая вовлечённость у {$studentName}: {$score}%",
                'context'   => [
                    'source'        => 'rtsp',
                    'camera_id'     => $snapshot['camera_id'] ?? null,
                    'face_detected' => (bool) ($snapshot['face_detected'] ?? true),
                ],
            ]);
        }
    }

    private function checkAbsence(LessonSession $session, AlertThreshold $thresholds, array $snapshots): void
    {
        $minutes = (int) $thresholds->absent_minutes_threshold;
        $cutoff = now()->subMinutes($minutes);

        foreach ($snapshots as $snapshot) {
            if (($snapshot['face_detected'] ?? true) === true) {
                continue;
            }

            $studentId = (string) $snapshot['student_id'];
            if (!$this->hasContinuousAbsence($session->id, $studentId, $cutoff)) {
                continue;
            }

            $studentName = $this->studentName($session, $studentId);
            $this->fireAlert($session, $studentId, 'student_absent', [
                'severity'  => 'warning',
                'score'     => 0.0,
                'threshold' => null,
                'message'   => "{$studentName} отсутствует в кадре {$minutes} мин.",
                'context'   => ['source' => 'rtsp', 'window_minutes' => $minutes],
            ], cooldownMinutes: 5);
        }
    }

    private function checkRapidDecline(LessonSession $session, AlertThreshold $thresholds, array $snapshots, float $classAvg): void
    {
        $windowSeconds = (int) $thresholds->rapid_decline_window_seconds;
        $delta = (float) $thresholds->rapid_decline_delta;
        $reference = $this->latestCapturedAt($snapshots);
        $previousAvg = DB::table('engagement_snapshots')
            ->where('session_id', $session->id)
            ->whereBetween('captured_at', [
                $reference->copy()->subSeconds($windowSeconds * 2),
                $reference->copy()->subSeconds($windowSeconds),
            ])
            ->avg('engagement_score');

        if ($previousAvg === null || ((float) $previousAvg - $classAvg) < $delta) {
            return;
        }

        $score = round($classAvg, 2);
        $this->fireAlert($session, null, 'rapid_decline', [
            'severity'  => 'critical',
            'score'     => $score,
            'threshold' => round((float) $previousAvg - $delta, 2),
            'message'   => "Резкое падение вовлечённости: {$score}%",
            'context'   => [
                'source'          => 'rtsp',
                'previous_avg'    => round((float) $previousAvg, 2),
                'delta'           => round((float) $previousAvg - $classAvg, 2),
                'window_seconds'  => $windowSeconds,
            ],
        ]);
    }

    private function checkProlongedLow(LessonSession $session, AlertThreshold $thresholds, array $snapshots): void
    {
        $minutes = (int) $thresholds->prolonged_low_minutes;
        $cutoff = now()->subMinutes($minutes);
        $classThreshold = (float) $thresholds->low_class_threshold;
        $studentThreshold = (float) $thresholds->low_student_threshold;

        $classAvg = DB::table('engagement_snapshots')
            ->where('session_id', $session->id)
            ->where('captured_at', '>=', $cutoff)
            ->avg('engagement_score');

        if ($classAvg !== null && (float) $classAvg < $classThreshold) {
            $score = round((float) $classAvg, 2);
            $this->fireAlert($session, null, 'prolonged_low', [
                'severity'  => $score < self::CRITICAL_THRESHOLD ? 'critical' : 'warning',
                'score'     => $score,
                'threshold' => $classThreshold,
                'message'   => "Класс сохраняет низкую вовлечённость {$minutes} мин.: {$score}%",
                'context'   => ['source' => 'rtsp', 'window_minutes' => $minutes, 'scope' => 'class'],
            ], cooldownMinutes: $minutes);
        }

        foreach ($snapshots as $snapshot) {
            $studentId = (string) $snapshot['student_id'];
            $studentAvg = DB::table('engagement_snapshots')
                ->where('session_id', $session->id)
                ->where('student_id', $studentId)
                ->where('captured_at', '>=', $cutoff)
                ->avg('engagement_score');

            if ($studentAvg === null || (float) $studentAvg >= $studentThreshold) {
                continue;
            }

            $score = round((float) $studentAvg, 2);
            $studentName = $this->studentName($session, $studentId);
            $this->fireAlert($session, $studentId, 'prolonged_low', [
                'severity'  => $score < self::CRITICAL_THRESHOLD ? 'critical' : 'warning',
                'score'     => $score,
                'threshold' => $studentThreshold,
                'message'   => "{$studentName} сохраняет низкую вовлечённость {$minutes} мин.: {$score}%",
                'context'   => ['source' => 'rtsp', 'window_minutes' => $minutes, 'scope' => 'student'],
            ], cooldownMinutes: $minutes);
        }
    }

    private function fireAlert(
        LessonSession $session,
        ?string $studentId,
        string $type,
        array $payload,
        int $cooldownMinutes = 2,
    ): ?EngagementAlert {
        $cacheKey = 'alert:' . implode(':', [$session->id, $studentId ?: 'class', $type]);
        if (Cache::has($cacheKey)) {
            return null;
        }

        Cache::put($cacheKey, true, now()->addMinutes($cooldownMinutes));

        $alert = EngagementAlert::create([
            'session_id'        => $session->id,
            'classroom_id'      => $session->classroom_id,
            'student_id'        => $studentId,
            'type'              => $type,
            'severity'          => $payload['severity'],
            'trigger_score'     => $payload['score'],
            'threshold_score'   => $payload['threshold'],
            'message'           => $payload['message'],
            'context'           => $payload['context'] ?? [],
            'is_acknowledged'   => false,
            'triggered_at'      => now(),
        ]);

        try {
            broadcast(new EngagementAlertTriggered($alert));
        } catch (\Throwable $e) {
            Log::warning('Engagement alert broadcast failed', [
                'alert_id' => $alert->id,
                'error'    => $e->getMessage(),
            ]);
        }

        return $alert;
    }

    private function hasContinuousAbsence(string $sessionId, string $studentId, Carbon $cutoff): bool
    {
        $lastDetectedAt = DB::table('engagement_snapshots')
            ->where('session_id', $sessionId)
            ->where('student_id', $studentId)
            ->where('face_detected', true)
            ->max('captured_at');

        if ($lastDetectedAt !== null && Carbon::parse($lastDetectedAt)->greaterThan($cutoff)) {
            return false;
        }

        $firstAbsentAt = DB::table('engagement_snapshots')
            ->where('session_id', $sessionId)
            ->where('student_id', $studentId)
            ->where('face_detected', false)
            ->when($lastDetectedAt !== null, fn ($query) => $query->where('captured_at', '>', $lastDetectedAt))
            ->min('captured_at');

        return $firstAbsentAt !== null && Carbon::parse($firstAbsentAt)->lessThanOrEqualTo($cutoff);
    }

    private function latestCapturedAt(array $snapshots): Carbon
    {
        $latest = collect($snapshots)->pluck('captured_at')->filter()->max();
        return $latest === null ? now() : Carbon::parse($latest);
    }

    private function studentName(LessonSession $session, string $studentId): string
    {
        $student = $session->classroom?->students?->firstWhere('id', $studentId);
        return $student?->name ?? 'Студент';
    }
}
