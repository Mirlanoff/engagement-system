<?php

namespace App\Services;

use App\Models\LessonSession;
use App\Models\Classroom;
use App\Events\SessionStarted;
use App\Events\SessionEnded;
use App\Events\SessionPaused;
use App\Events\SessionResumed;
use App\Infrastructure\ML\MlServiceClient;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SessionService
{
    public function __construct(
        private readonly MlServiceClient $mlClient,
        private readonly AlertService $alertService,
    ) {}

    // ── Старт урока ─────────────────────────────────────────────

    public function start(string $classroomId, string $teacherId, ?string $subject): LessonSession
    {
        return DB::transaction(function () use ($classroomId, $teacherId, $subject) {

            // Закрываем незавершённые сессии в этом классе
            LessonSession::where('classroom_id', $classroomId)
                ->whereIn('status', ['active', 'paused'])
                ->update([
                    'status'   => 'cancelled',
                    'ended_at' => now(),
                ]);

            $classroom = Classroom::with('students')->findOrFail($classroomId);

            $session = LessonSession::create([
                'classroom_id'   => $classroomId,
                'teacher_id'     => $teacherId,
                'subject'        => $subject,
                'status'         => 'active',
                'started_at'     => now(),
                'students_count' => $classroom->students()->count(),
            ]);

            $session->load(['classroom', 'teacher']);

            // Команда ML-сервису начать захват по всем камерам класса
            try {
                $cameras = is_string($classroom->camera_config)
                    ? json_decode($classroom->camera_config, true)
                    : ($classroom->camera_config ?? []);
                $this->mlClient->startCapture(
                    sessionId: $session->id,
                    classroomId: $classroomId,
                    cameras: $cameras ?: [],
                    studentIds: $classroom->students->pluck('id')->all(),
                );
            } catch (\Throwable $e) {
                Log::warning('ML startCapture failed', ['error' => $e->getMessage()]);
            }

            // Broadcast через WebSocket
            try {
                broadcast(new SessionStarted($session))->toOthers();
            } catch (\Throwable $e) {
                Log::warning('SessionStarted broadcast failed', ['error' => $e->getMessage()]);
            }

            Log::info('Session started', [
                'session_id'   => $session->id,
                'classroom_id' => $classroomId,
                'teacher_id'   => $teacherId,
            ]);

            return $session;
        });
    }

    // ── Пауза ───────────────────────────────────────────────────

    public function pause(LessonSession $session): LessonSession
    {
        if ($session->status !== 'active') {
            throw new \DomainException("Нельзя поставить на паузу — урок не активен (статус: {$session->status})");
        }

        $session->update(['status' => 'paused']);

        try {
            $this->mlClient->pauseCapture($session->id);
        } catch (\Throwable $e) {
            Log::warning('ML pauseCapture failed', ['error' => $e->getMessage()]);
        }

        try {
            broadcast(new SessionPaused($session))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('SessionPaused broadcast failed', ['error' => $e->getMessage()]);
        }

        return $session->fresh(['classroom', 'teacher']);
    }

    // ── Возобновление ───────────────────────────────────────────

    public function resume(LessonSession $session): LessonSession
    {
        if ($session->status !== 'paused') {
            throw new \DomainException("Нельзя возобновить — урок не на паузе (статус: {$session->status})");
        }

        $session->update(['status' => 'active']);

        try {
            $this->mlClient->resumeCapture($session->id);
        } catch (\Throwable $e) {
            Log::warning('ML resumeCapture failed', ['error' => $e->getMessage()]);
        }

        try {
            broadcast(new SessionResumed($session))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('SessionResumed broadcast failed', ['error' => $e->getMessage()]);
        }

        return $session->fresh(['classroom', 'teacher']);
    }

    // ── Завершение урока ────────────────────────────────────────

    public function end(LessonSession $session): LessonSession
    {
        if (!in_array($session->status, ['active', 'paused'])) {
            throw new \DomainException("Нельзя завершить — урок уже завершён (статус: {$session->status})");
        }

        return DB::transaction(function () use ($session) {

            // Считаем итоговую статистику из снэпшотов
            $stats = $this->calculateStats($session->id);

            $session->update([
                'status'               => 'completed',
                'ended_at'             => now(),
                'avg_engagement_score' => $stats['avg'],
                'min_engagement_score' => $stats['min'],
                'max_engagement_score' => $stats['max'],
                'total_snapshots'      => $stats['total'],
            ]);

            try {
                $this->mlClient->stopCapture($session->id);
            } catch (\Throwable $e) {
                Log::warning('ML stopCapture failed', ['error' => $e->getMessage()]);
            }

            try {
                broadcast(new SessionEnded($session))->toOthers();
            } catch (\Throwable $e) {
                Log::warning('SessionEnded broadcast failed', ['error' => $e->getMessage()]);
            }

            Log::info('Session ended', [
                'session_id'    => $session->id,
                'avg_score'     => $stats['avg'],
                'total_minutes' => $session->fresh()->duration_minutes,
            ]);

            return $session->fresh(['classroom', 'teacher']);
        });
    }

    // ── Статистика урока ────────────────────────────────────────

    private function calculateStats(string $sessionId): array
    {
        $stats = DB::table('engagement_snapshots')
            ->where('session_id', $sessionId)
            ->selectRaw('
                ROUND(AVG(engagement_score)::numeric, 2) as avg,
                ROUND(MIN(engagement_score)::numeric, 2) as min,
                ROUND(MAX(engagement_score)::numeric, 2) as max,
                COUNT(*) as total
            ')
            ->first();

        return [
            'avg'   => $stats?->avg ?? 0,
            'min'   => $stats?->min ?? 0,
            'max'   => $stats?->max ?? 0,
            'total' => $stats?->total ?? 0,
        ];
    }

    // ── Приём снэпшотов от ML сервиса ──────────────────────────

    public function processSnapshots(string $sessionId, array $snapshots): void
    {
        $session = LessonSession::find($sessionId);

        if (!$session || $session->status !== 'active') {
            Log::warning('Snapshots received for non-active session', [
                'session_id' => $sessionId,
                'status'     => $session?->status,
            ]);
            return;
        }

        DB::transaction(function () use ($session, $snapshots) {

            // Bulk insert снэпшотов
            $records = array_map(fn($s) => [
                'id'                 => \Illuminate\Support\Str::uuid(),
                'session_id'         => $session->id,
                'student_id'         => $s['student_id'],
                'classroom_id'       => $session->classroom_id,
                'camera_id'          => $s['camera_id'],
                'captured_at'        => $s['captured_at'],
                'engagement_score'   => $s['engagement_score'],
                'gaze_score'         => $s['gaze_score'] ?? null,
                'emotion_score'      => $s['emotion_score'] ?? null,
                'head_pose_score'    => $s['head_pose_score'] ?? null,
                'presence_score'     => $s['presence_score'] ?? null,
                'emotion'            => $s['emotion'] ?? null,
                'emotion_confidence' => $s['emotion_confidence'] ?? null,
                'gaze_yaw'           => $s['gaze_yaw'] ?? null,
                'gaze_pitch'         => $s['gaze_pitch'] ?? null,
                'head_yaw'           => $s['head_yaw'] ?? null,
                'head_pitch'         => $s['head_pitch'] ?? null,
                'head_roll'          => $s['head_roll'] ?? null,
                'face_detected'      => $s['face_detected'] ?? true,
                'face_confidence'    => $s['face_confidence'] ?? null,
                'face_bbox_x'        => $s['face_bbox_x'] ?? null,
                'face_bbox_y'        => $s['face_bbox_y'] ?? null,
                'face_bbox_w'        => $s['face_bbox_w'] ?? null,
                'face_bbox_h'        => $s['face_bbox_h'] ?? null,
                'processing_time_ms' => $s['processing_time_ms'] ?? null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ], $snapshots);

            DB::table('engagement_snapshots')->insert($records);
            $this->updateMinuteAggregates($session, $snapshots);

            $classAvg = collect($snapshots)->avg('engagement_score');
            $this->broadcastUpdate($session->id, $snapshots, $classAvg);
            $this->alertService->checkThresholds($session, $snapshots, $classAvg);
        });
    }

    // ── Realtime broadcast ──────────────────────────────────────

    private function broadcastUpdate(string $sessionId, array $snapshots, float $classAvg): void
    {
        try {
            $payload = [
                'session_id' => $sessionId,
                'timestamp'  => now()->toIso8601String(),
                'class_avg'  => round($classAvg, 2),
                'students'   => array_map(fn($s) => [
                    'student_id'    => $s['student_id'],
                    'score'         => round($s['engagement_score'], 2),
                    'emotion'       => $s['emotion'] ?? null,
                    'face_detected' => $s['face_detected'] ?? true,
                    'gaze_on_board' => abs($s['gaze_yaw'] ?? 999) < 25,
                    'level'         => match(true) {
                        $s['engagement_score'] >= 75 => 'high',
                        $s['engagement_score'] >= 50 => 'medium',
                        default                       => 'low',
                    },
                ], $snapshots),
            ];

            broadcast(new \App\Events\EngagementUpdated($sessionId, $payload));

        } catch (\Throwable $e) {
            Log::warning('EngagementUpdated broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    private function updateMinuteAggregates(LessonSession $session, array $snapshots): void
    {
        $minutes = collect($snapshots)
            ->pluck('captured_at')
            ->filter()
            ->map(fn ($capturedAt) => Carbon::parse($capturedAt)->startOfMinute()->toDateTimeString())
            ->unique()
            ->values();

        foreach ($minutes as $minuteAt) {
            $stats = DB::table('engagement_snapshots')
                ->selectRaw('AVG(engagement_score) as avg_score')
                ->selectRaw('MIN(engagement_score) as min_score')
                ->selectRaw('MAX(engagement_score) as max_score')
                ->selectRaw('STDDEV_POP(engagement_score) as std_dev')
                ->selectRaw('COUNT(DISTINCT student_id) as students_detected')
                ->selectRaw('COUNT(*) as snapshots_count')
                ->where('session_id', $session->id)
                ->where('captured_at', '>=', Carbon::parse($minuteAt))
                ->where('captured_at', '<', Carbon::parse($minuteAt)->addMinute())
                ->first();

            if ($stats === null || (int) $stats->snapshots_count === 0) {
                continue;
            }

            $keys = [
                'session_id'       => $session->id,
                'minute_at'        => $minuteAt,
                'interval_minutes' => 1,
            ];

            $values = [
                'classroom_id'       => $session->classroom_id,
                'avg_score'          => round((float) $stats->avg_score, 2),
                'min_score'          => round((float) $stats->min_score, 2),
                'max_score'          => round((float) $stats->max_score, 2),
                'std_dev'            => $stats->std_dev === null ? null : round((float) $stats->std_dev, 2),
                'students_detected'  => (int) $stats->students_detected,
                'snapshots_count'    => (int) $stats->snapshots_count,
                'created_at'         => now(),
                'updated_at'         => now(),
            ];

            $exists = DB::table('engagement_aggregates')->where($keys)->exists();
            if ($exists) {
                unset($values['created_at']);
                DB::table('engagement_aggregates')->where($keys)->update($values);
                continue;
            }

            DB::table('engagement_aggregates')->insert($keys + ['id' => Str::uuid()] + $values);
        }
    }
}
