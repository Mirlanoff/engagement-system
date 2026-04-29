<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class LessonSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'classroom_id', 'teacher_id', 'subject', 'status',
        'started_at', 'ended_at',
        'avg_engagement_score', 'min_engagement_score', 'max_engagement_score',
        'total_snapshots', 'students_count',
        'engagement_timeline', 'meta',
    ];

    protected $casts = [
        'started_at'           => 'datetime',
        'ended_at'             => 'datetime',
        'avg_engagement_score' => 'decimal:2',
        'engagement_timeline'  => 'array',
        'meta'                 => 'array',
    ];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function teacher()
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForClassroom($query, string $classroomId)
    {
        return $query->where('classroom_id', $classroomId);
    }

    public function snapshots()
    {
        return $this->hasMany(EngagementSnapshot::class, 'session_id');
    }

    public function aggregates()
    {
        return $this->hasMany(EngagementAggregate::class, 'session_id');
    }

    public function alerts()
    {
        return $this->hasMany(EngagementAlert::class, 'session_id');
    }

    public function recommendations()
    {
        return $this->hasMany(AiRecommendation::class, 'session_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }
    // Длительность в минутах
    public function getDurationMinutesAttribute(): ?int
    {
        if (!$this->started_at || !$this->ended_at) return null;
        return (int) $this->started_at->diffInMinutes($this->ended_at);
    }

    // Уровень вовлечённости
    public function getEngagementLevelAttribute(): string
    {
        $score = $this->avg_engagement_score ?? 0;
        return match(true) {
            $score >= 75 => 'high',
            $score >= 50 => 'medium',
            default      => 'low',
        };
    }
}
