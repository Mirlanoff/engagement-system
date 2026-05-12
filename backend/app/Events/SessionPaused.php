<?php

namespace App\Events;

use App\Models\LessonSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
