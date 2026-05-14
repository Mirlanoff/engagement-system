<?php

use App\Http\Controllers\Api\V1\AdminController;
use App\Http\Controllers\Api\V1\AnalyticsController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\SessionController;
use App\Http\Controllers\Api\V1\ClassroomController;
use App\Http\Controllers\Api\V1\StudentController;
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
    Route::get('classrooms/{classroom}', [ClassroomController::class, 'show']);

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

    // Студенты и регистрация лиц
    Route::post  ('students',                   [StudentController::class, 'store']);
    Route::get   ('students/{classroom}',       [StudentController::class, 'index']);
    Route::post  ('students/{student}/photo',   [StudentController::class, 'uploadPhoto']);
    Route::get   ('students/{student}/photo',   [StudentController::class, 'showPhoto']);
    Route::delete('students/{student}/photo',   [StudentController::class, 'deletePhoto']);

    // Админ-операции
    Route::post('admin/reset-dashboard', [AdminController::class, 'resetDashboard']);

    // Заглушки
    Route::get('alerts',          fn() => response()->json(['data' => []]));
    Route::get('alerts/active',   fn() => response()->json(['data' => []]));
    Route::get('recommendations', fn() => response()->json(['data' => []]));

    // Analytics
    Route::get ('analytics/overview',              [AnalyticsController::class, 'overview']);
    Route::get ('analytics/emotions',              [AnalyticsController::class, 'emotions']);
    Route::get ('analytics/heatmap/{classroomId}', [AnalyticsController::class, 'heatmap']);
    Route::get ('analytics/students',              [AnalyticsController::class, 'students']);
    Route::get ('analytics/students/{studentId}',  [AnalyticsController::class, 'student']);
    Route::post('analytics/compare',               [AnalyticsController::class, 'compare']);
});


// ML internal
Route::prefix('internal')->group(function () {
    Route::post('snapshots', [SessionController::class, 'receiveSnapshots']);
});
