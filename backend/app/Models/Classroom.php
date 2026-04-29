<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Classroom extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'school_id', 'name', 'code', 'capacity',
        'camera_config', 'detection_zones', 'settings', 'is_active',
    ];

    protected $casts = [
        'camera_config'   => 'array',
        'detection_zones' => 'array',
        'settings'        => 'array',
        'is_active'       => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function students()
    {
        return $this->belongsToMany(Student::class, 'classroom_student')
            ->withPivot('seat_number', 'enrolled_at', 'left_at')
            ->wherePivotNull('left_at');
    }

    public function sessions()
    {
        return $this->hasMany(LessonSession::class);
    }

    public function alertThresholds()
    {
        return $this->hasMany(AlertThreshold::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
