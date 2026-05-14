<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Infrastructure\ML\MlServiceClient;
use App\Models\Classroom;
use App\Models\Student;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudentController extends Controller
{
    private const PHOTO_DIR    = 'students';
    private const MAX_SIZE     = 640;
    private const ROUTE_PREFIX = 'api/v1/students';

    public function __construct(
        private readonly MlServiceClient $mlClient,
    ) {}

    // POST /api/v1/students
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'name'         => ['required', 'string', 'max:100'],
            'surname'      => ['required', 'string', 'max:100'],
            'classroom_id' => ['required', 'string', 'exists:classrooms,id'],
        ]);

        $classroom = Classroom::findOrFail($request->input('classroom_id'));

        $student = Student::create([
            'name'      => $request->input('name') . ' ' . $request->input('surname'),
            'school_id' => $classroom->school_id,
            'is_active' => true,
        ]);

        // Привязываем студента к классу
        $student->classrooms()->attach($classroom->id, [
            'id'          => \Illuminate\Support\Str::uuid()->toString(),
            'enrolled_at' => now(),
        ]);

        return response()->json([
            'data' => [
                'id'              => $student->id,
                'name'            => $student->name,
                'face_registered' => false,
                'photo_url'       => null,
            ],
        ], 201);
    }

    // GET /api/v1/students/{classroom}
    public function index(Request $request, string $classroomId): JsonResponse
    {
        $classroom = Classroom::find($classroomId);

        if (!$classroom) {
            return response()->json(['message' => 'Класс не найден'], 404);
        }

        $students = $classroom->students()
            ->orderBy('students.name')
            ->get()
            ->map(fn(Student $s) => [
                'id'                  => $s->id,
                'name'                => $s->name,
                'student_code'        => $s->student_code,
                'face_registered'     => (bool) $s->face_registered,
                'face_registered_at'  => $s->face_registered_at?->toIso8601String(),
                'photo_url'           => $s->photo_path
                    ? '/' . self::ROUTE_PREFIX . '/' . $s->id . '/photo'
                    : null,
            ]);

        return response()->json(['data' => $students]);
    }

    // POST /api/v1/students/{student}/photo
    public function uploadPhoto(Request $request, string $studentId): JsonResponse
    {
        $student = Student::find($studentId);
        if (!$student) {
            return response()->json(['message' => 'Студент не найден'], 404);
        }

        $request->validate([
            'photo' => ['required', 'file', 'mimes:jpeg,jpg,png', 'max:5120'],
        ]);

        $uploaded = $request->file('photo');
        $rawBytes = file_get_contents($uploaded->getRealPath());
        if ($rawBytes === false) {
            return response()->json(['message' => 'Не удалось прочитать файл'], 422);
        }

        $jpegBytes = $this->resizeToJpeg($rawBytes, self::MAX_SIZE);
        if ($jpegBytes === null) {
            return response()->json(['message' => 'Не удалось обработать изображение'], 422);
        }

        $relativePath = self::PHOTO_DIR . DIRECTORY_SEPARATOR . $student->id . '.jpg';
        $absolutePath = storage_path('app' . DIRECTORY_SEPARATOR . $relativePath);
        $dir          = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            Log::error('Failed to create student photos directory', ['dir' => $dir]);
            return response()->json(['message' => 'Не удалось сохранить файл'], 500);
        }
        if (file_put_contents($absolutePath, $jpegBytes) === false) {
            Log::error('Failed to write student photo', ['path' => $absolutePath]);
            return response()->json(['message' => 'Не удалось сохранить файл'], 500);
        }

        $student->photo_path = $relativePath;
        $student->save();

        $photoUrl = '/' . self::ROUTE_PREFIX . '/' . $student->id . '/photo';

        $mlResponse = $this->mlClient->generateEmbedding(
            studentId: $student->id,
            imageB64:  base64_encode($jpegBytes),
        );

        if (!is_array($mlResponse)) {
            $student->face_embedding     = null;
            $student->face_registered    = false;
            $student->face_registered_at = null;
            $student->save();

            return response()->json([
                'status'          => 'error',
                'message'         => 'ML сервис недоступен',
                'photo_url'       => $photoUrl,
                'face_registered' => false,
            ], 502);
        }

        $facesCount = (int) ($mlResponse['faces_count'] ?? 0);
        $embedding  = $mlResponse['embedding'] ?? null;

        if (($mlResponse['status'] ?? null) === 'ok' && $facesCount === 1 && is_array($embedding)) {
            $student->face_embedding     = $embedding;
            $student->face_registered    = true;
            $student->face_registered_at = Carbon::now();
            $student->save();

            return response()->json([
                'status'          => 'ok',
                'photo_url'       => $photoUrl,
                'face_registered' => true,
            ]);
        }

        $student->face_embedding     = null;
        $student->face_registered    = false;
        $student->face_registered_at = null;
        $student->save();

        $message = $mlResponse['message']
            ?? ($facesCount === 0 ? 'Лицо не найдено' : 'Обнаружено несколько лиц');

        return response()->json([
            'status'          => 'error',
            'message'         => $message,
            'photo_url'       => $photoUrl,
            'face_registered' => false,
            'faces_count'     => $facesCount,
        ], 422);
    }

    // GET /api/v1/students/{student}/photo
    public function showPhoto(string $studentId): BinaryFileResponse|JsonResponse
    {
        $student = Student::find($studentId);
        if (!$student || !$student->photo_path) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        $absolutePath = storage_path('app' . DIRECTORY_SEPARATOR . $student->photo_path);
        if (!is_file($absolutePath)) {
            return response()->json(['message' => 'Фото не найдено'], 404);
        }

        return response()->file($absolutePath, [
            'Content-Type'  => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    // DELETE /api/v1/students/{student}/photo
    public function deletePhoto(string $studentId): JsonResponse
    {
        $student = Student::find($studentId);
        if (!$student) {
            return response()->json(['message' => 'Студент не найден'], 404);
        }

        if ($student->photo_path) {
            $absolutePath = storage_path('app' . DIRECTORY_SEPARATOR . $student->photo_path);
            if (is_file($absolutePath)) {
                @unlink($absolutePath);
            }
        }

        $student->photo_path         = null;
        $student->face_embedding     = null;
        $student->face_registered    = false;
        $student->face_registered_at = null;
        $student->save();

        return response()->json([
            'status'          => 'ok',
            'face_registered' => false,
            'photo_url'       => null,
        ]);
    }

    /**
     * Decode an image (any GD-supported format) and re-encode as a JPEG that
     * fits within $maxSize x $maxSize, preserving aspect ratio. Returns null
     * on decode failure.
     */
    private function resizeToJpeg(string $bytes, int $maxSize): ?string
    {
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }

        $srcW = imagesx($src);
        $srcH = imagesy($src);

        if ($srcW <= $maxSize && $srcH <= $maxSize) {
            $dst = $src;
        } else {
            $ratio = min($maxSize / $srcW, $maxSize / $srcH);
            $dstW  = max(1, (int) round($srcW * $ratio));
            $dstH  = max(1, (int) round($srcH * $ratio));

            $dst = imagecreatetruecolor($dstW, $dstH);
            // White background for any transparent PNGs flattened to JPEG.
            $white = imagecolorallocate($dst, 255, 255, 255);
            imagefilledrectangle($dst, 0, 0, $dstW, $dstH, $white);
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            imagedestroy($src);
        }

        ob_start();
        imagejpeg($dst, null, 90);
        $jpeg = ob_get_clean();
        imagedestroy($dst);

        return $jpeg !== false ? $jpeg : null;
    }
}
