<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\SessionService;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SessionService::class);
    }

    public function boot(): void
    {
        //
    }
}
