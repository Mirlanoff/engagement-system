<?php

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

// ── Auth ──────────────────────────────────────────────────────────
Route::prefix('v1/auth')->group(function () {
    Route::post('login',  [AuthController::class, 'login']);
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me',      [AuthController::class, 'me']);
        Route::post('logout', [AuthController::class, 'logout']);
    });
});

// ── API v1 ────────────────────────────────────────────────────────
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('sessions/active', fn() => response()->json(['data' => [], 'count' => 0]));
    Route::get('sessions',        fn() => response()->json(['data' => []]));
    Route::get('alerts/active',   fn() => response()->json(['data' => []]));
    Route::get('alerts',          fn() => response()->json(['data' => []]));
    Route::get('recommendations', fn() => response()->json(['data' => []]));
    Route::get('analytics/overview', fn() => response()->json(['data' => []]));
});
