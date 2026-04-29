<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\InternalMlController;
use App\Http\Controllers\Api\V1\SessionController;
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
    Route::get('alerts',                    [SessionController::class, 'alerts']);
    Route::get('alerts/active',             [SessionController::class, 'activeAlerts']);
    Route::post('alerts/{alert}/acknowledge', [SessionController::class, 'acknowledgeAlert']);

    // AI рекомендации
    Route::get('recommendations',              [SessionController::class, 'recommendations']);
    Route::post('recommendations/{recommendation}/read', [SessionController::class, 'markRecommendationRead']);
    Route::post('recommendations/{recommendation}/rate', [SessionController::class, 'rateRecommendation']);

    // Аналитика
    Route::get ('analytics/overview',             [AnalyticsController::class, 'overview']);
    Route::get ('analytics/heatmap/{classroomId}', [AnalyticsController::class, 'heatmap']);
    Route::get ('analytics/students/{studentId}',  [AnalyticsController::class, 'student']);
    Route::post('analytics/compare',              [AnalyticsController::class, 'compare']);
});

// ML internal
Route::prefix('internal')->middleware('internal.api')->group(function () {
    Route::post('snapshots',                         [InternalMlController::class, 'receiveSnapshots']);
    Route::post('sessions/{sessionId}/camera-error', [InternalMlController::class, 'cameraError']);
});
