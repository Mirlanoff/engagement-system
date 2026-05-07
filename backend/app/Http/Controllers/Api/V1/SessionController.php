<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Session\StartSessionRequest;
use App\Http\Resources\Session\SessionResource;
use App\Infrastructure\ML\MlServiceClient;
use App\Models\LessonSession;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SessionController extends Controller
{
    public function __construct(
        private readonly SessionService $service,
        private readonly MlServiceClient $mlClient,
    ) {}

    // GET /api/v1/sessions
    public function index(Request $request): JsonResponse
    {
        $sessions = LessonSession::with(['classroom', 'teacher'])
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
        $session->load(['classroom', 'teacher']);
        return response()->json(['data' => new SessionResource($session)]);
    }

    // POST /api/v1/sessions/{session}/pause
    public function pause(LessonSession $session): JsonResponse
    {
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

    // POST /api/v1/sessions/{session}/frames
    // Принимает кадр с веб-камеры учителя и пересылает в ML сервис.
    // Если ML недоступен — генерирует один симулированный снэпшот,
    // чтобы дашборд продолжал работать без отдельных терминалов.
    public function ingestFrame(LessonSession $session, Request $request): JsonResponse
    {
        if ($session->status !== 'active') {
            return response()->json([
                'status'  => 'ignored',
                'message' => "Урок не активен (статус: {$session->status})",
            ], 422);
        }

        $validated = $request->validate([
            'frame'     => 'required|string|min:32',
            'camera_id' => 'nullable|string|max:50',
        ]);

        $frame    = $this->extractBase64($validated['frame']);
        $cameraId = $validated['camera_id'] ?? 'browser';

        // Берём UUID студентов класса — ML сопоставит с найденными лицами
        $session->loadMissing('classroom.students');
        $studentIds = $session->classroom?->students
            ->pluck('id')
            ->take(50)
            ->all() ?? [];

        // 1) Пробуем настоящий ML анализ
        $mlOk = $this->mlClient->analyzeFrame(
            sessionId: $session->id,
            classroomId: $session->classroom_id,
            cameraId: $cameraId,
            frameB64: $frame,
            studentIds: $studentIds,
        );

        if ($mlOk) {
            return response()->json([
                'status'      => 'analyzing',
                'session_id'  => $session->id,
                'camera_id'   => $cameraId,
                'students'    => count($studentIds),
            ], 202);
        }

        // 2) Fallback — ML сервис недоступен. Чтобы дашборд жил,
        //    сразу записываем один симулированный снэпшот для первого
        //    студента (или для самой сессии, если студентов нет).
        $fallback = $this->simulatedSnapshot($studentIds[0] ?? null, $cameraId);
        if ($fallback !== null) {
            try {
                $this->service->processSnapshots($session->id, [$fallback]);
            } catch (\Throwable $e) {
                Log::warning('Fallback snapshot insert failed', [
                    'error'      => $e->getMessage(),
                    'session_id' => $session->id,
                ]);
            }
        }

        return response()->json([
            'status'     => 'fallback',
            'session_id' => $session->id,
            'camera_id'  => $cameraId,
            'message'    => 'ML сервис недоступен — записан симулированный снэпшот.',
        ], 202);
    }

    private function extractBase64(string $raw): string
    {
        // Поддерживаем data URL: "data:image/jpeg;base64,XXXX"
        if (str_starts_with($raw, 'data:')) {
            $parts = explode(',', $raw, 2);
            return $parts[1] ?? '';
        }
        return $raw;
    }

    private function simulatedSnapshot(?string $studentId, string $cameraId): ?array
    {
        if ($studentId === null) {
            return null;
        }

        $score = max(0, min(100, round(60 + lcg_value() * 30, 2)));
        $emotions = ['neutral', 'neutral', 'happy', 'neutral', 'surprised'];
        return [
            'student_id'         => $studentId,
            'camera_id'          => $cameraId,
            'captured_at'        => now()->toIso8601String(),
            'engagement_score'   => $score,
            'gaze_score'         => $score,
            'emotion_score'      => $score,
            'head_pose_score'    => $score,
            'presence_score'     => 95.0,
            'emotion'            => $emotions[array_rand($emotions)],
            'emotion_confidence' => 0.7,
            'gaze_yaw'           => round(lcg_value() * 30 - 15, 2),
            'gaze_pitch'         => round(lcg_value() * 20 - 10, 2),
            'head_yaw'           => round(lcg_value() * 30 - 15, 2),
            'head_pitch'         => round(lcg_value() * 20 - 10, 2),
            'head_roll'          => round(lcg_value() * 10 - 5, 2),
            'face_detected'     => true,
            'face_confidence'   => 0.9,
            'processing_time_ms' => 0,
        ];
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
}
