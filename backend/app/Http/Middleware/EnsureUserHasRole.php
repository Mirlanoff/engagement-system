<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Маршрут-уровневая проверка роли пользователя.
 *
 * Использование:
 *   ->middleware('role:admin,supervisor')
 *
 * Аутентификация (auth:sanctum) должна быть применена раньше.
 */
class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['message' => 'Unauthenticated'], 401);
        }

        $userRole = $user->role ?? null;
        if (!in_array($userRole, $roles, strict: true)) {
            return response()->json([
                'message' => 'Forbidden',
                'role'    => $userRole,
            ], 403);
        }

        return $next($request);
    }
}
