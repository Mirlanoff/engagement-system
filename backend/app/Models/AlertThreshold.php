<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AlertThreshold extends Model
{
    use HasUuids;

    protected $fillable = [
        'school_id', 'classroom_id',
        'low_class_threshold', 'low_student_threshold',
        'absent_minutes_threshold', 'prolonged_low_minutes',
        'rapid_decline_delta', 'rapid_decline_window_seconds',
        'notify_supervisor', 'notify_teacher', 'sound_alert',
    ];

    protected $casts = [
        'low_class_threshold' => 'decimal:2',
        'low_student_threshold' => 'decimal:2',
        'rapid_decline_delta' => 'decimal:2',
        'notify_supervisor' => 'boolean',
        'notify_teacher' => 'boolean',
        'sound_alert' => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }
}
