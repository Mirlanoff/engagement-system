<?php

namespace App\Infrastructure\WebSocket;

use App\Models\EngagementAlert;
use App\Models\LessonSession;
use Illuminate\Support\Facades\Log;

/**
 * Все WebSocket события системы.
 *
 * Каналы:
 *  presence-session.{sessionId}   — участники активного урока
 *  private-school.{schoolId}      — все супервайзеры школы
 *  private-supervisor.{userId}    — конкретный супервайзер
 */
class SessionBroadcaster
{
    // ── События сессии ───────────────────────────────────────────

    public function sessionStarted(LessonSession $session): void
    {
        try {
            broadcast(new \App\Events\SessionStarted($session))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('SessionStarted broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    public function sessionPaused(LessonSession $session): void
    {
        try {
            broadcast(new \App\Events\SessionPaused($session))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('SessionPaused broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    public function sessionResumed(LessonSession $session): void
    {
        try {
            broadcast(new \App\Events\SessionResumed($session))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('SessionResumed broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    public function sessionEnded(LessonSession $session): void
    {
        try {
            broadcast(new \App\Events\SessionEnded($session))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('SessionEnded broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Realtime обновление вовлечённости ────────────────────────

    public function engagementUpdate(
        string $sessionId,
        array $snapshots,
        float $classAvg
    ): void {
        // Отправляем агрегированные данные (не сырые снэпшоты)
        $payload = [
            'session_id' => $sessionId,
            'timestamp'  => now()->toIso8601String(),
            'class_avg'  => round($classAvg, 2),
            'students'   => array_map(fn ($s) => [
                'student_id'       => $s['student_id'],
                'score'            => round($s['engagement_score'], 2),
                'emotion'          => $s['emotion'] ?? null,
                'face_detected'    => $s['face_detected'] ?? true,
                'gaze_on_board'    => (abs($s['gaze_yaw'] ?? 999) < 25),
                'level'            => match(true) {
                    $s['engagement_score'] >= 75 => 'high',
                    $s['engagement_score'] >= 50 => 'medium',
                    default                       => 'low',
                },
            ], $snapshots),
        ];

        try {
            broadcast(new \App\Events\EngagementUpdated($sessionId, $payload))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('EngagementUpdated broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Алерты ──────────────────────────────────────────────────

    public function alert(string $sessionId, EngagementAlert $alert): void
    {
        $payload = [
            'alert_id'    => $alert->id,
            'session_id'  => $sessionId,
            'type'        => $alert->type,
            'severity'    => $alert->severity,
            'message'     => $alert->message,
            'student_id'  => $alert->student_id,
            'score'       => $alert->trigger_score,
            'triggered_at'=> $alert->triggered_at->toIso8601String(),
        ];

        try {
            broadcast(new \App\Events\EngagementUpdated($sessionId, [
                'type' => 'engagement.alert',
                'alert' => $payload,
            ]))->toOthers();
        } catch (\Throwable $e) {
            Log::warning('EngagementAlert broadcast failed', ['error' => $e->getMessage()]);
        }
    }
}
