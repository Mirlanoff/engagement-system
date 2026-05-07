<?php

use App\Models\LessonSession;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Авторизация подписки на private/presence каналы. Без этих правил
| `/broadcasting/auth` будет отвечать 403 на каждый `echo.private(...)`
| / `echo.join(...)`, и фронт никогда не получит реалтайм-события.
|
*/

// Школа: пользователь видит свою школу. Админы видят все.
Broadcast::channel('school.{schoolId}', function ($user, string $schoolId) {
    if ($user->role === 'admin') {
        return true;
    }
    return $user->school_id === $schoolId;
});

// Сессия урока (presence): пускаем учителя сессии, всех админов/супервайзеров
// той же школы, а также студентов класса. Возвращаем массив = метаданные
// presence-channel (отображаются в `here()`/`joining()` на фронте).
Broadcast::channel('session.{sessionId}', function ($user, string $sessionId) {
    $session = LessonSession::with('classroom')->find($sessionId);
    if (!$session) {
        return false;
    }

    $sameSchool = $session->classroom?->school_id === $user->school_id;
    $isStaff    = in_array($user->role, ['admin', 'supervisor', 'teacher']);

    if (!$sameSchool || !$isStaff) {
        return false;
    }

    return [
        'id'    => $user->id,
        'name'  => $user->name,
        'role'  => $user->role,
    ];
});
