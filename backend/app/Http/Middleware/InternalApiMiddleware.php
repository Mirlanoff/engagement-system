<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверяет секретный токен для внутренних запросов от ML сервиса.
 * Использует HMAC-подпись с timestamp для защиты от replay-атак.
 */
class InternalApiMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $signature = $request->header('X-Internal-Signature');
        $timestamp  = $request->header('X-Internal-Timestamp');

        if (!$signature || !$timestamp) {
            return response()->json(['error' => 'Missing internal auth headers'], 401);
        }

        // Проверка: запрос не старше 30 секунд (защита от replay)
        if (abs(time() - (int) $timestamp) > 30) {
            return response()->json(['error' => 'Request timestamp expired'], 401);
        }

        // Проверка HMAC подписи
        $expected = hash_hmac(
            'sha256',
            $timestamp . $request->getContent(),
            config('services.ml_service.secret')
        );

        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid signature'], 401);
        }

        return $next($request);
    }
}
