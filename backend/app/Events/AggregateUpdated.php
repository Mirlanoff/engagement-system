<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AggregateUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $aggregate,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('analytics')];
    }

    public function broadcastAs(): string
    {
        return 'aggregate.updated';
    }

    public function broadcastWith(): array
    {
        return $this->aggregate;
    }
}
