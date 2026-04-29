<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\SessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Внутренний API для ML сервиса.
 * Защищён отдельным секретным токеном (не Sanctum).
 */
class InternalMlController extends Controller
{
    public function __construct(
        private readonly SessionService $sessionService,
    ) {}

    /**
     * POST /api/internal/snapshots
     * Приём батча снэпшотов от ML сервиса
     */
    public function receiveSnapshots(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'session_id'              => 'required|uuid|exists:lesson_sessions,id',
            'snapshots'               => 'required|array|min:1|max:50',
            'snapshots.*.student_id'  => 'required|uuid',
            'snapshots.*.camera_id'   => 'required|string|max:50',
            'snapshots.*.captured_at' => 'required|date',

            // Engagement score
            'snapshots.*.engagement_score' => 'required|numeric|min:0|max:100',
            'snapshots.*.gaze_score'       => 'nullable|numeric|min:0|max:100',
            'snapshots.*.emotion_score'    => 'nullable|numeric|min:0|max:100',
            'snapshots.*.head_pose_score'  => 'nullable|numeric|min:0|max:100',
            'snapshots.*.presence_score'   => 'nullable|numeric|min:0|max:100',

            // Emotion
            'snapshots.*.emotion'            => 'nullable|string|in:neutral,happy,sad,angry,fearful,disgusted,surprised',
            'snapshots.*.emotion_confidence' => 'nullable|numeric|min:0|max:1',

            // Gaze
            'snapshots.*.gaze_yaw'   => 'nullable|numeric|between:-180,180',
            'snapshots.*.gaze_pitch' => 'nullable|numeric|between:-90,90',

            // Head pose
            'snapshots.*.head_yaw'   => 'nullable|numeric|between:-180,180',
            'snapshots.*.head_pitch' => 'nullable|numeric|between:-90,90',
            'snapshots.*.head_roll'  => 'nullable|numeric|between:-90,90',

            // Face detection
            'snapshots.*.face_detected'    => 'nullable|boolean',
            'snapshots.*.face_confidence'  => 'nullable|numeric|min:0|max:1',

            'snapshots.*.processing_time_ms' => 'nullable|numeric|min:0',
        ]);

        $this->sessionService->processSnapshots(
            $validated['session_id'],
            $validated['snapshots']
        );

        return response()->json([
            'status'   => 'accepted',
            'count'    => count($validated['snapshots']),
            'ts'       => now()->toIso8601String(),
        ], 202);
    }

    /**
     * POST /api/internal/sessions/{sessionId}/camera-error
     * ML сервис сообщает об ошибке камеры
     */
    public function cameraError(Request $request, string $sessionId): JsonResponse
    {
        $validated = $request->validate([
            'camera_id' => 'required|string',
            'error'     => 'required|string|max:500',
        ]);

        Log::warning('Camera error reported by ML service', [
            'session_id' => $sessionId,
            'camera_id'  => $validated['camera_id'],
            'error'      => $validated['error'],
        ]);

        return response()->json(['status' => 'received'], 202);
    }
}
