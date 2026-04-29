<?php

namespace App\Domain\Alert\Services;

use App\Models\AlertThreshold;
use App\Models\EngagementAlert;
use App\Models\LessonSession;
use App\Infrastructure\WebSocket\SessionBroadcaster;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class AlertService
{
    public function __construct(
        private readonly SessionBroadcaster $broadcaster,
    ) {}

    public function checkThresholds(
        LessonSession $session,
        array $snapshots,
        float $classAvg
    ): void {
        $thresholds = $this->getThresholds($session->classroom_id);

        // ── 1. Низкая вовлечённость класса ──────────────────────
        if ($classAvg < $thresholds->low_class_threshold) {
            $this->maybeFire($session, null, 'low_class_engagement', [
                'score'     => $classAvg,
                'threshold' => $thresholds->low_class_threshold,
                'severity'  => $classAvg < 30 ? 'critical' : 'warning',
                'message'   => "Вовлечённость класса упала до {$classAvg}%",
            ]);
        }

        // ── 2. Низкая вовлечённость отдельных студентов ─────────
        foreach ($snapshots as $snapshot) {
            if ($snapshot['engagement_score'] < $thresholds->low_student_threshold) {
                $this->maybeFire($session, $snapshot['student_id'], 'low_student_engagement', [
                    'score'     => $snapshot['engagement_score'],
                    'threshold' => $thresholds->low_student_threshold,
                    'severity'  => 'warning',
                    'message'   => "Студент показывает низкую вовлечённость: {$snapshot['engagement_score']}%",
                ]);
            }

            // ── 3. Студент отсутствует (лицо не обнаружено) ─────
            if (!($snapshot['face_detected'] ?? true)) {
                $this->checkAbsence($session, $snapshot['student_id'], $thresholds);
            }
        }

        // ── 4. Резкое падение вовлечённости ─────────────────────
        $this->checkRapidDecline($session, $classAvg, $thresholds);
    }

    // ── Проверка: не было ли уже недавнего алерта того же типа ──

    private function maybeFire(
        LessonSession $session,
        ?string $studentId,
        string $type,
        array $data
    ): void {
        // Дедупликация: один алерт одного типа не чаще чем раз в 2 минуты
        $cacheKey = "alert:{$session->id}:{$type}:" . ($studentId ?? 'class');
        if (Cache::has($cacheKey)) {
            return;
        }
        Cache::put($cacheKey, true, now()->addMinutes(2));

        $alert = EngagementAlert::create([
            'session_id'      => $session->id,
            'classroom_id'    => $session->classroom_id,
            'student_id'      => $studentId,
            'type'            => $type,
            'severity'        => $data['severity'] ?? 'warning',
            'trigger_score'   => $data['score'],
            'threshold_score' => $data['threshold'],
            'message'         => $data['message'],
            'context'         => $data['context'] ?? [],
            'triggered_at'    => now(),
        ]);

        // Broadcast алерт супервайзеру
        $this->broadcaster->alert($session->id, $alert);

        Log::info("Alert fired: {$type}", [
            'session_id' => $session->id,
            'student_id' => $studentId,
            'score'      => $data['score'],
        ]);
    }

    private function checkAbsence(
        LessonSession $session,
        string $studentId,
        AlertThreshold $thresholds
    ): void {
        $absentKey = "absent:{$session->id}:{$studentId}";
        $absentSince = Cache::get($absentKey);

        if (!$absentSince) {
            Cache::put($absentKey, now()->timestamp, now()->addMinutes(30));
            return;
        }

        $absentMinutes = (now()->timestamp - $absentSince) / 60;
        if ($absentMinutes >= $thresholds->absent_minutes_threshold) {
            $this->maybeFire($session, $studentId, 'student_absent', [
                'score'     => 0,
                'threshold' => 0,
                'severity'  => 'warning',
                'message'   => "Студент не обнаружен в кадре более {$thresholds->absent_minutes_threshold} мин.",
                'context'   => ['absent_minutes' => round($absentMinutes, 1)],
            ]);
        }
    }

    private function checkRapidDecline(
        LessonSession $session,
        float $currentScore,
        AlertThreshold $thresholds
    ): void {
        $historyKey   = "score_history:{$session->id}";
        $windowSec    = $thresholds->rapid_decline_window_seconds;
        $history      = Cache::get($historyKey, []);
        $now          = now()->timestamp;

        $history[] = ['score' => $currentScore, 'ts' => $now];
        // Оставляем только точки в окне
        $history = array_filter($history, fn($p) => ($now - $p['ts']) <= $windowSec);
        $history = array_values($history);
        Cache::put($historyKey, $history, now()->addMinutes(10));

        if (count($history) < 3) return;

        $oldest = $history[0]['score'];
        $delta  = $oldest - $currentScore;

        if ($delta >= $thresholds->rapid_decline_delta) {
            $this->maybeFire($session, null, 'rapid_decline', [
                'score'     => $currentScore,
                'threshold' => $thresholds->rapid_decline_delta,
                'severity'  => 'critical',
                'message'   => "Резкое падение вовлечённости на {$delta}% за {$windowSec} сек.",
                'context'   => ['from' => $oldest, 'to' => $currentScore, 'delta' => $delta],
            ]);
        }
    }

    private function getThresholds(string $classroomId): AlertThreshold
    {
        return Cache::remember(
            "thresholds:{$classroomId}",
            now()->addMinutes(10),
            fn () => AlertThreshold::where('classroom_id', $classroomId)->first()
                ?? AlertThreshold::whereNull('classroom_id')
                    ->whereHas('school', fn ($q) => $q->whereHas(
                        'classrooms', fn ($q2) => $q2->where('id', $classroomId)
                    ))
                    ->first()
                ?? new AlertThreshold() // дефолтные значения
        );
    }
}
