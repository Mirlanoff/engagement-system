<?php

use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\InternalMlController;
use App\Http\Controllers\Api\V1\AlertController;
use App\Http\Controllers\Api\V1\RecommendationController;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\StudentController;
use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

// ── Auth ─────────────────────────────────────────────────────────
Route::prefix('v1/auth')->group(function () {
    Route::post('login',        [AuthController::class, 'login']);
    Route::post('logout',       [AuthController::class, 'logout'])->middleware('auth:sanctum');
    Route::get('me',            [AuthController::class, 'me'])->middleware('auth:sanctum');
    Route::post('refresh',      [AuthController::class, 'refresh'])->middleware('auth:sanctum');
});

// ── Внутренний API для ML сервиса ─────────────────────────────────
Route::prefix('internal')->middleware('internal.api')->group(function () {
    Route::post('snapshots',                          [InternalMlController::class, 'receiveSnapshots']);
    Route::post('sessions/{sessionId}/camera-error',  [InternalMlController::class, 'cameraError']);
});

// ── Основной API v1 ───────────────────────────────────────────────
Route::prefix('v1')->middleware(['auth:sanctum', 'throttle:api'])->group(function () {

    // ── Сессии (уроки) ─────────────────────────────────────────
    Route::prefix('sessions')->group(function () {
        Route::get('/',                   [SessionController::class, 'index']);
        Route::post('/',                  [SessionController::class, 'store']);
        Route::get('active',              [SessionController::class, 'active']);
        Route::get('{session}',           [SessionController::class, 'show']);
        Route::post('{session}/pause',    [SessionController::class, 'pause']);
        Route::post('{session}/resume',   [SessionController::class, 'resume']);
        Route::post('{session}/end',      [SessionController::class, 'end']);
        Route::get('{session}/timeline',  [SessionController::class, 'timeline']);
        Route::get('{session}/students',  [SessionController::class, 'students']);
    });

    // ── Классы ─────────────────────────────────────────────────
    Route::apiResource('classrooms', ClassroomController::class);
    Route::get('classrooms/{classroom}/cameras',         [ClassroomController::class, 'cameras']);
    Route::put('classrooms/{classroom}/cameras',         [ClassroomController::class, 'updateCameras']);
    Route::get('classrooms/{classroom}/sessions',        [ClassroomController::class, 'sessions']);

    // ── Студенты ───────────────────────────────────────────────
    Route::apiResource('students', StudentController::class);
    Route::post('students/{student}/enroll',             [StudentController::class, 'enroll']);
    Route::get('students/{student}/history',             [StudentController::class, 'history']);

    // ── Аналитика ──────────────────────────────────────────────
    Route::prefix('analytics')->group(function () {
        Route::get('overview',                           [AnalyticsController::class, 'overview']);
        Route::get('compare',                            [AnalyticsController::class, 'compare']);
        Route::get('heatmap/{classroomId}',              [AnalyticsController::class, 'heatmap']);
        Route::get('students/{studentId}',               [AnalyticsController::class, 'student']);
    });

    // ── Алерты ─────────────────────────────────────────────────
    Route::prefix('alerts')->group(function () {
        Route::get('/',                                  [AlertController::class, 'index']);
        Route::get('active',                             [AlertController::class, 'active']);
        Route::post('{alert}/acknowledge',               [AlertController::class, 'acknowledge']);
        Route::get('thresholds',                         [AlertController::class, 'thresholds']);
        Route::put('thresholds/{classroomId?}',          [AlertController::class, 'updateThresholds']);
    });

    // ── AI Рекомендации ────────────────────────────────────────
    Route::prefix('recommendations')->group(function () {
        Route::get('/',                                  [RecommendationController::class, 'index']);
        Route::get('{recommendation}',                   [RecommendationController::class, 'show']);
        Route::post('{recommendation}/read',             [RecommendationController::class, 'markRead']);
        Route::post('{recommendation}/rate',             [RecommendationController::class, 'rate']);
        Route::post('generate/{session}',                [RecommendationController::class, 'generate']);
    });
});
