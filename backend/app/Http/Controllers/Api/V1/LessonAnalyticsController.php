<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LessonSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Лёгкий контроллер аналитики, опирающийся напрямую на таблицы
 * lesson_sessions / engagement_snapshots, без сторонних агрегатов.
 *
 *  - GET /api/v1/analytics/lessons              — список последних уроков
 *  - GET /api/v1/analytics/sessions/{session}   — разбивка одного урока по студентам
 */
class LessonAnalyticsController extends Controller
{
    private const EMOTIONS = [
        'happy', 'neutral', 'sad', 'angry', 'fearful', 'disgusted', 'surprised',
    ];

    /**
     * GET /api/v1/analytics/lessons
     */
    public function lessons(Request $request): JsonResponse
    {
        $request->validate([
            'classroom_id' => 'nullable|uuid',
            'status'       => 'nullable|in:active,paused,completed,cancelled,scheduled',
            'limit'        => 'nullable|integer|min:1|max:100',
        ]);

        $limit = (int) ($request->input('limit', 30));

        $sessions = LessonSession::query()
            ->with(['classroom', 'teacher'])
            ->when($request->classroom_id, fn ($q) => $q->where('classroom_id', $request->classroom_id))
            ->when($request->status,       fn ($q) => $q->where('status', $request->status))
            ->whereIn('status', ['active', 'paused', 'completed'])
            ->orderByDesc('started_at')
            ->limit($limit)
            ->get();

        if ($sessions->isEmpty()) {
            return response()->json(['data' => []]);
        }

        $sessionIds = $sessions->pluck('id')->all();

        $liveStats = DB::table('engagement_snapshots')
            ->selectRaw('
                session_id,
                ROUND(AVG(engagement_score)::numeric, 2) AS avg_score,
                ROUND(MIN(engagement_score)::numeric, 2) AS min_score,
                ROUND(MAX(engagement_score)::numeric, 2) AS max_score,
                COUNT(*) AS total_snapshots,
                COUNT(DISTINCT student_id) AS unique_students
            ')
            ->whereIn('session_id', $sessionIds)
            ->groupBy('session_id')
            ->get()
            ->keyBy('session_id');

        $data = $sessions->map(function (LessonSession $s) use ($liveStats) {
            $live = $liveStats->get($s->id);

            $avg = $s->avg_engagement_score !== null
                ? (float) $s->avg_engagement_score
                : (float) ($live->avg_score ?? 0);

            return [
                'id'                   => $s->id,
                'classroom_id'         => $s->classroom_id,
                'classroom_name'       => $s->classroom?->name,
                'subject'              => $s->subject,
                'status'               => $s->status,
                'teacher_name'         => $s->teacher?->name,
                'started_at'           => $s->started_at?->toIso8601String(),
                'ended_at'             => $s->ended_at?->toIso8601String(),
                'duration_minutes'     => $this->durationMinutes($s),
                'students_count'       => $live->unique_students ?? $s->students_count ?? 0,
                'avg_engagement_score' => round($avg, 2),
                'min_engagement_score' => $s->min_engagement_score !== null
                    ? (float) $s->min_engagement_score
                    : (float) ($live->min_score ?? 0),
                'max_engagement_score' => $s->max_engagement_score !== null
                    ? (float) $s->max_engagement_score
                    : (float) ($live->max_score ?? 0),
                'total_snapshots'      => (int) ($live->total_snapshots ?? $s->total_snapshots ?? 0),
                'engagement_level'     => $this->level($avg),
            ];
        });

        return response()->json(['data' => $data]);
    }

    /**
     * GET /api/v1/analytics/sessions/{session}
     */
    public function session(LessonSession $session): JsonResponse
    {
        $session->load(['classroom', 'teacher']);

        $sessionStats = DB::table('engagement_snapshots')
            ->selectRaw('
                ROUND(AVG(engagement_score)::numeric, 2) AS avg_score,
                ROUND(MIN(engagement_score)::numeric, 2) AS min_score,
                ROUND(MAX(engagement_score)::numeric, 2) AS max_score,
                COUNT(*)                                  AS total_snapshots,
                COUNT(DISTINCT student_id)                AS unique_students
            ')
            ->where('session_id', $session->id)
            ->first();

        $studentRows = DB::table('engagement_snapshots as es')
            ->leftJoin('students as s', 's.id', '=', 'es.student_id')
            ->where('es.session_id', $session->id)
            ->groupBy('es.student_id', 's.name', 's.student_code')
            ->selectRaw('
                es.student_id,
                s.name         AS student_name,
                s.student_code AS student_code,
                ROUND(AVG(es.engagement_score)::numeric, 2) AS avg_score,
                ROUND(MIN(es.engagement_score)::numeric, 2) AS min_score,
                ROUND(MAX(es.engagement_score)::numeric, 2) AS max_score,
                ROUND(AVG(es.gaze_score)::numeric, 2)       AS avg_gaze,
                ROUND(AVG(es.emotion_score)::numeric, 2)    AS avg_emotion,
                ROUND(AVG(es.head_pose_score)::numeric, 2)  AS avg_head_pose,
                ROUND(AVG(es.presence_score)::numeric, 2)   AS avg_presence,
                COUNT(*)                                    AS snapshots,
                SUM(CASE WHEN es.face_detected = false THEN 1 ELSE 0 END) AS absent_count,
                SUM(CASE
                    WHEN es.gaze_yaw IS NOT NULL AND ABS(es.gaze_yaw) < 25
                    THEN 1 ELSE 0
                END) AS gaze_on_board_count
            ')
            ->orderByDesc('avg_score')
            ->get();

        $emotionRows = DB::table('engagement_snapshots')
            ->where('session_id', $session->id)
            ->whereNotNull('emotion')
            ->selectRaw('student_id, emotion, COUNT(*) AS cnt')
            ->groupBy('student_id', 'emotion')
            ->get();

        $emotionsByStudent = [];
        foreach ($emotionRows as $row) {
            $emotionsByStudent[$row->student_id][$row->emotion] = (int) $row->cnt;
        }

        $students = $studentRows->map(function ($r) use ($emotionsByStudent) {
            $studentId = $r->student_id;

            $rawDist  = $emotionsByStudent[$studentId] ?? [];
            $distAll  = $this->normalizeEmotionDistribution($rawDist);

            $dominant = null;
            $dominantCount = -1;
            foreach ($rawDist as $emotion => $cnt) {
                if ($cnt > $dominantCount) {
                    $dominant = $emotion;
                    $dominantCount = $cnt;
                }
            }

            $snapshots = (int) $r->snapshots;
            $gazeOnBoardPct = $snapshots > 0
                ? round(((int) $r->gaze_on_board_count) * 100 / $snapshots, 1)
                : 0.0;

            $avg = (float) ($r->avg_score ?? 0);

            return [
                'student_id'         => $studentId,
                'student_name'       => $r->student_name ?? 'Студент',
                'student_code'       => $r->student_code,
                'avg_engagement'     => $avg,
                'min_engagement'     => (float) ($r->min_score ?? 0),
                'max_engagement'     => (float) ($r->max_score ?? 0),
                'avg_gaze'           => (float) ($r->avg_gaze ?? 0),
                'avg_emotion'        => (float) ($r->avg_emotion ?? 0),
                'avg_head_pose'      => (float) ($r->avg_head_pose ?? 0),
                'avg_presence'       => (float) ($r->avg_presence ?? 0),
                'snapshots'          => $snapshots,
                'absent_count'       => (int) ($r->absent_count ?? 0),
                'gaze_on_board_pct'  => $gazeOnBoardPct,
                'dominant_emotion'   => $dominant,
                'emotion_distribution' => $distAll,
                'level'              => $this->level($avg),
            ];
        })->values();

        return response()->json([
            'session' => [
                'id'                   => $session->id,
                'classroom_id'         => $session->classroom_id,
                'classroom_name'       => $session->classroom?->name,
                'subject'              => $session->subject,
                'status'               => $session->status,
                'teacher_name'         => $session->teacher?->name,
                'started_at'           => $session->started_at?->toIso8601String(),
                'ended_at'             => $session->ended_at?->toIso8601String(),
                'duration_minutes'     => $this->durationMinutes($session),
                'avg_engagement_score' => round((float) ($sessionStats->avg_score ?? 0), 2),
                'min_engagement_score' => round((float) ($sessionStats->min_score ?? 0), 2),
                'max_engagement_score' => round((float) ($sessionStats->max_score ?? 0), 2),
                'students_count'       => (int) ($sessionStats->unique_students ?? 0),
                'total_snapshots'      => (int) ($sessionStats->total_snapshots ?? 0),
                'engagement_level'     => $this->level((float) ($sessionStats->avg_score ?? 0)),
            ],
            'students' => $students,
        ]);
    }

    private function normalizeEmotionDistribution(array $raw): array
    {
        $total = array_sum($raw);
        $out   = [];
        foreach (self::EMOTIONS as $emotion) {
            $count = (int) ($raw[$emotion] ?? 0);
            $out[$emotion] = [
                'count'   => $count,
                'percent' => $total > 0 ? round($count * 100 / $total, 1) : 0.0,
            ];
        }
        return $out;
    }

    private function level(float $score): string
    {
        return match (true) {
            $score >= 75 => 'high',
            $score >= 50 => 'medium',
            default      => 'low',
        };
    }

    private function durationMinutes(LessonSession $s): ?int
    {
        if (!$s->started_at) {
            return null;
        }
        $end = $s->ended_at ?? now();
        return (int) $s->started_at->diffInMinutes($end);
    }
}
