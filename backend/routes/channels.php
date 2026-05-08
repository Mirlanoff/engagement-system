<?php

use App\Models\LessonSession;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('school.{schoolId}', function (User $user, string $schoolId) {
    return (string) $user->school_id === (string) $schoolId;
});

Broadcast::channel('session.{sessionId}', function (User $user, string $sessionId) {
    $session = LessonSession::with('classroom')->find($sessionId);

    if ($session === null || (string) $session->classroom?->school_id !== (string) $user->school_id) {
        return false;
    }

    return [
        'id'   => $user->id,
        'name' => $user->name,
        'role' => $user->role,
    ];
});
