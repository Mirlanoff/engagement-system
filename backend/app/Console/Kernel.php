<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected function schedule(Schedule $schedule): void
    {
        // ── Ежедневный бэкап БД в 02:00 ─────────────────────────
        $schedule->command('db:backup')
            ->dailyAt('02:00')
            ->withoutOverlapping()
            ->onFailure(fn () => \Log::error('Daily DB backup failed'));

        // ── Еженедельные AI-отчёты по каждому классу (понедельник 08:00) ─
        // Локальная Ollama-модель тяжёлая, поэтому держим вне учебных часов.
        $schedule->command('recommendations:weekly')
            ->weeklyOn(1, '08:00')
            ->withoutOverlapping()
            ->onFailure(fn () => \Log::error('Weekly recommendations job failed'));

        // ── Очистка старых снэпшотов (> 90 дней) в 03:00 ────────
        $schedule->command('engagement:prune --days=90')
            ->dailyAt('03:00')
            ->withoutOverlapping();

        // ── Агрегация данных за прошлый день (4:00) ─────────────
        $schedule->command('analytics:aggregate --date=yesterday')
            ->dailyAt('04:00')
            ->withoutOverlapping();

        // ── Horizon snapshot для метрик ──────────────────────────
        $schedule->command('horizon:snapshot')->everyFiveMinutes();
    }

    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
