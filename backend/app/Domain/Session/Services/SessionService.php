<?php

namespace App\Domain\Session\Services;

use App\Domain\Session\DTOs\StartSessionDTO;
use App\Domain\Engagement\Services\EngagementAggregatorService;
use App\Domain\Alert\Services\AlertService;
use App\Domain\Recommendation\Services\AiRecommendationService;
use App\Infrastructure\WebSocket\SessionBroadcaster;
use App\Infrastructure\ML\MlServiceClient;
use App\Models\Classroom;
use App\Models\EngagementSnapshot;
use App\Models\LessonSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SessionService
{
    public function __construct(
        private readonly EngagementAggregatorService $aggregator,
        private readonly AlertService $alertService,
        private readonly AiRecommendationService $aiService,
        private readonly SessionBroadcaster $broadcaster,
        private readonly MlServiceClient $mlClient,
    ) {}

    // ── Старт урока ─────────────────────────────────────────────

    public function start(StartSessionDTO $dto): LessonSession
    {
        return DB::transaction(function () use ($dto) {
            // Завершаем незакрытые сессии в этом классе (защита от дублей)
            LessonSession::forClassroom($dto->classroomId)
                ->active()
                ->update(['status' => 'cancelled', 'ended_at' => now()]);

            $session = LessonSession::create([
                'classroom_id' => $dto->classroomId,
                'teacher_id'   => $dto->teacherId,
                'subject'      => $dto->subject,
                'status'       => 'active',
                'started_at'   => now(),
                'students_count' => Classroom::find($dto->classroomId)
                    ->students()->active()->count(),
            ]);

            // Команда ML сервису — начать захват по всем камерам класса
            $this->mlClient->startCapture(
                sessionId: $session->id,
                classroomId: $dto->classroomId,
                cameras: $session->classroom->camera_config,
            );

            // Трансляция события через WebSocket
            $this->broadcaster->sessionStarted($session);

            Log::info('Lesson session started', [
                'session_id'   => $session->id,
                'classroom_id' => $dto->classroomId,
                'teacher_id'   => $dto->teacherId,
            ]);

            return $session->fresh(['classroom', 'teacher']);
        });
    }

    // ── Пауза/возобновление ─────────────────────────────────────

    public function pause(LessonSession $session): LessonSession
    {
        $this->ensureSessionIsActive($session);

        $session->update(['status' => 'paused']);
        $this->mlClient->pauseCapture($session->id);
        $this->broadcaster->sessionPaused($session);

        return $session;
    }

    public function resume(LessonSession $session): LessonSession
    {
        if ($session->status !== 'paused') {
            throw new \DomainException("Session is not paused.");
        }

        $session->update(['status' => 'active']);
        $this->mlClient->resumeCapture($session->id);
        $this->broadcaster->sessionResumed($session);

        return $session;
    }

    // ── Завершение урока ────────────────────────────────────────

    public function end(LessonSession $session): LessonSession
    {
        $this->ensureSessionIsActive($session, allowPaused: true);

        return DB::transaction(function () use ($session) {
            // Останавливаем ML захват
            $this->mlClient->stopCapture($session->id);

            // Считаем итоговую статистику
            $stats = $this->aggregator->calculateSessionStats($session);

            $session->update([
                'status'               => 'completed',
                'ended_at'             => now(),
                'avg_engagement_score' => $stats['avg'],
                'min_engagement_score' => $stats['min'],
                'max_engagement_score' => $stats['max'],
                'total_snapshots'      => $stats['total_snapshots'],
                'engagement_timeline'  => $stats['timeline'],
            ]);

            // Генерируем AI рекомендации (в фоне)
            $this->aiService->generatePostLessonRecommendation($session);

            $this->broadcaster->sessionEnded($session);

            Log::info('Lesson session ended', [
                'session_id'  => $session->id,
                'duration'    => $session->duration_minutes,
                'avg_score'   => $stats['avg'],
            ]);

            return $session->fresh();
        });
    }

    // ── Приём снэпшотов от ML сервиса ──────────────────────────

    public function processIncomingSnapshots(string $sessionId, array $snapshots): void
    {
        $session = LessonSession::findOrFail($sessionId);

        if (!$session->isActive()) {
            Log::warning('Snapshots received for non-active session', [
                'session_id' => $sessionId,
                'status'     => $session->status,
            ]);
            return;
        }

        DB::transaction(function () use ($session, $snapshots) {
            // Bulk insert снэпшотов
            $records = array_map(fn ($s) => $this->prepareSnapshotRecord($session, $s), $snapshots);
            EngagementSnapshot::insert($records);

            // Обновляем агрегаты по минуте
            $this->aggregator->updateMinuteAggregate($session, $snapshots);

            // Проверяем пороги и генерируем алерты
            $classAvg = collect($snapshots)->avg('engagement_score');
            $this->alertService->checkThresholds($session, $snapshots, $classAvg);

            // Широковещание realtime обновления
            $this->broadcaster->engagementUpdate($session->id, $snapshots, $classAvg);
        });
    }

    // ── Private ─────────────────────────────────────────────────

    private function prepareSnapshotRecord(LessonSession $session, array $s): array
    {
        return [
            'id'                  => \Illuminate\Support\Str::uuid(),
            'session_id'          => $session->id,
            'student_id'          => $s['student_id'],
            'classroom_id'        => $session->classroom_id,
            'camera_id'           => $s['camera_id'],
            'captured_at'         => $s['captured_at'],
            'engagement_score'    => $s['engagement_score'],
            'gaze_score'          => $s['gaze_score'] ?? null,
            'emotion_score'       => $s['emotion_score'] ?? null,
            'head_pose_score'     => $s['head_pose_score'] ?? null,
            'presence_score'      => $s['presence_score'] ?? null,
            'emotion'             => $s['emotion'] ?? null,
            'emotion_confidence'  => $s['emotion_confidence'] ?? null,
            'gaze_yaw'            => $s['gaze_yaw'] ?? null,
            'gaze_pitch'          => $s['gaze_pitch'] ?? null,
            'head_yaw'            => $s['head_yaw'] ?? null,
            'head_pitch'          => $s['head_pitch'] ?? null,
            'head_roll'           => $s['head_roll'] ?? null,
            'face_detected'       => $s['face_detected'] ?? true,
            'face_confidence'     => $s['face_confidence'] ?? null,
            'processing_time_ms'  => $s['processing_time_ms'] ?? null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ];
    }

    private function ensureSessionIsActive(LessonSession $session, bool $allowPaused = false): void
    {
        $valid = $session->isActive() || ($allowPaused && $session->isPaused());
        if (!$valid) {
            throw new \DomainException("Session '{$session->id}' is not active (status: {$session->status}).");
        }
    }
}
