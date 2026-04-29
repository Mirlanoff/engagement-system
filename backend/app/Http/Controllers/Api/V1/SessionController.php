<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Session\StartSessionRequest;
use App\Http\Resources\Session\SessionResource;
use App\Models\AiRecommendation;
use App\Models\EngagementAlert;
use App\Models\LessonSession;
use App\Services\SessionService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionService $service,
    ) {}

    // GET /api/v1/sessions
    public function index(Request $request): JsonResponse
    {
        $sessions = LessonSession::with(['classroom', 'teacher'])
            ->whereHas('classroom', fn ($q) => $q->where('school_id', $request->user()->school_id))
            ->when($request->classroom_id, fn($q) => $q->where('classroom_id', $request->classroom_id))
            ->when($request->status,       fn($q) => $q->where('status', $request->status))
            ->when($request->date_from,    fn($q) => $q->where('started_at', '>=', $request->date_from))
            ->when($request->date_to,      fn($q) => $q->where('started_at', '<=', $request->date_to . ' 23:59:59'))
            ->orderByDesc('started_at')
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'data' => SessionResource::collection($sessions->items()),
            'meta' => [
                'current_page' => $sessions->currentPage(),
                'last_page'    => $sessions->lastPage(),
                'total'        => $sessions->total(),
            ],
        ]);
    }

    // GET /api/v1/sessions/active
    public function active(): JsonResponse
    {
        $sessions = LessonSession::where('status', 'active')
            ->whereHas('classroom', fn ($q) => $q->where('school_id', request()->user()->school_id))
            ->with(['classroom', 'teacher'])
            ->get();

        return response()->json([
            'data'  => SessionResource::collection($sessions),
            'count' => $sessions->count(),
        ]);
    }

    // POST /api/v1/sessions
    public function store(StartSessionRequest $request): JsonResponse
    {
        $session = $this->service->start(
            classroomId: $request->classroom_id,
            teacherId:   $request->user()->id,
            subject:     $request->subject,
        );

        return response()->json([
            'data'    => new SessionResource($session),
            'message' => 'Урок начат',
        ], 201);
    }

    // GET /api/v1/sessions/{session}
    public function show(LessonSession $session): JsonResponse
    {
        if ($session->classroom?->school_id !== request()->user()->school_id) {
            return response()->json(['message' => 'Сессия не найдена'], 404);
        }

        $session->load(['classroom', 'teacher']);
        return response()->json(['data' => new SessionResource($session)]);
    }

    // POST /api/v1/sessions/{session}/pause
    public function pause(LessonSession $session): JsonResponse
    {
        if ($session->classroom?->school_id !== request()->user()->school_id) {
            return response()->json(['message' => 'Сессия не найдена'], 404);
        }

        try {
            $session = $this->service->pause($session);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new SessionResource($session), 'message' => 'Урок на паузе']);
    }

    // POST /api/v1/sessions/{session}/resume
    public function resume(LessonSession $session): JsonResponse
    {
        if ($session->classroom?->school_id !== request()->user()->school_id) {
            return response()->json(['message' => 'Сессия не найдена'], 404);
        }

        try {
            $session = $this->service->resume($session);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new SessionResource($session), 'message' => 'Урок возобновлён']);
    }

    // POST /api/v1/sessions/{session}/end
    public function end(LessonSession $session): JsonResponse
    {
        if ($session->classroom?->school_id !== request()->user()->school_id) {
            return response()->json(['message' => 'Сессия не найдена'], 404);
        }

        try {
            $session = $this->service->end($session);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => new SessionResource($session), 'message' => 'Урок завершён']);
    }

    // GET /api/v1/sessions/{session}/timeline
    public function timeline(LessonSession $session): JsonResponse
    {
        if ($session->classroom?->school_id !== request()->user()->school_id) {
            return response()->json(['message' => 'Сессия не найдена'], 404);
        }

        $data = DB::table('engagement_aggregates')
            ->where('session_id', $session->id)
            ->orderBy('minute_at')
            ->get()
            ->map(fn($a) => [
                'minute'    => (int) \Carbon\Carbon::parse($a->minute_at)->diffInMinutes($session->started_at),
                'avg_score' => (float) $a->avg_score,
                'min_score' => (float) $a->min_score,
                'max_score' => (float) $a->max_score,
                'students'  => $a->students_detected,
                'high'      => $a->high_engagement_count,
                'medium'    => $a->medium_engagement_count,
                'low'       => $a->low_engagement_count,
            ]);

        return response()->json([
            'session_id' => $session->id,
            'started_at' => $session->started_at?->toIso8601String(),
            'data'       => $data,
        ]);
    }

    // GET /api/v1/sessions/{session}/students
    public function students(LessonSession $session): JsonResponse
    {
        if ($session->classroom?->school_id !== request()->user()->school_id) {
            return response()->json(['message' => 'Сессия не найдена'], 404);
        }

        $data = DB::table('engagement_snapshots as es')
            ->join('students as s', 's.id', '=', 'es.student_id')
            ->where('es.session_id', $session->id)
            ->groupBy('es.student_id', 's.name', 's.student_code')
            ->selectRaw('
                es.student_id,
                s.name as student_name,
                s.student_code,
                ROUND(AVG(es.engagement_score)::numeric, 2) as avg_score,
                ROUND(MIN(es.engagement_score)::numeric, 2) as min_score,
                ROUND(MAX(es.engagement_score)::numeric, 2) as max_score,
                COUNT(*) as snapshots,
                SUM(CASE WHEN es.face_detected = false THEN 1 ELSE 0 END) as absent_count
            ')
            ->orderByDesc('avg_score')
            ->get()
            ->map(fn($s) => [
                'student_id'   => $s->student_id,
                'name'         => $s->student_name,
                'code'         => $s->student_code,
                'avg_score'    => (float) $s->avg_score,
                'min_score'    => (float) $s->min_score,
                'max_score'    => (float) $s->max_score,
                'snapshots'    => $s->snapshots,
                'absent_count' => $s->absent_count,
                'level'        => match(true) {
                    $s->avg_score >= 75 => 'high',
                    $s->avg_score >= 50 => 'medium',
                    default              => 'low',
                },
            ]);

        return response()->json(['session_id' => $session->id, 'data' => $data]);
    }

    public function alerts(Request $request): JsonResponse
    {
        $alerts = EngagementAlert::query()
            ->with(['classroom:id,name', 'student:id,name'])
            ->whereIn('classroom_id', $this->schoolClassroomIds($request))
            ->when($request->session_id, fn ($q) => $q->where('session_id', $request->session_id))
            ->when($request->severity, fn ($q) => $q->where('severity', $request->severity))
            ->when($request->boolean('active'), fn ($q) => $q->where('is_acknowledged', false))
            ->latest('triggered_at')
            ->paginate($request->per_page ?? 20);
        $data = $this->formatAlerts($alerts->getCollection());

        return response()->json([
            'data' => $data,
            'meta' => [
                'current_page' => $alerts->currentPage(),
                'last_page' => $alerts->lastPage(),
                'total' => $alerts->total(),
            ],
        ]);
    }

    public function activeAlerts(Request $request): JsonResponse
    {
        $alerts = EngagementAlert::query()
            ->with(['classroom:id,name', 'student:id,name'])
            ->whereIn('classroom_id', $this->schoolClassroomIds($request))
            ->where('is_acknowledged', false)
            ->latest('triggered_at')
            ->limit(50)
            ->get();

        return response()->json(['data' => $this->formatAlerts($alerts), 'count' => $alerts->count()]);
    }

    public function acknowledgeAlert(Request $request, EngagementAlert $alert): JsonResponse
    {
        $request->validate(['note' => 'nullable|string|max:500']);

        if (!in_array($alert->classroom_id, $this->schoolClassroomIds($request), true)) {
            return response()->json(['message' => 'Алерт не найден'], 404);
        }

        $alert->update([
            'is_acknowledged' => true,
            'acknowledged_by' => $request->user()->id,
            'acknowledged_at' => now(),
            'acknowledgement_note' => $request->note,
        ]);

        return response()->json(['data' => $alert->fresh(), 'message' => 'Алерт обработан']);
    }

    public function recommendations(Request $request): JsonResponse
    {
        $recommendations = AiRecommendation::query()
            ->whereHas('session.classroom', fn ($q) => $q->where('school_id', $request->user()->school_id))
            ->with('session:id,classroom_id,subject,started_at,ended_at')
            ->when($request->session_id, fn ($q) => $q->where('session_id', $request->session_id))
            ->when($request->type, fn ($q) => $q->where('type', $request->type))
            ->latest()
            ->paginate($request->per_page ?? 20);

        return response()->json([
            'data' => $recommendations->items(),
            'meta' => [
                'current_page' => $recommendations->currentPage(),
                'last_page' => $recommendations->lastPage(),
                'total' => $recommendations->total(),
            ],
        ]);
    }

    public function markRecommendationRead(AiRecommendation $recommendation): JsonResponse
    {
        $recommendation->loadMissing('session.classroom');
        if ($recommendation->session?->classroom?->school_id !== request()->user()->school_id) {
            return response()->json(['message' => 'Рекомендация не найдена'], 404);
        }

        $recommendation->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return response()->json(['data' => $recommendation->fresh()]);
    }

    public function rateRecommendation(Request $request, AiRecommendation $recommendation): JsonResponse
    {
        $validated = $request->validate(['rating' => 'required|integer|min:1|max:5']);

        $recommendation->loadMissing('session.classroom');
        if ($recommendation->session?->classroom?->school_id !== $request->user()->school_id) {
            return response()->json(['message' => 'Рекомендация не найдена'], 404);
        }

        $recommendation->update(['helpfulness_rating' => $validated['rating']]);

        return response()->json(['data' => $recommendation->fresh()]);
    }

    // POST /api/internal/snapshots  (вызывается ML сервисом)
    public function receiveSnapshots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id'                   => 'required|string',
            'snapshots'                    => 'required|array|min:1',
            'snapshots.*.student_id'       => 'required|string',
            'snapshots.*.camera_id'        => 'required|string',
            'snapshots.*.captured_at'      => 'required|date',
            'snapshots.*.engagement_score' => 'required|numeric|min:0|max:100',
            'snapshots.*.emotion'          => 'nullable|string',
            'snapshots.*.gaze_yaw'         => 'nullable|numeric',
            'snapshots.*.face_detected'    => 'nullable|boolean',
        ]);

        $this->service->processSnapshots($validated['session_id'], $validated['snapshots']);

        return response()->json(['status' => 'accepted', 'count' => count($validated['snapshots'])], 202);
    }

    private function formatAlerts(EloquentCollection $alerts): array
    {
        return $alerts->map(fn (EngagementAlert $alert) => [
            'alert_id' => $alert->id,
            'id' => $alert->id,
            'session_id' => $alert->session_id,
            'classroom_id' => $alert->classroom_id,
            'classroom_name' => $alert->classroom?->name,
            'student_id' => $alert->student_id,
            'student_name' => $alert->student?->name,
            'type' => $alert->type,
            'severity' => $alert->severity,
            'trigger_score' => $alert->trigger_score,
            'threshold_score' => $alert->threshold_score,
            'message' => $alert->message,
            'context' => $alert->context,
            'is_acknowledged' => $alert->is_acknowledged,
            'acknowledged_at' => $alert->acknowledged_at?->toIso8601String(),
            'triggered_at' => $alert->triggered_at?->toIso8601String(),
        ])->all();
    }

    private function schoolClassroomIds(Request $request): array
    {
        return DB::table('classrooms')
            ->where('school_id', $request->user()->school_id)
            ->pluck('id')
            ->all();
    }
}
