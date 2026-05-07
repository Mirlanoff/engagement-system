<?php

namespace App\Events;

use App\Models\LessonSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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

    public function broadcastAs(): string
    {
        return 'session.ended';
    }

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
