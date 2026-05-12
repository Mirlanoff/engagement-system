<?php

namespace App\Domain\Engagement\Services;

use App\Domain\Session\Models\LessonSession;
use App\Domain\Engagement\Models\EngagementSnapshot;
use App\Domain\Engagement\Models\EngagementAggregate;
use App\Events\AggregateUpdated;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class EngagementAggregatorService
{
    // ── Агрегат по минуте (вызывается при каждом батче снэпшотов) ─

    public function updateMinuteAggregate(LessonSession $session, array $snapshots): void
    {
        $minuteAt = Carbon::parse($snapshots[0]['captured_at'])->startOfMinute();
        $scores   = array_column($snapshots, 'engagement_score');

        $aggregate = EngagementAggregate::updateOrCreate(
            [
                'session_id'       => $session->id,
                'minute_at'        => $minuteAt,
                'interval_minutes' => 1,
            ],
            [
                'classroom_id'           => $session->classroom_id,
                'avg_score'              => round(array_sum($scores) / count($scores), 2),
                'min_score'              => min($scores),
                'max_score'              => max($scores),
                'std_dev'                => $this->stdDev($scores),
                'students_detected'      => count($scores),
                'snapshots_count'        => count($scores),
                'high_engagement_count'  => count(array_filter($scores, fn($s) => $s >= 75)),
                'medium_engagement_count'=> count(array_filter($scores, fn($s) => $s >= 50 && $s < 75)),
                'low_engagement_count'   => count(array_filter($scores, fn($s) => $s < 50)),
            ]
        );

        try {
            broadcast(new AggregateUpdated([
                'session_id'        => $aggregate->session_id,
                'classroom_id'      => $aggregate->classroom_id,
                'minute_at'         => $aggregate->minute_at?->toIso8601String(),
                'avg_score'         => (float) $aggregate->avg_score,
                'min_score'         => (float) $aggregate->min_score,
                'max_score'         => (float) $aggregate->max_score,
                'students_detected' => (int) $aggregate->students_detected,
                'snapshots_count'   => (int) $aggregate->snapshots_count,
            ]));
        } catch (\Throwable $e) {
            Log::warning('AggregateUpdated broadcast failed', ['error' => $e->getMessage()]);
        }
    }

    // ── Итоговая статистика урока ────────────────────────────────

    public function calculateSessionStats(LessonSession $session): array
    {
        $stats = EngagementSnapshot::forSession($session->id)
            ->selectRaw('
                AVG(engagement_score) as avg,
                MIN(engagement_score) as min,
                MAX(engagement_score) as max,
                COUNT(*) as total_snapshots,
                STDDEV(engagement_score) as std_dev
            ')
            ->first();

        $timeline = $this->buildTimeline($session);

        return [
            'avg'             => round($stats->avg ?? 0, 2),
            'min'             => round($stats->min ?? 0, 2),
            'max'             => round($stats->max ?? 0, 2),
            'std_dev'         => round($stats->std_dev ?? 0, 2),
            'total_snapshots' => $stats->total_snapshots ?? 0,
            'timeline'        => $timeline,
        ];
    }

    // ── Timeline для графика урока (точки по минутам) ───────────

    public function buildTimeline(LessonSession $session): array
    {
        return EngagementAggregate::where('session_id', $session->id)
            ->orderBy('minute_at')
            ->get()
            ->map(fn ($a) => [
                'minute'    => $a->minute_at->diffInMinutes($session->started_at),
                'avg_score' => (float) $a->avg_score,
                'min_score' => (float) $a->min_score,
                'max_score' => (float) $a->max_score,
                'students'  => $a->students_detected,
                'high'      => $a->high_engagement_count,
                'medium'    => $a->medium_engagement_count,
                'low'       => $a->low_engagement_count,
            ])
            ->toArray();
    }

    // ── Аналитика по студенту за период ─────────────────────────

    public function getStudentStats(string $studentId, Carbon $from, Carbon $to): array
    {
        $raw = EngagementSnapshot::forStudent($studentId)
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('
                AVG(engagement_score)   as avg_score,
                AVG(gaze_score)         as avg_gaze,
                AVG(emotion_score)      as avg_emotion,
                AVG(head_pose_score)    as avg_head_pose,
                COUNT(*)                as total_snapshots,
                SUM(CASE WHEN engagement_score < 50 THEN 1 ELSE 0 END) as low_count,
                SUM(CASE WHEN engagement_score >= 75 THEN 1 ELSE 0 END) as high_count,
                MODE() WITHIN GROUP (ORDER BY emotion) as dominant_emotion
            ')
            ->first();

        $trend = $this->getStudentTrend($studentId, $from, $to);

        return [
            'avg_score'       => round($raw->avg_score ?? 0, 2),
            'avg_gaze'        => round($raw->avg_gaze ?? 0, 2),
            'avg_emotion'     => round($raw->avg_emotion ?? 0, 2),
            'avg_head_pose'   => round($raw->avg_head_pose ?? 0, 2),
            'total_snapshots' => $raw->total_snapshots ?? 0,
            'low_count'       => $raw->low_count ?? 0,
            'high_count'      => $raw->high_count ?? 0,
            'dominant_emotion'=> $raw->dominant_emotion ?? 'neutral',
            'trend'           => $trend, // 'improving' | 'declining' | 'stable'
            'trend_delta'     => $this->getTrendDelta($studentId, $from, $to),
        ];
    }

    // ── Сравнение классов ────────────────────────────────────────

    public function compareClassrooms(array $classroomIds, Carbon $from, Carbon $to): array
    {
        return DB::table('engagement_aggregates as ea')
            ->join('lesson_sessions as ls', 'ls.id', '=', 'ea.session_id')
            ->join('classrooms as c', 'c.id', '=', 'ea.classroom_id')
            ->whereIn('ea.classroom_id', $classroomIds)
            ->whereBetween('ea.minute_at', [$from, $to])
            ->groupBy('ea.classroom_id', 'c.name')
            ->selectRaw('
                ea.classroom_id,
                c.name as classroom_name,
                AVG(ea.avg_score)              as avg_score,
                MIN(ea.min_score)              as min_score,
                MAX(ea.max_score)              as max_score,
                SUM(ea.high_engagement_count)  as high_total,
                SUM(ea.medium_engagement_count)as medium_total,
                SUM(ea.low_engagement_count)   as low_total,
                COUNT(DISTINCT ea.session_id)  as sessions_count
            ')
            ->orderByDesc('avg_score')
            ->get()
            ->map(fn ($r) => [
                'classroom_id'   => $r->classroom_id,
                'classroom_name' => $r->classroom_name,
                'avg_score'      => round($r->avg_score, 2),
                'min_score'      => round($r->min_score, 2),
                'max_score'      => round($r->max_score, 2),
                'sessions_count' => $r->sessions_count,
                'distribution'   => [
                    'high'   => $r->high_total,
                    'medium' => $r->medium_total,
                    'low'    => $r->low_total,
                ],
            ])
            ->toArray();
    }

    // ── Heatmap по часам дня × дням недели ──────────────────────

    public function getEngagementHeatmap(string $classroomId, Carbon $from, Carbon $to): array
    {
        return DB::table('engagement_aggregates as ea')
            ->join('lesson_sessions as ls', 'ls.id', '=', 'ea.session_id')
            ->where('ea.classroom_id', $classroomId)
            ->whereBetween('ea.minute_at', [$from, $to])
            ->selectRaw('
                EXTRACT(DOW FROM ea.minute_at)  as day_of_week,
                EXTRACT(HOUR FROM ea.minute_at) as hour_of_day,
                AVG(ea.avg_score)               as avg_score,
                COUNT(*)                        as data_points
            ')
            ->groupByRaw('day_of_week, hour_of_day')
            ->orderByRaw('day_of_week, hour_of_day')
            ->get()
            ->map(fn ($r) => [
                'day'    => (int) $r->day_of_week,
                'hour'   => (int) $r->hour_of_day,
                'score'  => round($r->avg_score, 2),
                'points' => $r->data_points,
            ])
            ->toArray();
    }

    // ── Private helpers ─────────────────────────────────────────

    private function stdDev(array $values): float
    {
        $n = count($values);
        if ($n < 2) return 0.0;
        $mean = array_sum($values) / $n;
        $variance = array_sum(array_map(fn($v) => ($v - $mean) ** 2, $values)) / ($n - 1);
        return round(sqrt($variance), 2);
    }

    private function getStudentTrend(string $studentId, Carbon $from, Carbon $to): string
    {
        $midpoint = $from->copy()->addSeconds($from->diffInSeconds($to) / 2);

        $firstHalf = EngagementSnapshot::forStudent($studentId)
            ->whereBetween('captured_at', [$from, $midpoint])
            ->avg('engagement_score') ?? 0;

        $secondHalf = EngagementSnapshot::forStudent($studentId)
            ->whereBetween('captured_at', [$midpoint, $to])
            ->avg('engagement_score') ?? 0;

        $delta = $secondHalf - $firstHalf;

        return match(true) {
            $delta > 5  => 'improving',
            $delta < -5 => 'declining',
            default     => 'stable',
        };
    }

    private function getTrendDelta(string $studentId, Carbon $from, Carbon $to): float
    {
        $midpoint = $from->copy()->addSeconds($from->diffInSeconds($to) / 2);
        $first  = EngagementSnapshot::forStudent($studentId)->whereBetween('captured_at', [$from, $midpoint])->avg('engagement_score') ?? 0;
        $second = EngagementSnapshot::forStudent($studentId)->whereBetween('captured_at', [$midpoint, $to])->avg('engagement_score') ?? 0;
        return round($second - $first, 2);
    }
}
