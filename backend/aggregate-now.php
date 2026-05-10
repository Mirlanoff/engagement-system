<?php

use App\Domain\Engagement\Models\EngagementSnapshot;
use App\Domain\Engagement\Services\EngagementAggregatorService;
use App\Domain\Session\Models\LessonSession;

$svc = app(EngagementAggregatorService::class);
$ids = DB::table('engagement_snapshots')->distinct()->pluck('session_id');

echo "Found " . $ids->count() . " sessions with snapshots
";

foreach ($ids as $sid) {
    $session = LessonSession::find($sid);
    if (!$session) { echo "  skip {$sid} (no session)
"; continue; }

    $snaps = EngagementSnapshot::where('session_id', $sid)
        ->orderBy('captured_at')
        ->get();

    $grouped = $snaps->groupBy(fn($s) => $s->captured_at->format('Y-m-d H:i:00'));

    foreach ($grouped as $minute => $group) {
        try {
            $arr = $group->map(fn($s) => $s->toArray())->all();
            $svc->updateMinuteAggregate($session, $arr);
        } catch (Throwable $e) {
            echo "  ERROR on {$minute}: " . $e->getMessage() . "
";
        }
    }

    echo "  Session {$sid}: {$grouped->count()} min(s), {$snaps->count()} snapshots
";
}

echo "Done.
";
