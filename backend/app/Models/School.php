<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUuids;

class School extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'name', 'slug', 'address', 'timezone', 'settings', 'is_active',
    ];

    protected $casts = [
        'settings'  => 'array',
        'is_active' => 'boolean',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function classrooms()
    {
        return $this->hasMany(Classroom::class);
    }
}
