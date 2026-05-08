<?php

namespace App\Events;

use App\Models\EngagementAlert;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class EngagementAlertTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public EngagementAlert $alert)
    {
        $this->alert->loadMissing(['classroom', 'student']);
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("school.{$this->alert->classroom->school_id}"),
            new PresenceChannel("session.{$this->alert->session_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'engagement.alert';
    }

    public function broadcastWith(): array
    {
        return $this->alert->toDashboardPayload();
    }
}
