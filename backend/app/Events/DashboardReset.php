<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DashboardReset implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param array<int, string> $sessionIds id затронутых уроков
     */
    public function __construct(
        public array $sessionIds,
        public bool  $keepCompleted = false,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('dashboard')];
    }

    public function broadcastAs(): string
    {
        return 'dashboard.reset';
    }

    public function broadcastWith(): array
    {
        return [
            'session_ids'    => $this->sessionIds,
            'keep_completed' => $this->keepCompleted,
            'timestamp'      => now()->toIso8601String(),
        ];
    }
}
