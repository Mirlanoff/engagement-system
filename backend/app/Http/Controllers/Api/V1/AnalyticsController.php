<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Recommendation\Models\AiRecommendation;
use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Аналитика для ролей supervisor и admin.
 *
 * Все эндпоинты подмонтированы в routes/api.php под middleware
 * `auth:sanctum` + `role:admin,supervisor`.
 */
class AnalyticsController extends Controller
{
    // ── 1. Heatmap (день × час) ─────────────────────────────────

    /**
     * GET /api/v1/analytics/heatmap?classroom_id=&from=&to=
     */
    public function heatmap(Request $request): JsonResponse
    {
        $request->validate([
            'classroom_id' => 'required|uuid',
            'from'         => 'required|date',
            'to'           => 'required|date|after_or_equal:from',
        ]);

        [$from, $to] = $this->parseRange($request);

        $rows = DB::table('engagement_snapshots')
            ->where('classroom_id', $request->classroom_id)
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw("EXTRACT(DOW  FROM captured_at)::int as dow")
            ->selectRaw("EXTRACT(HOUR FROM captured_at)::int as hour")
            ->selectRaw('AVG(engagement_score) as avg_score')
            ->selectRaw('COUNT(*) as samples')
            ->groupByRaw('1, 2')
            ->orderByRaw('1, 2')
            ->get();

        $cells = $rows->map(fn ($r) => [
            'dow'       => (int) $r->dow,
            'hour'      => (int) $r->hour,
            'avg_score' => round((float) $r->avg_score, 1),
            'samples'   => (int) $r->samples,
        ]);

        return response()->json([
            'classroom_id' => $request->classroom_id,
            'period'       => $this->periodPayload($from, $to),
            'cells'        => $cells,
            'days'         => ['Вс', 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб'],
        ]);
    }

    // ── 2. Сравнение классов ─────────────────────────────────────

    /**
     * GET /api/v1/analytics/comparison?classroom_ids[]=...&from=&to=
     */
    public function comparison(Request $request): JsonResponse
    {
        $request->validate([
            'classroom_ids'   => 'required|array|max:20',
            'classroom_ids.*' => 'uuid',
            'from'            => 'required|date',
            'to'              => 'required|date|after_or_equal:from',
        ]);

        [$from, $to] = $this->parseRange($request);

        $rows = DB::table('engagement_snapshots as es')
            ->join('classrooms as c', 'c.id', '=', 'es.classroom_id')
            ->whereIn('es.classroom_id', $request->classroom_ids)
            ->whereBetween('es.captured_at', [$from, $to])
            ->select('es.classroom_id', 'c.name as classroom_name')
            ->selectRaw('AVG(es.engagement_score) as avg_score')
            ->selectRaw('MIN(es.engagement_score) as min_score')
            ->selectRaw('MAX(es.engagement_score) as max_score')
            ->selectRaw('COUNT(*) as snapshots')
            ->selectRaw('COUNT(DISTINCT es.session_id) as sessions')
            ->groupBy('es.classroom_id', 'c.name')
            ->orderByDesc('avg_score')
            ->get();

        return response()->json([
            'period'     => $this->periodPayload($from, $to),
            'classrooms' => $rows->map(fn ($r) => [
                'classroom_id'   => $r->classroom_id,
                'classroom_name' => $r->classroom_name,
                'avg_score'      => round((float) $r->avg_score, 1),
                'min_score'      => round((float) $r->min_score, 1),
                'max_score'      => round((float) $r->max_score, 1),
                'snapshots'      => (int) $r->snapshots,
                'sessions'       => (int) $r->sessions,
            ]),
        ]);
    }

    // ── 3. Тренды по студенту ───────────────────────────────────

    /**
     * GET /api/v1/analytics/student-trends?student_id=&from=&to=
     */
    public function studentTrends(Request $request): JsonResponse
    {
        $request->validate([
            'student_id' => 'required|uuid',
            'from'       => 'required|date',
            'to'         => 'required|date|after_or_equal:from',
        ]);

        [$from, $to] = $this->parseRange($request);

        $perDay = DB::table('engagement_snapshots')
            ->where('student_id', $request->student_id)
            ->whereBetween('captured_at', [$from, $to])
            ->selectRaw("DATE(captured_at) as date")
            ->selectRaw('AVG(engagement_score) as avg_score')
            ->selectRaw('COUNT(*) as samples')
            ->groupByRaw('1')
            ->orderByRaw('1')
            ->get()
            ->map(fn ($r) => [
                'date'      => (string) $r->date,
                'avg_score' => round((float) $r->avg_score, 1),
                'samples'   => (int) $r->samples,
            ]);

        $sessions = DB::table('engagement_snapshots as es')
            ->join('lesson_sessions as ls', 'ls.id', '=', 'es.session_id')
            ->where('es.student_id', $request->student_id)
            ->whereBetween('es.captured_at', [$from, $to])
            ->selectRaw('es.session_id, ls.subject, ls.started_at, AVG(es.engagement_score) as avg_score')
            ->groupBy('es.session_id', 'ls.subject', 'ls.started_at')
            ->orderBy('ls.started_at')
            ->get()
            ->map(fn ($r) => [
                'session_id' => $r->session_id,
                'subject'    => $r->subject,
                'date'       => Carbon::parse($r->started_at)->toDateString(),
                'avg_score'  => round((float) $r->avg_score, 1),
            ]);

        return response()->json([
            'student_id' => $request->student_id,
            'period'     => $this->periodPayload($from, $to),
            'per_day'    => $perDay,
            'sessions'   => $sessions,
        ]);
    }

    // ── 4. Breakdown одного снэпшота ────────────────────────────

    /**
     * GET /api/v1/analytics/snapshots/{id}/breakdown
     *
     * Расшифровывает score_breakdown, frame_quality, attention_state и
     * причину not_detected_reason для конкретного снэпшота — это и есть
     * "почему именно такая цифра" для отдельного момента.
     */
    public function snapshotBreakdown(string $id): JsonResponse
    {
        $snapshot = DB::table('engagement_snapshots')
            ->where('id', $id)
            ->first();

        if (!$snapshot) {
            return response()->json(['message' => 'Snapshot not found'], 404);
        }

        $breakdown = $this->decodeJsonField($snapshot->score_breakdown ?? null);
        $quality   = $this->decodeJsonField($snapshot->frame_quality ?? null);

        return response()->json([
            'snapshot_id'         => $snapshot->id,
            'session_id'          => $snapshot->session_id,
            'student_id'          => $snapshot->student_id,
            'captured_at'         => $snapshot->captured_at,
            'engagement_score'    => $this->numOrNull($snapshot->engagement_score ?? null),
            'attention_state'     => $snapshot->attention_state ?? null,
            'face_detected'       => (bool) ($snapshot->face_detected ?? false),
            'not_detected_reason' => $snapshot->not_detected_reason ?? null,
            'frame_quality'       => $quality,
            'score_breakdown'     => $breakdown,
            'raw_components'      => [
                'gaze_score'      => $this->numOrNull($snapshot->gaze_score ?? null),
                'emotion_score'   => $this->numOrNull($snapshot->emotion_score ?? null),
                'head_pose_score' => $this->numOrNull($snapshot->head_pose_score ?? null),
                'presence_score'  => $this->numOrNull($snapshot->presence_score ?? null),
                'posture_score'   => $this->numOrNull($snapshot->posture_score ?? null),
            ],
            'extras' => [
                'emotion'        => $snapshot->emotion        ?? null,
                'posture_state'  => $snapshot->posture_state  ?? null,
                'hand_raised'    => (bool) ($snapshot->hand_raised ?? false),
            ],
        ]);
    }

    // ── 5. Weekly insights (последний AI-отчёт по классу) ──────

    /**
     * GET /api/v1/analytics/weekly-insights?classroom_id=
     */
    public function weeklyInsights(Request $request): JsonResponse
    {
        $request->validate([
            'classroom_id' => 'required|uuid',
        ]);

        $rec = AiRecommendation::query()
            ->where('classroom_id', $request->classroom_id)
            ->where('type', 'weekly_analysis')
            ->latest()
            ->first();

        if (!$rec) {
            return response()->json([
                'classroom_id' => $request->classroom_id,
                'available'    => false,
            ]);
        }

        return response()->json([
            'classroom_id'  => $request->classroom_id,
            'available'     => true,
            'generated_at'  => $rec->created_at,
            'content'       => $rec->content,
            'key_insights'  => $rec->key_insights,
            'action_items'  => $rec->action_items,
            'data_summary'  => $rec->input_data_summary,
            'model_used'    => $rec->model_used,
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────

    private function parseRange(Request $request): array
    {
        return [
            Carbon::parse($request->from)->startOfDay(),
            Carbon::parse($request->to)->endOfDay(),
        ];
    }

    private function periodPayload(Carbon $from, Carbon $to): array
    {
        return [
            'from' => $from->toDateString(),
            'to'   => $to->toDateString(),
        ];
    }

    private function decodeJsonField(mixed $field): mixed
    {
        if ($field === null) return null;
        if (is_array($field)) return $field;
        $decoded = json_decode((string) $field, true);
        return $decoded === null ? $field : $decoded;
    }

    private function numOrNull(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }
}
