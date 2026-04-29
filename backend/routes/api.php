<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Models\LessonSession;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/auth')->group(function () {
    Route::post('login',   [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me',      [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    // Классы
    Route::get('classrooms', [ClassroomController::class, 'index']);

    // Сессии
    Route::get ('sessions/active',            [SessionController::class, 'active']);
    Route::get ('sessions',                   [SessionController::class, 'index']);
    Route::post('sessions',                   [SessionController::class, 'store']);
    Route::get ('sessions/{session}',         [SessionController::class, 'show']);
    Route::post('sessions/{session}/pause',   [SessionController::class, 'pause']);
    Route::post('sessions/{session}/resume',  [SessionController::class, 'resume']);
    Route::post('sessions/{session}/end',     [SessionController::class, 'end']);
    Route::get ('sessions/{session}/timeline',[SessionController::class, 'timeline']);
    Route::get ('sessions/{session}/students',[SessionController::class, 'students']);

    // Алерты
    Route::get('alerts', function (Request $request) {
        $alerts = DB::table('engagement_alerts')
            ->orderByDesc('triggered_at')
            ->limit(50)
            ->get();
        return response()->json(['data' => $alerts]);
    });

    Route::get('alerts/active', function (Request $request) {
        $alerts = DB::table('engagement_alerts')
            ->where('is_acknowledged', false)
            ->orderByDesc('triggered_at')
            ->limit(50)
            ->get();
        return response()->json(['data' => $alerts]);
    });

    // Рекомендации
    Route::get('recommendations', function (Request $request) {
        $recs = DB::table('ai_recommendations')
            ->where('generated_for', $request->user()->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();
        return response()->json(['data' => $recs]);
    });

    // Аналитика — обзор по классам
    Route::get('analytics/overview', function (Request $request) {
        $schoolId = $request->user()->school_id;

        $classrooms = DB::table('classrooms')
            ->where('school_id', $schoolId)
            ->where('is_active', true)
            ->get();

        $stats = $classrooms->map(function ($classroom) {
            $sessions = DB::table('lesson_sessions')
                ->where('classroom_id', $classroom->id)
                ->where('status', 'completed')
                ->get();

            return [
                'classroom_id'   => $classroom->id,
                'classroom_name' => $classroom->name,
                'total_sessions' => $sessions->count(),
                'avg_score'      => round($sessions->avg('avg_engagement_score') ?? 0, 2),
                'total_students' => $sessions->sum('students_count'),
                'last_session'   => $sessions->sortByDesc('ended_at')->first()?->ended_at,
            ];
        });

        $totalSessions = LessonSession::where('status', 'completed')->count();
        $schoolAvg = LessonSession::where('status', 'completed')
            ->whereNotNull('avg_engagement_score')
            ->avg('avg_engagement_score');

        return response()->json([
            'data' => [
                'classrooms'     => $stats,
                'total_sessions' => $totalSessions,
                'school_avg'     => round($schoolAvg ?? 0, 2),
            ],
        ]);
    });
});

// ML internal
Route::prefix('internal')->group(function () {
    Route::post('snapshots', [SessionController::class, 'receiveSnapshots']);
});
