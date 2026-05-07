<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CameraErrorEvent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $sessionId,
        public string $cameraId,
        public string $error,
    ) {}

    public function broadcastOn(): array
    {
        return [new PresenceChannel("session.{$this->sessionId}")];
    }

    public function broadcastAs(): string
    {
        return 'camera.error';
    }

    public function broadcastWith(): array
    {
        return [
            'session_id' => $this->sessionId,
            'camera_id'  => $this->cameraId,
            'error'      => $this->error,
            'timestamp'  => now()->toIso8601String(),
        ];
    }
}
