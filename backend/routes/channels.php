<?php

use App\Models\LessonSession;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('school.{schoolId}', function ($user, string $schoolId) {
    return (string) $user->school_id === $schoolId && $user->isSupervisor();
});

Broadcast::channel('session.{sessionId}', function ($user, string $sessionId) {
    return $user !== null && LessonSession::whereKey($sessionId)
        ->whereHas('classroom', fn ($query) => $query->where('school_id', $user->school_id))
        ->exists();
});
