<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $classrooms = Classroom::where('is_active', true)
            ->orderBy('name')
            ->get()
            ->map(fn($c) => [
                'id'       => $c->id,
                'name'     => $c->name,
                'code'     => $c->code,
                'capacity' => $c->capacity,
            ]);

        return response()->json(['data' => $classrooms]);
    }
}
