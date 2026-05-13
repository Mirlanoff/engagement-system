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
        'face_encoding_path', 'face_embedding', 'photo_path',
        'face_registered', 'face_registered_at',
        'consent_given', 'consent_given_at', 'is_active',
    ];

    protected $casts = [
        'birth_date'         => 'date',
        'face_embedding'     => 'array',
        'face_registered'    => 'boolean',
        'face_registered_at' => 'datetime',
        'consent_given'      => 'boolean',
        'consent_given_at'   => 'datetime',
        'is_active'          => 'boolean',
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
