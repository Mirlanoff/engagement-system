<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

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
        'captured_at' => 'datetime',
        'engagement_score' => 'decimal:2',
        'gaze_score' => 'decimal:2',
        'emotion_score' => 'decimal:2',
        'head_pose_score' => 'decimal:2',
        'presence_score' => 'decimal:2',
        'emotion_confidence' => 'decimal:3',
        'face_detected' => 'boolean',
        'face_confidence' => 'decimal:3',
        'gaze_yaw' => 'float',
        'gaze_pitch' => 'float',
        'head_yaw' => 'float',
        'head_pitch' => 'float',
        'head_roll' => 'float',
        'processing_time_ms' => 'decimal:2',
    ];

    public function scopeForSession($query, string $sessionId)
    {
        return $query->where('session_id', $sessionId);
    }

    public function scopeForStudent($query, string $studentId)
    {
        return $query->where('student_id', $studentId);
    }

    public function session()
    {
        return $this->belongsTo(LessonSession::class, 'session_id');
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }
}
