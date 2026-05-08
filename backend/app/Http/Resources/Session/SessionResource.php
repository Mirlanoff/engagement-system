<?php

namespace App\Http\Resources\Session;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;

class SessionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                   => $this->id,
            'classroom_id'         => $this->classroom_id,
            'classroom_name'       => $this->classroom?->name,
            'teacher_id'           => $this->teacher_id,
            'teacher_name'         => $this->teacher?->name,
            'subject'              => $this->subject,
            'status'               => $this->status,
            'started_at'           => $this->started_at?->toIso8601String(),
            'ended_at'             => $this->ended_at?->toIso8601String(),
            'duration_minutes'     => $this->duration_minutes,
            'avg_engagement_score' => $this->avg_engagement_score,
            'min_engagement_score' => $this->min_engagement_score,
            'max_engagement_score' => $this->max_engagement_score,
            'students_count'       => $this->students_count,    // ростер класса
            'students_present'     => $this->resolveStudentsPresent(),
            'live_avg_score'       => $this->resolveLiveAvg(),
            'total_snapshots'      => $this->total_snapshots,
            'engagement_level'     => $this->engagement_level,
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Сколько лиц обнаружено в последнем кадре активного урока.
     */
    private function resolveStudentsPresent(): int
    {
        if ($this->status !== 'active') {
            return 0;
        }

        $latest = DB::table('engagement_snapshots')
            ->where('session_id', $this->id)
            ->orderByDesc('captured_at')
            ->limit(1)
            ->value('captured_at');

        if (!$latest) {
            return 0;
        }

        return (int) DB::table('engagement_snapshots')
            ->where('session_id', $this->id)
            ->where('captured_at', $latest)
            ->where('face_detected', true)
            ->distinct()
            ->count('student_id');
    }

    /**
     * Средний балл по последнему кадру (только присутствующие).
     */
    private function resolveLiveAvg(): ?float
    {
        if ($this->status !== 'active') {
            return null;
        }

        $latest = DB::table('engagement_snapshots')
            ->where('session_id', $this->id)
            ->orderByDesc('captured_at')
            ->limit(1)
            ->value('captured_at');

        if (!$latest) {
            return null;
        }

        $avg = DB::table('engagement_snapshots')
            ->where('session_id', $this->id)
            ->where('captured_at', $latest)
            ->where('face_detected', true)
            ->avg('engagement_score');

        return $avg !== null ? round((float) $avg, 2) : null;
    }
}
