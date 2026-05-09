<?php

namespace App\Console\Commands;

use App\Domain\Recommendation\Services\AiRecommendationService;
use App\Models\Classroom;
use Illuminate\Console\Command;

class GenerateWeeklyRecommendationsCommand extends Command
{
    protected $signature = 'recommendations:weekly
                            {--classroom= : UUID конкретного класса (по умолчанию — все активные)}';

    protected $description = 'Сгенерировать еженедельный AI-анализ по всем активным классам через локальный Ollama';

    public function handle(AiRecommendationService $service): int
    {
        $query = Classroom::query();
        if ($id = $this->option('classroom')) {
            $query->where('id', $id);
        } else {
            // Берём только классы у которых были снэпшоты за прошедшую неделю
            $query->whereExists(function ($sub) {
                $sub->select(\DB::raw(1))
                    ->from('engagement_snapshots')
                    ->whereColumn('engagement_snapshots.classroom_id', 'classrooms.id')
                    ->where('captured_at', '>=', now()->subWeek());
            });
        }

        $classrooms = $query->get();
        $this->info("Найдено классов для анализа: {$classrooms->count()}");

        foreach ($classrooms as $classroom) {
            $this->line("→ {$classroom->id} ({$classroom->name})");
            try {
                $service->generateWeeklyAnalysis($classroom->id);
            } catch (\Throwable $e) {
                $this->error("  Ошибка: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
