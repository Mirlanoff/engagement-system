<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EngagementAlert extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id',
        'classroom_id',
        'student_id',
        'type',
        'severity',
        'trigger_score',
        'threshold_score',
        'message',
        'context',
        'is_acknowledged',
        'acknowledged_by',
        'acknowledged_at',
        'acknowledgement_note',
        'triggered_at',
    ];

    protected $casts = [
        'context'          => 'array',
        'is_acknowledged'  => 'boolean',
        'trigger_score'    => 'decimal:2',
        'threshold_score'  => 'decimal:2',
        'acknowledged_at'  => 'datetime',
        'triggered_at'     => 'datetime',
    ];

    public function session()
    {
        return $this->belongsTo(LessonSession::class, 'session_id');
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }

    public function toDashboardPayload(): array
    {
        return [
            'alert_id'          => $this->id,
            'id'                => $this->id,
            'session_id'        => $this->session_id,
            'classroom_id'      => $this->classroom_id,
            'classroom_name'    => $this->classroom?->name,
            'student_id'        => $this->student_id,
            'student_name'      => $this->student?->name,
            'type'              => $this->type,
            'severity'          => $this->severity,
            'message'           => $this->message,
            'score'             => $this->trigger_score === null ? null : (float) $this->trigger_score,
            'trigger_score'     => $this->trigger_score === null ? null : (float) $this->trigger_score,
            'threshold_score'   => $this->threshold_score === null ? null : (float) $this->threshold_score,
            'context'           => $this->context ?? [],
            'is_acknowledged'   => $this->is_acknowledged,
            'acknowledged_at'   => $this->acknowledged_at?->toIso8601String(),
            'triggered_at'      => $this->triggered_at?->toIso8601String(),
        ];
    }
}
