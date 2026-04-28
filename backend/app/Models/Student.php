<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class Student extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'school_id', 'name', 'student_code', 'birth_date',
        'face_encoding_path', 'consent_given', 'consent_given_at', 'is_active',
    ];

    protected $casts = [
        'birth_date'       => 'date',
        'consent_given'    => 'boolean',
        'consent_given_at' => 'datetime',
        'is_active'        => 'boolean',
    ];

    public function school()
    {
        return $this->belongsTo(School::class);
    }

    public function classrooms()
    {
        return $this->belongsToMany(Classroom::class, 'classroom_student')
            ->withPivot('seat_number', 'enrolled_at', 'left_at');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
