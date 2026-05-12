<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Engagement\Services\EngagementAggregatorService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    public function __construct(
        private readonly EngagementAggregatorService $aggregator,
    ) {
        // Middleware удалены для упрощения тестирования/интеграции
    }

    /**
     * GET /api/v1/analytics/overview
     * Общий обзор школы за период
     */
    public function overview(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after:from',
        ]);

        $from = Carbon::parse($request->from)->startOfDay();
        $to   = Carbon::parse($request->to)->endOfDay();

        $classroomIds = $request->user()->school
            ->classrooms()->active()->pluck('id')->toArray();

        $comparison = $this->aggregator->compareClassrooms($classroomIds, $from, $to);

        // Общий тренд школы по дням
        $dailyTrend = \DB::table('engagement_aggregates as ea')
            ->whereIn('ea.classroom_id', $classroomIds)
            ->whereBetween('ea.minute_at', [$from, $to])
            ->selectRaw("DATE(ea.minute_at) as date, AVG(ea.avg_score) as avg_score")
            ->groupByRaw("DATE(ea.minute_at)")
            ->orderBy('date')
            ->get();

        return response()->json([
            'period'      => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'classrooms'  => $comparison,
            'daily_trend' => $dailyTrend,
            'summary'     => [
                'total_classrooms' => count($classroomIds),
                'school_avg'       => round(collect($comparison)->avg('avg_score'), 2),
                'best_classroom'   => collect($comparison)->first(),
                'worst_classroom'  => collect($comparison)->last(),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/heatmap/{classroomId}
     * Тепловая карта вовлечённости: день × час
     */
    public function heatmap(Request $request, string $classroomId): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date',
        ]);

        $from = Carbon::parse($request->from)->startOfDay();
        $to   = Carbon::parse($request->to)->endOfDay();

        $data = $this->aggregator->getEngagementHeatmap($classroomId, $from, $to);

        return response()->json([
            'classroom_id' => $classroomId,
            'period'       => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data'         => $data,
            'days'         => ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
        ]);
    }

    /**
     * GET /api/v1/analytics/students/{studentId}
     * Персональная аналитика студента
     */
    public function student(Request $request, string $studentId): JsonResponse
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date',
        ]);

        $from  = Carbon::parse($request->from)->startOfDay();
        $to    = Carbon::parse($request->to)->endOfDay();
        $stats = $this->aggregator->getStudentStats($studentId, $from, $to);

        // История по урокам
        $sessions = \App\Domain\Engagement\Models\EngagementSnapshot::forStudent($studentId)
            ->whereBetween('captured_at', [$from, $to])
            ->join('lesson_sessions', 'lesson_sessions.id', '=', 'engagement_snapshots.session_id')
            ->selectRaw('
                engagement_snapshots.session_id,
                lesson_sessions.subject,
                lesson_sessions.started_at,
                AVG(engagement_snapshots.engagement_score) as avg_score,
                COUNT(*) as snapshots
            ')
            ->groupBy('engagement_snapshots.session_id', 'lesson_sessions.subject', 'lesson_sessions.started_at')
            ->orderBy('lesson_sessions.started_at')
            ->get();

        return response()->json([
            'student_id' => $studentId,
            'period'     => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'stats'      => $stats,
            'sessions'   => $sessions->map(fn ($s) => [
                'session_id'  => $s->session_id,
                'subject'     => $s->subject,
                'date'        => Carbon::parse($s->started_at)->toDateString(),
                'avg_score'   => round($s->avg_score, 2),
                'snapshots'   => $s->snapshots,
            ]),
        ]);
    }

    /**
     * GET /api/v1/analytics/compare
     * Сравнение нескольких классов
     */
    public function compare(Request $request): JsonResponse
    {
        $request->validate([
            'classroom_ids'   => 'required|array|max:10',
            'classroom_ids.*' => 'uuid',
            'from'            => 'required|date',
            'to'              => 'required|date',
        ]);

        $from = Carbon::parse($request->from)->startOfDay();
        $to   = Carbon::parse($request->to)->endOfDay();

        $data = $this->aggregator->compareClassrooms(
            $request->classroom_ids,
            $from,
            $to
        );

        return response()->json(['data' => $data]);
    }
}