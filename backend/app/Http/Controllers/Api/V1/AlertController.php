<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\EngagementAlert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $alerts = $this->baseQuery($request)
            ->when($request->filled('session_id'), fn ($query) => $query->where('session_id', (string) $request->string('session_id')))
            ->when($request->filled('classroom_id'), fn ($query) => $query->where('classroom_id', (string) $request->string('classroom_id')))
            ->when($request->filled('student_id'), fn ($query) => $query->where('student_id', (string) $request->string('student_id')))
            ->when($request->filled('type'), fn ($query) => $query->where('type', (string) $request->string('type')))
            ->when($request->filled('severity'), fn ($query) => $query->where('severity', (string) $request->string('severity')))
            ->when($request->boolean('active'), fn ($query) => $query->where('is_acknowledged', false))
            ->latest('triggered_at')
            ->paginate((int) $request->integer('per_page', 50));

        return response()->json([
            'data' => $alerts->getCollection()->map->toDashboardPayload()->values(),
            'meta' => [
                'current_page' => $alerts->currentPage(),
                'last_page'    => $alerts->lastPage(),
                'per_page'     => $alerts->perPage(),
                'total'        => $alerts->total(),
            ],
        ]);
    }

    public function active(Request $request): JsonResponse
    {
        $alerts = $this->baseQuery($request)
            ->where('is_acknowledged', false)
            ->latest('triggered_at')
            ->limit(100)
            ->get();

        return response()->json(['data' => $alerts->map->toDashboardPayload()->values()]);
    }

    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $alert = $this->baseQuery($request)->findOrFail($id);
        $alert->update([
            'is_acknowledged'      => true,
            'acknowledged_by'      => $request->user()->id,
            'acknowledged_at'      => now(),
            'acknowledgement_note' => $data['note'] ?? null,
        ]);

        return response()->json(['data' => $alert->fresh(['classroom', 'student'])->toDashboardPayload()]);
    }

    private function baseQuery(Request $request)
    {
        return EngagementAlert::query()
            ->with(['classroom', 'student'])
            ->whereHas('classroom', function ($query) use ($request) {
                $query->where('school_id', $request->user()->school_id);
            });
    }
}
