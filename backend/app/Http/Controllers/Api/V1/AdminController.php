<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\DashboardResetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function __construct(
        private readonly DashboardResetService $resetService,
    ) {}

    // POST /api/v1/admin/reset-dashboard
    public function resetDashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!in_array($user?->role, ['admin', 'supervisor'], true)) {
            return response()->json([
                'message' => 'Недостаточно прав. Очистка дашборда доступна только администраторам и супервайзерам.',
            ], 403);
        }

        $validated = $request->validate([
            'keep_completed' => 'sometimes|boolean',
        ]);

        $stats = $this->resetService->reset(
            keepCompleted: (bool) ($validated['keep_completed'] ?? false),
        );

        return response()->json([
            'status'  => 'reset',
            'deleted' => $stats,
            'message' => 'Данные дашборда очищены.',
        ]);
    }
}
