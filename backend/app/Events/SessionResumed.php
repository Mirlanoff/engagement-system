<?php

namespace App\Events;

use App\Models\LessonSession;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

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


cat > backend/app/Events/EngagementUpdated.php << 'EOF'
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
