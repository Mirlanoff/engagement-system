<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\EngagementSnapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * GET /api/v1/analytics/overview
     * Общий обзор школы за период
     */
    public function overview(Request $request): JsonResponse
    {
        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date|after_or_equal:from',
        ]);

        $from = Carbon::parse($request->from ?? now()->subDays(7))->startOfDay();
        $to   = Carbon::parse($request->to ?? now())->endOfDay();

        $classroomIds = Classroom::query()
            ->where('school_id', $request->user()->school_id)
            ->where('is_active', true)
            ->pluck('id')
            ->all();

        $comparison = $this->classroomComparison($classroomIds, $from, $to);

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
                'best_classroom'   => collect($comparison)->sortByDesc('avg_score')->first(),
                'worst_classroom'  => collect($comparison)->sortBy('avg_score')->first(),
            ],
        ]);
    }

    /**
     * GET /api/v1/analytics/heatmap/{classroomId}
     * Тепловая карта вовлечённости: день × час
     */
    public function heatmap(Request $request, string $classroomId): JsonResponse
    {
        $classroom = Classroom::where('school_id', $request->user()->school_id)->find($classroomId);
        if (!$classroom) {
            return response()->json(['message' => 'Класс не найден'], 404);
        }

        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $from = Carbon::parse($request->from ?? now()->subDays(7))->startOfDay();
        $to   = Carbon::parse($request->to ?? now())->endOfDay();

        $data = \DB::table('engagement_aggregates')
            ->where('classroom_id', $classroomId)
            ->whereBetween('minute_at', [$from, $to])
            ->selectRaw('EXTRACT(DOW FROM minute_at) as day, EXTRACT(HOUR FROM minute_at) as hour, ROUND(AVG(avg_score)::numeric, 2) as avg_score')
            ->groupByRaw('EXTRACT(DOW FROM minute_at), EXTRACT(HOUR FROM minute_at)')
            ->orderBy('day')
            ->orderBy('hour')
            ->get();

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
        $studentExists = \DB::table('students')
            ->where('id', $studentId)
            ->where('school_id', $request->user()->school_id)
            ->exists();
        if (!$studentExists) {
            return response()->json(['message' => 'Студент не найден'], 404);
        }

        $request->validate([
            'from' => 'nullable|date',
            'to'   => 'nullable|date',
        ]);

        $from  = Carbon::parse($request->from ?? now()->subDays(7))->startOfDay();
        $to    = Carbon::parse($request->to ?? now())->endOfDay();
        $stats = $this->studentStats($studentId, $from, $to);

        // История по урокам
        $sessions = EngagementSnapshot::forStudent($studentId)
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
            'classroom_ids'   => 'nullable|array|max:10',
            'classroom_ids.*' => 'uuid',
            'from'            => 'nullable|date',
            'to'              => 'nullable|date',
        ]);

        $from = Carbon::parse($request->from ?? now()->subDays(7))->startOfDay();
        $to   = Carbon::parse($request->to ?? now())->endOfDay();
        $schoolClassroomIds = Classroom::where('school_id', $request->user()->school_id)->pluck('id')->all();
        $classroomIds = $request->classroom_ids
            ? array_values(array_intersect($request->classroom_ids, $schoolClassroomIds))
            : $schoolClassroomIds;

        $data = $this->classroomComparison($classroomIds, $from, $to);

        return response()->json(['data' => $data]);
    }

    private function classroomComparison(array $classroomIds, Carbon $from, Carbon $to): array
    {
        return Classroom::whereIn('id', $classroomIds)
            ->get()
            ->map(function (Classroom $classroom) use ($from, $to) {
                $stats = \DB::table('engagement_aggregates')
                    ->where('classroom_id', $classroom->id)
                    ->whereBetween('minute_at', [$from, $to])
                    ->selectRaw('ROUND(AVG(avg_score)::numeric, 2) as avg_score, COUNT(*) as points')
                    ->first();

                return [
                    'classroom_id' => $classroom->id,
                    'classroom_name' => $classroom->name,
                    'avg_score' => (float) ($stats?->avg_score ?? 0),
                    'points' => (int) ($stats?->points ?? 0),
                ];
            })
            ->sortByDesc('avg_score')
            ->values()
            ->all();
    }

    private function studentStats(string $studentId, Carbon $from, Carbon $to): array
    {
        $raw = EngagementSnapshot::forStudent($studentId)
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw('
                ROUND(AVG(engagement_score)::numeric, 2) as avg_score,
                ROUND(AVG(gaze_score)::numeric, 2) as avg_gaze,
                ROUND(AVG(emotion_score)::numeric, 2) as avg_emotion,
                ROUND(AVG(head_pose_score)::numeric, 2) as avg_head_pose,
                COUNT(*) as total_snapshots,
                SUM(CASE WHEN engagement_score < 50 THEN 1 ELSE 0 END) as low_count,
                SUM(CASE WHEN engagement_score >= 75 THEN 1 ELSE 0 END) as high_count
            ')
            ->first();

        return [
            'avg_score' => (float) ($raw?->avg_score ?? 0),
            'avg_gaze' => (float) ($raw?->avg_gaze ?? 0),
            'avg_emotion' => (float) ($raw?->avg_emotion ?? 0),
            'avg_head_pose' => (float) ($raw?->avg_head_pose ?? 0),
            'total_snapshots' => (int) ($raw?->total_snapshots ?? 0),
            'low_count' => (int) ($raw?->low_count ?? 0),
            'high_count' => (int) ($raw?->high_count ?? 0),
        ];
    }
}
