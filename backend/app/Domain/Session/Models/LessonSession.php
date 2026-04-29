<?php

namespace App\Domain\Session\Models;

use App\Models\AiRecommendation;
use App\Models\Classroom;
use App\Models\EngagementAggregate;
use App\Models\EngagementAlert;
use App\Models\EngagementSnapshot;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Builder;
use Carbon\Carbon;

class LessonSession extends Model
{
    use HasUuids;

    protected $fillable = [
        'classroom_id', 'teacher_id', 'subject', 'status',
        'started_at', 'ended_at', 'avg_engagement_score',
        'min_engagement_score', 'max_engagement_score',
        'total_snapshots', 'students_count',
        'engagement_timeline', 'meta',
    ];

    protected $casts = [
        'started_at'          => 'datetime',
        'ended_at'            => 'datetime',
        'avg_engagement_score'=> 'decimal:2',
        'min_engagement_score'=> 'decimal:2',
        'max_engagement_score'=> 'decimal:2',
        'engagement_timeline' => 'array',
        'meta'                => 'array',
    ];

    // ── Relations ───────────────────────────────────────────────

    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }

    public function teacher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'teacher_id');
    }

    public function snapshots(): HasMany
    {
        return $this->hasMany(EngagementSnapshot::class, 'session_id');
    }

    public function aggregates(): HasMany
    {
        return $this->hasMany(EngagementAggregate::class, 'session_id');
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(EngagementAlert::class, 'session_id');
    }

    public function recommendations(): HasMany
    {
        return $this->hasMany(AiRecommendation::class, 'session_id');
    }

    // ── Scopes ──────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', 'completed');
    }

    public function scopeForClassroom(Builder $query, string $classroomId): Builder
    {
        return $query->where('classroom_id', $classroomId);
    }

    public function scopeInPeriod(Builder $query, Carbon $from, Carbon $to): Builder
    {
        return $query->whereBetween('started_at', [$from, $to]);
    }

    public function scopeWithLowEngagement(Builder $query, float $threshold = 50.0): Builder
    {
        return $query->where('avg_engagement_score', '<', $threshold);
    }

    // ── Helpers ─────────────────────────────────────────────────

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isPaused(): bool
    {
        return $this->status === 'paused';
    }

    public function getDurationMinutesAttribute(): ?int
    {
        if (!$this->started_at || !$this->ended_at) {
            return null;
        }
        return (int) $this->started_at->diffInMinutes($this->ended_at);
    }

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
