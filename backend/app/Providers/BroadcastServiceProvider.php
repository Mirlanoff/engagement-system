<?php

namespace App\Providers;

use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;

class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Регистрируем POST /api/v1/broadcasting/auth с auth:sanctum,
        // чтобы Echo на фронте мог авторизовать подписку на private/presence
        // каналы по тому же Bearer-токену, который выдаёт логин.
        Broadcast::routes([
            'prefix'     => 'api/v1',
            'middleware' => ['auth:sanctum'],
        ]);

        require base_path('routes/channels.php');
    }
}
