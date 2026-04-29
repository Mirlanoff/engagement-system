<?php

namespace App\Http\Resources\Session;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

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
            'students_count'       => $this->students_count,
            'total_snapshots'      => $this->total_snapshots,
            'engagement_level'     => $this->engagement_level,
            'created_at'           => $this->created_at?->toIso8601String(),
        ];
    }
}
