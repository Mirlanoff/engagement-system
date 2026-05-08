<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AlertThreshold extends Model
{
    use HasUuids;

    protected $fillable = [
        'school_id',
        'classroom_id',
        'low_class_threshold',
        'low_student_threshold',
        'absent_minutes_threshold',
        'prolonged_low_minutes',
        'rapid_decline_delta',
        'rapid_decline_window_seconds',
        'notify_supervisor',
        'notify_teacher',
        'sound_alert',
    ];

    protected $casts = [
        'low_class_threshold'          => 'decimal:2',
        'low_student_threshold'        => 'decimal:2',
        'rapid_decline_delta'          => 'decimal:2',
        'notify_supervisor'            => 'boolean',
        'notify_teacher'               => 'boolean',
        'sound_alert'                  => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function classroom()
    {
        return $this->belongsTo(Classroom::class);
    }

    public static function resolveFor(Classroom $classroom): self
    {
        $threshold = static::where('school_id', $classroom->school_id)
            ->where('classroom_id', $classroom->id)
            ->first();

        if ($threshold !== null) {
            return $threshold;
        }

        $threshold = static::where('school_id', $classroom->school_id)
            ->whereNull('classroom_id')
            ->first();

        if ($threshold !== null) {
            return $threshold;
        }

        return new self([
            'school_id'                       => $classroom->school_id,
            'classroom_id'                    => $classroom->id,
            'low_class_threshold'             => 50.00,
            'low_student_threshold'           => 40.00,
            'absent_minutes_threshold'        => 3,
            'prolonged_low_minutes'           => 10,
            'rapid_decline_delta'             => 25.00,
            'rapid_decline_window_seconds'    => 60,
            'notify_supervisor'               => true,
            'notify_teacher'                  => true,
            'sound_alert'                     => false,
        ]);
    }
}
