<?php

namespace App\Console\Commands;

use App\Services\DashboardResetService;
use Illuminate\Console\Command;

class EngagementResetCommand extends Command
{
    protected $signature = 'engagement:reset
                            {--force : Не запрашивать подтверждение}
                            {--keep-completed : Оставить завершённые уроки в истории}';

    protected $description = 'Очистить все данные дашборда (сессии, снэпшоты, агрегаты, алерты, рекомендации). Студенты/классы/школы остаются.';

    public function handle(DashboardResetService $service): int
    {
        $keepCompleted = (bool) $this->option('keep-completed');

        if (!$this->option('force')) {
            $this->warn($keepCompleted
                ? 'Будут очищены только активные/приостановленные уроки и live-данные.'
                : 'Будут очищены ВСЕ уроки, снэпшоты, агрегаты, алерты и AI-рекомендации.');

            if (!$this->confirm('Продолжить?', false)) {
                $this->info('Отменено.');
                return self::SUCCESS;
            }
        }

        $stats = $service->reset(keepCompleted: $keepCompleted);

        $this->info('Готово. Удалено:');
        foreach ($stats as $table => $count) {
            $this->line(sprintf('  %-28s %d', $table, $count));
        }

        return self::SUCCESS;
    }
}
