<?php

namespace App\Events;

use App\Models\LessonSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SessionStarted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public LessonSession $session) {}

    public function broadcastOn(): array
    {
        return [new PrivateChannel("school.{$this->session->classroom->school_id}")];
    }

    public function broadcastAs(): string
    {
        return 'session.started';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id'     => $this->session->id,
            'id'             => $this->session->id,
            'classroom_id'   => $this->session->classroom_id,
            'classroom_name' => $this->session->classroom?->name,
            'subject'        => $this->session->subject,
            'teacher'        => $this->session->teacher?->name,
            'started_at'     => $this->session->started_at?->toIso8601String(),
            'students_count' => $this->session->students_count,
        ];
    }
}
