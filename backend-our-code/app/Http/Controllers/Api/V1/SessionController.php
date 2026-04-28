<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Session\Models\LessonSession;
use App\Domain\Session\Services\SessionService;
use App\Domain\Session\DTOs\StartSessionDTO;
use App\Http\Controllers\Controller;
use App\Http\Requests\Session\StartSessionRequest;
use App\Http\Resources\Session\SessionResource;
use App\Http\Resources\Session\SessionDetailResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionService $sessionService,
    ) {
        $this->middleware('auth:sanctum');
        $this->middleware('role:admin,supervisor,teacher');
    }

    /**
     * GET /api/v1/sessions
     * Список сессий с фильтрацией
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', LessonSession::class);

        $sessions = LessonSession::query()
            ->whereHas('classroom', fn ($q) =>
                $q->where('school_id', $request->user()->school_id)
            )
            ->when($request->classroom_id, fn ($q) =>
                $q->forClassroom($request->classroom_id)
            )
            ->when($request->status, fn ($q) =>
                $q->where('status', $request->status)
            )
            ->when($request->date_from, fn ($q) =>
                $q->where('started_at', '>=', $request->date_from)
            )
            ->when($request->date_to, fn ($q) =>
                $q->where('started_at', '<=', $request->date_to)
            )
            ->with(['classroom', 'teacher'])
            ->orderByDesc('started_at')
            ->paginate($request->per_page ?? 20);

        return SessionResource::collection($sessions);
    }

    /**
     * GET /api/v1/sessions/active
     * Все активные сессии школы (для дашборда супервайзера)
     */
    public function active(Request $request): JsonResponse
    {
        $sessions = LessonSession::active()
            ->whereHas('classroom', fn ($q) =>
                $q->where('school_id', $request->user()->school_id)
            )
            ->with(['classroom', 'teacher'])
            ->get();

        return response()->json([
            'data'  => SessionResource::collection($sessions),
            'count' => $sessions->count(),
        ]);
    }

    /**
     * POST /api/v1/sessions
     * Старт нового урока
     */
    public function store(StartSessionRequest $request): JsonResponse
    {
        $dto = new StartSessionDTO(
            classroomId: $request->classroom_id,
            teacherId:   $request->user()->id,
            subject:     $request->subject,
        );

        $session = $this->sessionService->start($dto);

        return response()->json([
            'data'    => new SessionDetailResource($session),
            'message' => 'Урок начат',
        ], 201);
    }

    /**
     * GET /api/v1/sessions/{session}
     */
    public function show(LessonSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $session->load(['classroom', 'teacher', 'alerts' => fn ($q) => $q->latest()->limit(20)]);

        return response()->json([
            'data' => new SessionDetailResource($session),
        ]);
    }

    /**
     * POST /api/v1/sessions/{session}/pause
     */
    public function pause(LessonSession $session): JsonResponse
    {
        $this->authorize('manage', $session);
        $session = $this->sessionService->pause($session);

        return response()->json([
            'data'    => new SessionResource($session),
            'message' => 'Урок на паузе',
        ]);
    }

    /**
     * POST /api/v1/sessions/{session}/resume
     */
    public function resume(LessonSession $session): JsonResponse
    {
        $this->authorize('manage', $session);
        $session = $this->sessionService->resume($session);

        return response()->json([
            'data'    => new SessionResource($session),
            'message' => 'Урок возобновлён',
        ]);
    }

    /**
     * POST /api/v1/sessions/{session}/end
     */
    public function end(LessonSession $session): JsonResponse
    {
        $this->authorize('manage', $session);
        $session = $this->sessionService->end($session);

        return response()->json([
            'data'    => new SessionDetailResource($session),
            'message' => 'Урок завершён',
        ]);
    }

    /**
     * GET /api/v1/sessions/{session}/timeline
     * Timeline вовлечённости по минутам
     */
    public function timeline(LessonSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $aggregates = $session->aggregates()
            ->orderBy('minute_at')
            ->get()
            ->map(fn ($a) => [
                'minute'    => $a->minute_at->diffInMinutes($session->started_at),
                'avg_score' => (float) $a->avg_score,
                'min_score' => (float) $a->min_score,
                'max_score' => (float) $a->max_score,
                'high'      => $a->high_engagement_count,
                'medium'    => $a->medium_engagement_count,
                'low'       => $a->low_engagement_count,
                'students'  => $a->students_detected,
            ]);

        return response()->json([
            'session_id' => $session->id,
            'started_at' => $session->started_at?->toIso8601String(),
            'data'       => $aggregates,
        ]);
    }

    /**
     * GET /api/v1/sessions/{session}/students
     * Статистика по студентам за урок
     */
    public function students(LessonSession $session): JsonResponse
    {
        $this->authorize('view', $session);

        $students = \App\Domain\Engagement\Models\EngagementSnapshot::forSession($session->id)
            ->with('student:id,name,student_code')
            ->selectRaw('
                student_id,
                AVG(engagement_score) as avg_score,
                MIN(engagement_score) as min_score,
                MAX(engagement_score) as max_score,
                COUNT(*) as snapshots,
                SUM(CASE WHEN face_detected = false THEN 1 ELSE 0 END) as absent_count,
                MODE() WITHIN GROUP (ORDER BY emotion) as dominant_emotion
            ')
            ->groupBy('student_id')
            ->orderByDesc('avg_score')
            ->get();

        return response()->json([
            'session_id' => $session->id,
            'data'       => $students->map(fn ($s) => [
                'student_id'      => $s->student_id,
                'student'         => $s->student ? [
                    'name' => $s->student->name,
                    'code' => $s->student->student_code,
                ] : null,
                'avg_score'       => round($s->avg_score, 2),
                'min_score'       => round($s->min_score, 2),
                'max_score'       => round($s->max_score, 2),
                'snapshots'       => $s->snapshots,
                'absent_count'    => $s->absent_count,
                'dominant_emotion'=> $s->dominant_emotion,
                'level'           => match(true) {
                    $s->avg_score >= 75 => 'high',
                    $s->avg_score >= 50 => 'medium',
                    default              => 'low',
                },
            ]),
        ]);
    }
}
