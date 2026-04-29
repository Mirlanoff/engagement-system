<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EngagementAggregate extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id', 'classroom_id', 'minute_at', 'interval_minutes',
        'avg_score', 'min_score', 'max_score', 'std_dev',
        'students_detected', 'snapshots_count',
        'high_engagement_count', 'medium_engagement_count', 'low_engagement_count',
    ];

    protected $casts = [
        'minute_at' => 'datetime',
        'avg_score' => 'decimal:2',
        'min_score' => 'decimal:2',
        'max_score' => 'decimal:2',
        'std_dev' => 'decimal:2',
    ];

    public function session()
    {
        return $this->belongsTo(LessonSession::class, 'session_id');
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }
}
