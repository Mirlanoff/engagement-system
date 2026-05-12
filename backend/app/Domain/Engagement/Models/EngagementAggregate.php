<?php

namespace App\Domain\Engagement\Models;

use Illuminate\Database\Eloquent\Model;

class EngagementAggregate extends Model
{
    protected $table = 'engagement_aggregates';

    public $incrementing = false;
    protected $keyType   = 'string';

    protected $fillable = [
        'session_id',
        'classroom_id',
        'minute_at',
        'interval_minutes',
        'avg_score',
        'min_score',
        'max_score',
        'std_dev',
        'students_detected',
        'snapshots_count',
        'high_engagement_count',
        'medium_engagement_count',
        'low_engagement_count',
    ];

    protected $casts = [
        'minute_at'        => 'datetime',
        'interval_minutes' => 'integer',
        'avg_score'        => 'float',
        'min_score'        => 'float',
        'max_score'        => 'float',
        'std_dev'          => 'float',
    ];

    protected static function booted(): void
    {
        static::creating(function ($agg) {
            if (empty($agg->id)) {
                $agg->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }
}
