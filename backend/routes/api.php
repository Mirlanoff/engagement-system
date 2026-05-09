<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ClassroomController;
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
    Route::post('sessions/{session}/frames',  [SessionController::class, 'ingestFrame']);

    // Админ-операции
    Route::post('admin/reset-dashboard', [AdminController::class, 'resetDashboard']);

    // Заглушки
    Route::get('alerts',             fn() => response()->json(['data' => []]));
    Route::get('alerts/active',      fn() => response()->json(['data' => []]));
    Route::get('recommendations',    fn() => response()->json(['data' => []]));

    // Аналитика — только для supervisor / admin
    Route::middleware('role:admin,supervisor')->prefix('analytics')->group(function () {
        Route::get('heatmap',                       [AnalyticsController::class, 'heatmap']);
        Route::get('comparison',                    [AnalyticsController::class, 'comparison']);
        Route::get('student-trends',                [AnalyticsController::class, 'studentTrends']);
        Route::get('snapshots/{id}/breakdown',      [AnalyticsController::class, 'snapshotBreakdown']);
        Route::get('weekly-insights',               [AnalyticsController::class, 'weeklyInsights']);
    });
});

// ML internal
Route::prefix('internal')->group(function () {
    Route::post('snapshots', [SessionController::class, 'receiveSnapshots']);
});
