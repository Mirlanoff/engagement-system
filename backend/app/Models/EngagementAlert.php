<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class EngagementAlert extends Model
{
    use HasUuids;

    protected $fillable = [
        'session_id', 'classroom_id', 'student_id', 'type', 'severity',
        'trigger_score', 'threshold_score', 'message', 'context',
        'is_acknowledged', 'acknowledged_by', 'acknowledged_at',
        'acknowledgement_note', 'triggered_at',
    ];

    protected $casts = [
        'context' => 'array',
        'is_acknowledged' => 'boolean',
        'acknowledged_at' => 'datetime',
        'triggered_at' => 'datetime',
        'trigger_score' => 'decimal:2',
        'threshold_score' => 'decimal:2',
    ];

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function session()
    {
        return $this->belongsTo(LessonSession::class, 'session_id');
    }

    public function acknowledgedBy()
    {
        return $this->belongsTo(User::class, 'acknowledged_by');
    }
}
