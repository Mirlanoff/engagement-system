<?php

namespace App\Infrastructure\WebSocket;

use App\Domain\Session\Models\LessonSession;
use App\Domain\Alert\Models\EngagementAlert;
use Illuminate\Support\Facades\Broadcast;

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
        Broadcast::on("private-school.{$session->classroom->school_id}")
            ->as('session.started')
            ->with([
                'session_id'    => $session->id,
                'classroom_id'  => $session->classroom_id,
                'classroom_name'=> $session->classroom->name,
                'subject'       => $session->subject,
                'teacher'       => $session->teacher?->name,
                'started_at'    => $session->started_at->toIso8601String(),
                'students_count'=> $session->students_count,
            ])
            ->send();
    }

    public function sessionPaused(LessonSession $session): void
    {
        Broadcast::on("presence-session.{$session->id}")
            ->as('session.paused')
            ->with(['session_id' => $session->id])
            ->send();
    }

    public function sessionResumed(LessonSession $session): void
    {
        Broadcast::on("presence-session.{$session->id}")
            ->as('session.resumed')
            ->with(['session_id' => $session->id])
            ->send();
    }

    public function sessionEnded(LessonSession $session): void
    {
        Broadcast::on("presence-session.{$session->id}")
            ->as('session.ended')
            ->with([
                'session_id'        => $session->id,
                'duration_minutes'  => $session->duration_minutes,
                'avg_score'         => $session->avg_engagement_score,
                'ended_at'          => $session->ended_at->toIso8601String(),
            ])
            ->send();
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

        Broadcast::on("presence-session.{$sessionId}")
            ->as('engagement.update')
            ->with($payload)
            ->send();
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

        // Школьный канал (все супервайзеры)
        Broadcast::on("private-school.{$alert->classroom->school_id}")
            ->as('engagement.alert')
            ->with($payload)
            ->send();

        // Канал сессии
        Broadcast::on("presence-session.{$sessionId}")
            ->as('engagement.alert')
            ->with($payload)
            ->send();
    }
}
