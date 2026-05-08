<?php

namespace App\Services;

use App\Events\DashboardReset;
use App\Models\LessonSession;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class DashboardResetService
{
    /**
     * Очищает live-данные дашборда. Реальные сущности (школы, классы,
     * студенты, пользователи, alert_thresholds) не трогаем.
     *
     * @return array<string, int> карта таблица → удалено строк
     */
    public function reset(bool $keepCompleted = false): array
    {
        // Список активных уроков нужен заранее — после очистки broadcast по
        // их каналам станет «no-op», но фронт уже подписан и должен получить
        // сигнал «сбросить локальное состояние».
        $sessionIds = LessonSession::query()
            ->when($keepCompleted, fn ($q) => $q->whereIn('status', ['active', 'paused']))
            ->pluck('id')
            ->all();

        $stats = DB::transaction(function () use ($keepCompleted) {
            $deleted = [];

            // Снэпшоты и агрегаты — всегда чистим полностью, они привязаны
            // к сессиям, в которые мы либо удаляем, либо в которых будет новый
            // запуск. Хранить «сиротские» снэпшоты бессмысленно.
            $deleted['engagement_snapshots']  = DB::table('engagement_snapshots')->delete();
            $deleted['engagement_aggregates'] = DB::table('engagement_aggregates')->delete();
            $deleted['engagement_alerts']     = DB::table('engagement_alerts')->delete();
            $deleted['ai_recommendations']    = DB::table('ai_recommendations')->delete();

            if ($keepCompleted) {
                // Только уроки в полёте: completed/cancelled остаются в истории.
                $deleted['lesson_sessions'] = LessonSession::query()
                    ->whereIn('status', ['active', 'paused'])
                    ->delete();
            } else {
                $deleted['lesson_sessions'] = DB::table('lesson_sessions')->delete();
            }

            return $deleted;
        });

        $this->forgetAlertCooldowns($sessionIds);

        try {
            broadcast(new DashboardReset($sessionIds, $keepCompleted));
        } catch (\Throwable $e) {
            Log::warning('DashboardReset broadcast failed', ['error' => $e->getMessage()]);
        }

        Log::info('Dashboard reset', [
            'keep_completed' => $keepCompleted,
            'deleted'        => $stats,
            'sessions'       => count($sessionIds),
        ]);

        return $stats;
    }

    private function forgetAlertCooldowns(array $sessionIds): void
    {
        foreach ($sessionIds as $sessionId) {
            Cache::forget("alert:low_class:{$sessionId}");
        }
    }
}
