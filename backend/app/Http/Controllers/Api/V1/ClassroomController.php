<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Classroom;
use App\Models\LessonSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClassroomController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $classrooms = Classroom::where('is_active', true)
            ->withCount('students')
            ->orderBy('name')
            ->get();

        // Get active sessions to mark which classrooms are active
        $activeSessions = LessonSession::where('status', 'active')
            ->pluck('classroom_id')
            ->toArray();

        $data = $classrooms->map(fn($c) => [
            'id'             => $c->id,
            'name'           => $c->name,
            'code'           => $c->code,
            'capacity'       => $c->capacity,
            'head_teacher'   => $c->head_teacher,
            'students_count' => $c->students_count,
            'is_lesson_active' => in_array($c->id, $activeSessions),
        ]);

        return response()->json(['data' => $data]);
    }

    public function show(string $classroomId): JsonResponse
    {
        $classroom = Classroom::with('students')->find($classroomId);

        if (!$classroom) {
            return response()->json(['message' => 'Класс не найден'], 404);
        }

        $students = $classroom->students->map(fn($s) => [
            'id'              => $s->id,
            'name'            => $s->name,
            'face_registered' => (bool) $s->face_registered,
            'photo_url'       => $s->photo_path
                ? '/api/v1/students/' . $s->id . '/photo'
                : null,
        ]);

        return response()->json([
            'data' => [
                'id'             => $classroom->id,
                'name'           => $classroom->name,
                'code'           => $classroom->code,
                'capacity'       => $classroom->capacity,
                'head_teacher'   => $classroom->head_teacher,
                'students_count' => $students->count(),
                'students'       => $students,
            ],
        ]);
    }
}
