<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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
