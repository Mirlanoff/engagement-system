<?php

namespace App\Domain\Recommendation\Services;

use App\Domain\Engagement\Models\EngagementSnapshot;
use App\Domain\Engagement\Services\EngagementAggregatorService;
use App\Domain\Recommendation\Models\AiRecommendation;
use App\Domain\Session\Models\LessonSession;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiRecommendationService
{
    private const MODEL = 'claude-sonnet-4-20250514';

    private const API_URL = 'https://api.anthropic.com/v1/messages';

    public function __construct(
        private readonly EngagementAggregatorService $aggregator,
    ) {}

    // ── Post-lesson рекомендация (генерируется после завершения урока) ──

    public function generatePostLessonRecommendation(LessonSession $session): void
    {
        dispatch(function () use ($session) {
            $this->doGenerate($session, 'post_lesson_summary');
        })->afterResponse();
    }

    public function generateRealtimeSuggestion(LessonSession $session, float $currentScore): void
    {
        // Только если Score упал ниже 45 и последняя рекомендация была > 10 минут назад
        $lastSuggestion = AiRecommendation::where('session_id', $session->id)
            ->where('type', 'realtime_suggestion')
            ->latest()
            ->first();

        if ($lastSuggestion && $lastSuggestion->created_at->diffInMinutes(now()) < 10) {
            return;
        }

        dispatch(function () use ($session) {
            $this->doGenerate($session, 'realtime_suggestion');
        })->afterResponse();
    }

    // ── Еженедельный анализ (запускается через Scheduler) ──────────

    public function generateWeeklyAnalysis(string $classroomId): void
    {
        // TODO: реализовать через Command
    }

    // ── Основная логика генерации ────────────────────────────────

    private function doGenerate(LessonSession $session, string $type): void
    {
        $session->load(['classroom.students', 'teacher']);

        $stats = $this->aggregator->calculateSessionStats($session);
        $timeline = $stats['timeline'];

        // Топ-3 проблемных студента по средней вовлечённости
        $weakStudents = EngagementSnapshot::forSession($session->id)
            ->selectRaw('student_id, AVG(engagement_score) as avg, MODE() WITHIN GROUP (ORDER BY emotion) as dominant_emotion')
            ->groupBy('student_id')
            ->orderBy('avg')
            ->limit(3)
            ->get();

        $prompt = $this->buildPrompt($type, $session, $stats, $timeline, $weakStudents);
        $apiKey = config('services.claude.api_key');

        if (! $apiKey) {
            Log::warning('Claude API key is not configured; skipping AI recommendation', [
                'session_id' => $session->id,
                'type' => $type,
            ]);

            return;
        }

        try {
            $response = Http::withHeaders([
                'x-api-key' => $apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])->timeout(30)->post(self::API_URL, [
                'model' => self::MODEL,
                'max_tokens' => 1000,
                'system' => $this->getSystemPrompt(),
                'messages' => [
                    ['role' => 'user', 'content' => $prompt],
                ],
            ]);

            if ($response->failed()) {
                Log::error('Claude API error', ['status' => $response->status(), 'body' => $response->body()]);

                return;
            }

            $content = $response->json('content.0.text');
            $parsed = $this->parseStructuredResponse($content);

            AiRecommendation::create([
                'session_id' => $session->id,
                'generated_for' => $session->teacher_id,
                'type' => $type,
                'content' => $parsed['content'],
                'key_insights' => $parsed['insights'],
                'action_items' => $parsed['actions'],
                'session_avg_score' => $stats['avg'],
                'input_data_summary' => [
                    'avg' => $stats['avg'],
                    'min' => $stats['min'],
                    'max' => $stats['max'],
                    'duration' => $session->duration_minutes,
                    'students' => $session->students_count,
                ],
                'model_used' => self::MODEL,
                'tokens_used' => $response->json('usage.output_tokens'),
            ]);

            Log::info('AI recommendation generated', [
                'session_id' => $session->id,
                'type' => $type,
            ]);

        } catch (\Throwable $e) {
            Log::error('Failed to generate AI recommendation', [
                'session_id' => $session->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function getSystemPrompt(): string
    {
        return <<<'PROMPT'
Ты — педагогический аналитик системы мониторинга вовлечённости студентов.
Анализируешь данные с камер и выдаёшь конкретные, практичные рекомендации учителям.
Отвечай на русском языке. Будь конкретным, избегай общих фраз.
Структурируй ответ строго в JSON формате — никакого текста вне JSON.
PROMPT;
    }

    private function buildPrompt(
        string $type,
        LessonSession $session,
        array $stats,
        array $timeline,
        $weakStudents
    ): string {
        $subject = $session->subject ?? 'не указан';
        $duration = $session->duration_minutes ?? 0;
        $weakList = $weakStudents->map(fn ($s) => [
            'avg_score' => round($s->avg, 1),
            'emotion' => $s->dominant_emotion,
        ])->toArray();

        // Находим самую низкую минуту урока
        $lowestMinute = collect($timeline)->sortBy('avg_score')->first();

        return <<<PROMPT
Тип анализа: {$type}
Предмет: {$subject}
Длительность урока: {$duration} минут
Количество студентов: {$session->students_count}

Статистика вовлечённости:
- Средний балл: {$stats['avg']}%
- Минимальный: {$stats['min']}%
- Максимальный: {$stats['max']}%
- Отклонение: {$stats['std_dev']}%

Самая низкая точка урока: минута {$lowestMinute['minute']} — {$lowestMinute['avg_score']}%

Студенты с низкой вовлечённостью (анонимизировано):
PROMPT.json_encode($weakList, JSON_UNESCAPED_UNICODE).<<<'PROMPT'


Верни ТОЛЬКО JSON в формате:
{
  "content": "Подробный анализ в Markdown (2-3 абзаца)",
  "insights": ["инсайт 1", "инсайт 2", "инсайт 3"],
  "actions": [
    {"priority": "high", "action": "конкретное действие"},
    {"priority": "medium", "action": "конкретное действие"}
  ]
}
PROMPT;
    }

    private function parseStructuredResponse(string $content): array
    {
        try {
            $clean = preg_replace('/```json|```/', '', $content);
            $data = json_decode(trim($clean), true, flags: JSON_THROW_ON_ERROR);

            return [
                'content' => $data['content'] ?? $content,
                'insights' => $data['insights'] ?? [],
                'actions' => $data['actions'] ?? [],
            ];
        } catch (\Throwable) {
            return ['content' => $content, 'insights' => [], 'actions' => []];
        }
    }
}
