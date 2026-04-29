<?php

namespace App\Events;

use App\Models\LessonSession;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ── Session Started ──────────────────────────────────────────────
class SessionStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LessonSession $session) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("school.{$this->session->classroom->school_id}"),
        ];
    }

    public function broadcastAs(): string { return 'session.started'; }

    public function broadcastWith(): array
    {
        return [
            'session_id'     => $this->session->id,
            'classroom_id'   => $this->session->classroom_id,
            'classroom_name' => $this->session->classroom?->name,
            'subject'        => $this->session->subject,
            'teacher'        => $this->session->teacher?->name,
            'started_at'     => $this->session->started_at?->toIso8601String(),
            'students_count' => $this->session->students_count,
        ];
    }
}

// ── Session Ended ────────────────────────────────────────────────
class SessionEnded implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LessonSession $session) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("session.{$this->session->id}"),
            new PrivateChannel("school.{$this->session->classroom->school_id}"),
        ];
    }

    public function broadcastAs(): string { return 'session.ended'; }

    public function broadcastWith(): array
    {
        return [
            'session_id'        => $this->session->id,
            'avg_score'         => $this->session->avg_engagement_score,
            'duration_minutes'  => $this->session->duration_minutes,
            'ended_at'          => $this->session->ended_at?->toIso8601String(),
        ];
    }
}

// ── Session Paused ───────────────────────────────────────────────
class SessionPaused implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LessonSession $session) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("session.{$this->session->id}")];
    }

    public function broadcastAs(): string { return 'session.paused'; }

    public function broadcastWith(): array
    {
        return ['session_id' => $this->session->id];
    }
}

// ── Session Resumed ──────────────────────────────────────────────
class SessionResumed implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LessonSession $session) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("session.{$this->session->id}")];
    }

    public function broadcastAs(): string { return 'session.resumed'; }

    public function broadcastWith(): array
    {
        return ['session_id' => $this->session->id];
    }
}

// ── Engagement Updated (realtime scores) ────────────────────────
class EngagementUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public array  $payload,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("session.{$this->sessionId}")];
    }

    public function broadcastAs(): string { return 'engagement.update'; }

    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
