<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LessonSession;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика школы.
 *
 * Намеренно не зависит от устаревшего EngagementAggregatorService — берём
 * данные напрямую из таблиц engagement_snapshots / lesson_sessions, чтобы
 * дашборд работал даже без CV-сервиса (см. fallback-снэпшоты в
 * SessionController::ingestFrame).
 */
class AnalyticsController extends Controller
{
    // GET /api/v1/analytics/overview?period=today|week|month
    public function overview(Request $request): JsonResponse
    {
        [$from, $to, $period] = $this->resolvePeriod($request);
        $schoolId = $request->user()?->school_id;

        $sessionsQuery = LessonSession::query()
            ->when($schoolId, function ($q) use ($schoolId) {
                $q->whereHas('classroom', fn ($c) => $c->where('school_id', $schoolId));
            })
            ->whereBetween('started_at', [$from, $to]);

        $totals = (clone $sessionsQuery)
            ->selectRaw("
                COUNT(*)                                                  as total_sessions,
                COUNT(*) FILTER (WHERE status = 'active')                 as active_sessions,
                COUNT(*) FILTER (WHERE status = 'completed')              as completed_sessions,
                COALESCE(SUM(students_count), 0)                          as students_total,
                COALESCE(SUM(total_snapshots), 0)                         as snapshots_total
            ")
            ->first();

        $sessionIds = (clone $sessionsQuery)->pluck('id');

        $snapshotStats = DB::table('engagement_snapshots')
            ->whereIn('session_id', $sessionIds)
            ->selectRaw("
                ROUND(AVG(engagement_score)::numeric, 2)                  as avg_score,
                ROUND(MIN(engagement_score)::numeric, 2)                  as min_score,
                ROUND(MAX(engagement_score)::numeric, 2)                  as max_score,
                COUNT(*)                                                  as snapshots,
                COUNT(*) FILTER (WHERE engagement_score >= 75)            as high,
                COUNT(*) FILTER (WHERE engagement_score >= 50 AND engagement_score < 75) as medium,
                COUNT(*) FILTER (WHERE engagement_score < 50)             as low
            ")
            ->first();

        return response()->json([
            'period' => [
                'name' => $period,
                'from' => $from->toIso8601String(),
                'to'   => $to->toIso8601String(),
            ],
            'summary' => [
                'total_sessions'     => (int) ($totals->total_sessions ?? 0),
                'active_sessions'    => (int) ($totals->active_sessions ?? 0),
                'completed_sessions' => (int) ($totals->completed_sessions ?? 0),
                'students_total'     => (int) ($totals->students_total ?? 0),
                'snapshots_total'    => (int) ($snapshotStats->snapshots ?? $totals->snapshots_total ?? 0),
                'avg_score'          => (float) ($snapshotStats->avg_score ?? 0),
                'min_score'          => (float) ($snapshotStats->min_score ?? 0),
                'max_score'          => (float) ($snapshotStats->max_score ?? 0),
            ],
            'distribution' => [
                'high'   => (int) ($snapshotStats->high ?? 0),
                'medium' => (int) ($snapshotStats->medium ?? 0),
                'low'    => (int) ($snapshotStats->low ?? 0),
            ],
            'time_series'  => $this->buildTimeSeries($sessionIds, $from, $to, $period),
            'classrooms'   => $this->buildClassroomBreakdown($sessionIds, $schoolId),
            'top_sessions' => $this->buildTopSessions($sessionsQuery),
        ]);
    }

    // GET /api/v1/analytics/heatmap?days=14
    public function heatmap(Request $request): JsonResponse
    {
        $days     = max(1, min(60, (int) $request->input('days', 14)));
        $from     = now()->subDays($days)->startOfDay();
        $to       = now()->endOfDay();
        $schoolId = $request->user()?->school_id;

        $rows = DB::table('engagement_snapshots as es')
            ->join('lesson_sessions as ls', 'ls.id', '=', 'es.session_id')
            ->join('classrooms as c', 'c.id', '=', 'es.classroom_id')
            ->when($schoolId, fn ($q) => $q->where('c.school_id', $schoolId))
            ->whereBetween('es.captured_at', [$from, $to])
            ->selectRaw("
                EXTRACT(DOW  FROM es.captured_at)::int       as day_of_week,
                EXTRACT(HOUR FROM es.captured_at)::int       as hour_of_day,
                ROUND(AVG(es.engagement_score)::numeric, 2)  as avg_score,
                COUNT(*)                                     as snapshots
            ")
            ->groupByRaw('EXTRACT(DOW FROM es.captured_at), EXTRACT(HOUR FROM es.captured_at)')
            ->orderByRaw('day_of_week, hour_of_day')
            ->get()
            ->map(fn ($r) => [
                'day'       => (int) $r->day_of_week,
                'hour'      => (int) $r->hour_of_day,
                'avg_score' => (float) $r->avg_score,
                'snapshots' => (int) $r->snapshots,
            ]);

        return response()->json([
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'days'   => ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
            'data'   => $rows,
        ]);
    }

    // ── Helpers ──────────────────────────────────────────────────

    private function resolvePeriod(Request $request): array
    {
        $period = $request->input('period', 'today');

        return match ($period) {
            'week'  => [now()->subDays(6)->startOfDay(),  now()->endOfDay(), 'week'],
            'month' => [now()->subDays(29)->startOfDay(), now()->endOfDay(), 'month'],
            default => [now()->startOfDay(),              now()->endOfDay(), 'today'],
        };
    }

    private function buildTimeSeries($sessionIds, Carbon $from, Carbon $to, string $period): array
    {
        // Для today берём по часам, для week/month — по дням
        $bucket = $period === 'today'
            ? "DATE_TRUNC('hour', captured_at)"
            : "DATE_TRUNC('day',  captured_at)";

        $rows = DB::table('engagement_snapshots')
            ->whereIn('session_id', $sessionIds)
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw("$bucket as bucket, ROUND(AVG(engagement_score)::numeric, 2) as avg_score, COUNT(*) as samples")
            ->groupByRaw($bucket)
            ->orderByRaw($bucket)
            ->get();

        return $rows->map(fn ($r) => [
            'at'        => Carbon::parse($r->bucket)->toIso8601String(),
            'avg_score' => (float) $r->avg_score,
            'samples'   => (int) $r->samples,
        ])->toArray();
    }

    private function buildClassroomBreakdown($sessionIds, ?string $schoolId): array
    {
        if ($sessionIds->isEmpty()) {
            return [];
        }

        return DB::table('engagement_snapshots as es')
            ->join('classrooms as c', 'c.id', '=', 'es.classroom_id')
            ->when($schoolId, fn ($q) => $q->where('c.school_id', $schoolId))
            ->whereIn('es.session_id', $sessionIds)
            ->groupBy('es.classroom_id', 'c.name')
            ->selectRaw("
                es.classroom_id,
                c.name                                      as classroom_name,
                ROUND(AVG(es.engagement_score)::numeric, 2) as avg_score,
                COUNT(DISTINCT es.session_id)               as sessions,
                COUNT(*)                                    as snapshots,
                COUNT(*) FILTER (WHERE es.engagement_score >= 75) as high,
                COUNT(*) FILTER (WHERE es.engagement_score < 50)  as low
            ")
            ->orderByDesc('avg_score')
            ->get()
            ->map(fn ($r) => [
                'classroom_id'   => $r->classroom_id,
                'classroom_name' => $r->classroom_name,
                'avg_score'      => (float) $r->avg_score,
                'sessions'       => (int) $r->sessions,
                'snapshots'      => (int) $r->snapshots,
                'high'           => (int) $r->high,
                'low'            => (int) $r->low,
            ])
            ->toArray();
    }

    private function buildTopSessions($baseQuery): array
    {
        return (clone $baseQuery)
            ->with(['classroom', 'teacher'])
            ->whereNotNull('avg_engagement_score')
            ->orderByDesc('avg_engagement_score')
            ->limit(5)
            ->get()
            ->map(fn (LessonSession $s) => [
                'id'             => $s->id,
                'classroom_name' => $s->classroom?->name,
                'subject'        => $s->subject,
                'started_at'     => $s->started_at?->toIso8601String(),
                'ended_at'       => $s->ended_at?->toIso8601String(),
                'avg_score'      => (float) ($s->avg_engagement_score ?? 0),
                'students_count' => (int) ($s->students_count ?? 0),
                'status'         => $s->status,
            ])
            ->toArray();
    }
}
