<?php

namespace App\Domain\Engagement\Models;

use App\Models\Classroom;
use App\Models\LessonSession;
use App\Models\Student;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Builder;

class EngagementSnapshot extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id', 'student_id', 'classroom_id', 'camera_id',
        'captured_at', 'engagement_score',
        'gaze_score', 'emotion_score', 'head_pose_score', 'presence_score',
        'emotion', 'emotion_confidence',
        'gaze_yaw', 'gaze_pitch',
        'head_yaw', 'head_pitch', 'head_roll',
        'face_detected', 'face_confidence',
        'face_bbox_x', 'face_bbox_y', 'face_bbox_w', 'face_bbox_h',
        'processing_time_ms',
    ];

    protected $casts = [
        'captured_at'          => 'datetime',
        'engagement_score'     => 'decimal:2',
        'gaze_score'           => 'decimal:2',
        'emotion_score'        => 'decimal:2',
        'head_pose_score'      => 'decimal:2',
        'presence_score'       => 'decimal:2',
        'emotion_confidence'   => 'decimal:3',
        'face_detected'        => 'boolean',
        'face_confidence'      => 'decimal:3',
        'gaze_yaw'             => 'float',
        'gaze_pitch'           => 'float',
        'head_yaw'             => 'float',
        'head_pitch'           => 'float',
        'head_roll'            => 'float',
        'processing_time_ms'   => 'decimal:2',
    ];

    // Не нужны created_at/updated_at — только captured_at
    public $timestamps = true;

    // ── Relations ───────────────────────────────────────────────

    public function session(): BelongsTo
    {
        return $this->belongsTo(LessonSession::class, 'session_id');
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    // ── Scopes ──────────────────────────────────────────────────

    public function scopeForSession(Builder $query, string $sessionId): Builder
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeForStudent(Builder $query, string $studentId): Builder
    {
        return $query->where('student_id', $studentId);
    }

    public function scopeLowEngagement(Builder $query, float $threshold = 40.0): Builder
    {
        return $query->where('engagement_score', '<', $threshold);
    }

    public function scopeRecent(Builder $query, int $seconds = 30): Builder
    {
        return $query->where('captured_at', '>=', now()->subSeconds($seconds));
    }

    public function scopeFaceDetected(Builder $query): Builder
    {
        return $query->where('face_detected', true);
    }

    // ── Computed ─────────────────────────────────────────────────

    public function getEngagementLevelAttribute(): string
    {
        return match(true) {
            $this->engagement_score >= 75 => 'high',
            $this->engagement_score >= 50 => 'medium',
            default                        => 'low',
        };
    }

    public function getIsLookingAtBoardAttribute(): bool
    {
        if ($this->gaze_yaw === null || $this->gaze_pitch === null) {
            return false;
        }
        return abs($this->gaze_yaw) < 25 && abs($this->gaze_pitch) < 20;
    }
}
