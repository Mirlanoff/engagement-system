<?php

namespace App\Domain\Recommendation\Services;

use App\Domain\Engagement\Services\EngagementAggregatorService;
use App\Domain\Recommendation\Models\AiRecommendation;
use App\Models\Classroom;
use App\Models\LessonSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Генерация педагогических рекомендаций на базе локальной модели Ollama.
 *
 * Вызывается:
 *  - после завершения урока: post_lesson_summary;
 *  - во время урока, если средний балл резко упал: realtime_suggestion;
 *  - по понедельникам утром: weekly_analysis (см. Console\Kernel::schedule).
 */
class AiRecommendationService
{
    public function __construct(
        private readonly OllamaClient $ollama,
        private readonly EngagementAggregatorService $aggregator,
    ) {}

    // ── Public API ───────────────────────────────────────────────

    public function generatePostLessonRecommendation(LessonSession $session): void
    {
        dispatch(function () use ($session) {
            $this->doGenerateForSession($session, 'post_lesson_summary');
        })->afterResponse();
    }

    public function generateRealtimeSuggestion(LessonSession $session): void
    {
        $lastSuggestion = AiRecommendation::query()
            ->where('session_id', $session->id)
            ->where('type', 'realtime_suggestion')
            ->latest()
            ->first();

        if ($lastSuggestion && $lastSuggestion->created_at->diffInMinutes(now()) < 10) {
            return;
        }

        dispatch(function () use ($session) {
            $this->doGenerateForSession($session, 'realtime_suggestion');
        })->afterResponse();
    }

    /**
     * Еженедельный анализ по классу. Вызывается из консольной команды.
     */
    public function generateWeeklyAnalysis(string $classroomId): void
    {
        $classroom = Classroom::find($classroomId);
        if (!$classroom) {
            Log::warning('Weekly analysis: classroom not found', ['classroom_id' => $classroomId]);
            return;
        }

        $from = now()->subWeek()->startOfWeek();
        $to   = now()->subDay()->endOfDay();

        $context = $this->buildWeeklyContext($classroom, $from, $to);
        if (empty($context['n_sessions'])) {
            Log::info('Weekly analysis: no sessions in range', [
                'classroom_id' => $classroomId,
                'from'         => $from->toDateTimeString(),
                'to'           => $to->toDateTimeString(),
            ]);
            return;
        }

        $userPrompt = $this->buildUserPrompt('weekly_analysis', $context);
        $parsed = $this->callOllama($userPrompt);
        if ($parsed === null) {
            return;
        }

        AiRecommendation::create([
            'session_id'         => null,
            'classroom_id'       => $classroom->id,
            'generated_for'      => $classroom->teacher_id ?? $context['teacher_id'],
            'type'               => 'weekly_analysis',
            'content'            => $parsed['content'],
            'key_insights'       => $parsed['insights'],
            'action_items'       => $parsed['actions'],
            'session_avg_score'  => $context['weekly_avg'],
            'input_data_summary' => $context,
            'model_used'         => config('services.ollama.model', 'qwen2.5:7b-instruct'),
            'tokens_used'        => $parsed['tokens'],
        ]);

        Log::info('Weekly AI analysis generated', [
            'classroom_id' => $classroomId,
            'sessions'     => $context['n_sessions'],
        ]);
    }

    // ── Per-session generation ───────────────────────────────────

    private function doGenerateForSession(LessonSession $session, string $type): void
    {
        try {
            $session->loadMissing(['classroom', 'teacher']);

            $stats = $this->aggregator->calculateSessionStats($session);
            $context = $this->buildSessionContext($session, $stats, $type);
            $userPrompt = $this->buildUserPrompt($type, $context);

            $parsed = $this->callOllama($userPrompt);
            if ($parsed === null) {
                return;
            }

            AiRecommendation::create([
                'session_id'         => $session->id,
                'classroom_id'       => $session->classroom_id,
                'generated_for'      => $session->teacher_id,
                'type'               => $type,
                'content'            => $parsed['content'],
                'key_insights'       => $parsed['insights'],
                'action_items'       => $parsed['actions'],
                'session_avg_score'  => $stats['avg'] ?? null,
                'input_data_summary' => $context,
                'model_used'         => config('services.ollama.model', 'qwen2.5:7b-instruct'),
                'tokens_used'        => $parsed['tokens'],
            ]);

            Log::info('AI recommendation generated', [
                'session_id' => $session->id,
                'type'       => $type,
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to generate AI recommendation', [
                'session_id' => $session->id,
                'type'       => $type,
                'error'      => $e->getMessage(),
            ]);
        }
    }

    // ── Ollama call ──────────────────────────────────────────────

    /**
     * Возвращает ['content','insights','actions','tokens'] или null.
     */
    private function callOllama(string $userPrompt): ?array
    {
        try {
            $resp = $this->ollama->chat(
                messages: [
                    ['role' => 'system', 'content' => $this->getSystemPrompt()],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                expectJson: true,
            );
        } catch (\Throwable $e) {
            Log::error('Ollama call failed', ['error' => $e->getMessage()]);
            return null;
        }

        $parsed = $this->parseStructuredResponse($resp['content']);
        return [
            'content'  => $parsed['content'],
            'insights' => $parsed['insights'],
            'actions'  => $parsed['actions'],
            'tokens'   => $resp['eval_count'],
        ];
    }

    // ── Prompts ──────────────────────────────────────────────────

    public function getSystemPrompt(): string
    {
        return <<<PROMPT
Ты — педагогический аналитик системы мониторинга вовлечённости студентов.
Анализируешь данные с одной веб-камеры в классе (5–12 студентов фронтально)
и выдаёшь конкретные, практичные рекомендации учителю на русском языке.

Правила:
- Никаких общих фраз ("надо больше вовлекать"). Только конкретные действия,
  привязанные к данным (минута урока, фамилия/индекс студента, метрика).
- Никогда не диагностируешь учеников клинически. Описываешь поведение
  ("отвлекался", "редко смотрел на доску"), а не личность.
- Если данных мало — честно скажи об этом и предложи продолжить наблюдение.
- Отвечай **строго JSON**, никакого текста до или после, никакого markdown
  с тройными бектиками. Структура указана в запросе.
PROMPT;
    }

    private function buildUserPrompt(string $type, array $ctx): string
    {
        $jsonContext = json_encode($ctx, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return match ($type) {
            'realtime_suggestion'  => $this->realtimePrompt($jsonContext),
            'post_lesson_summary'  => $this->postLessonPrompt($jsonContext),
            'weekly_analysis'      => $this->weeklyPrompt($jsonContext),
            default                 => $this->postLessonPrompt($jsonContext),
        };
    }

    private function realtimePrompt(string $jsonContext): string
    {
        return <<<PROMPT
Тип: realtime_suggestion (подсказка во время урока).
Контекст:
{$jsonContext}

Задача: 1–2 предложения почему класс просел сейчас + 1 ключевой инсайт + 1 действие
с приоритетом "high".

Формат ответа (СТРОГО JSON, без markdown-обёртки):
{
  "content": "1-2 предложения, что сделать прямо сейчас",
  "insights": ["короткий инсайт"],
  "actions": [{"priority": "high", "action": "конкретное действие учителю"}]
}
PROMPT;
    }

    private function postLessonPrompt(string $jsonContext): string
    {
        return <<<PROMPT
Тип: post_lesson_summary (итог после урока).
Контекст:
{$jsonContext}

Задача: 2–3 абзаца разбора урока + 3 инсайта + 2–3 действия (high/medium).

Формат ответа (СТРОГО JSON):
{
  "content": "2-3 абзаца Markdown без code-блоков",
  "insights": ["инсайт 1", "инсайт 2", "инсайт 3"],
  "actions": [
    {"priority": "high", "action": "..."},
    {"priority": "medium", "action": "..."}
  ]
}
PROMPT;
    }

    private function weeklyPrompt(string $jsonContext): string
    {
        return <<<PROMPT
Тип: weekly_analysis (отчёт за прошедшую учебную неделю по одному классу).
Контекст:
{$jsonContext}

Задача: 3–4 абзаца разбора недели (тренд, лучший/худший день, проблемные студенты,
гипотезы причин), 5 инсайтов и список действий учителю на следующую неделю
(от 3 high до 2 low).

Формат ответа (СТРОГО JSON):
{
  "content": "3-4 абзаца на русском",
  "insights": ["1","2","3","4","5"],
  "actions": [
    {"priority": "high", "action": "..."},
    {"priority": "medium", "action": "..."}
  ]
}
PROMPT;
    }

    // ── Context builders ─────────────────────────────────────────

    private function buildSessionContext(LessonSession $session, array $stats, string $type): array
    {
        $weak = DB::table('engagement_snapshots')
            ->where('session_id', $session->id)
            ->select('student_id')
            ->selectRaw('AVG(engagement_score) as avg')
            ->groupBy('student_id')
            ->orderBy('avg')
            ->limit(3)
            ->get()
            ->map(fn ($r) => [
                'student_id' => $r->student_id,
                'avg_score'  => round((float) $r->avg, 1),
            ])
            ->toArray();

        $stateDist = DB::table('engagement_snapshots')
            ->where('session_id', $session->id)
            ->select('attention_state', DB::raw('COUNT(*) as cnt'))
            ->groupBy('attention_state')
            ->pluck('cnt', 'attention_state')
            ->toArray();

        $emotionDist = DB::table('engagement_snapshots')
            ->where('session_id', $session->id)
            ->select('emotion', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('emotion')
            ->groupBy('emotion')
            ->pluck('cnt', 'emotion')
            ->toArray();

        return [
            'type'                   => $type,
            'session_id'             => $session->id,
            'subject'                => $session->subject,
            'duration_minutes'       => $session->duration_minutes,
            'students_count'         => $session->students_count,
            'avg_score'              => $stats['avg'] ?? null,
            'min_score'              => $stats['min'] ?? null,
            'max_score'              => $stats['max'] ?? null,
            'std_dev'                => $stats['std_dev'] ?? null,
            'attention_state_dist'   => $stateDist,
            'emotion_distribution'   => $emotionDist,
            'top_weak_students'      => $weak,
        ];
    }

    private function buildWeeklyContext(Classroom $classroom, Carbon $from, Carbon $to): array
    {
        $sessions = LessonSession::query()
            ->where('classroom_id', $classroom->id)
            ->whereBetween('started_at', [$from, $to])
            ->where('status', 'completed')
            ->get();

        if ($sessions->isEmpty()) {
            return [
                'classroom_id' => $classroom->id,
                'from'         => $from->toDateString(),
                'to'           => $to->toDateString(),
                'n_sessions'   => 0,
            ];
        }

        $perDay = $sessions->groupBy(fn ($s) => Carbon::parse($s->started_at)->toDateString())
            ->map(fn ($group) => [
                'sessions' => $group->count(),
                'avg'      => round((float) $group->avg('avg_engagement_score'), 1),
            ]);

        $weakStudents = DB::table('engagement_snapshots')
            ->where('classroom_id', $classroom->id)
            ->whereBetween('captured_at', [$from, $to])
            ->select('student_id')
            ->selectRaw('AVG(engagement_score) as avg, COUNT(*) as snapshots')
            ->groupBy('student_id')
            ->orderBy('avg')
            ->limit(5)
            ->get()
            ->toArray();

        $stateDist = DB::table('engagement_snapshots')
            ->where('classroom_id', $classroom->id)
            ->whereBetween('captured_at', [$from, $to])
            ->select('attention_state', DB::raw('COUNT(*) as cnt'))
            ->whereNotNull('attention_state')
            ->groupBy('attention_state')
            ->pluck('cnt', 'attention_state')
            ->toArray();

        $byDay = $perDay->sortBy('avg');
        $worstDay = $byDay->keys()->first();
        $bestDay  = $byDay->keys()->last();

        $first = $perDay->first();
        $last  = $perDay->last();
        $trend = match (true) {
            !$first || !$last                   => 'stable',
            $last['avg']  > $first['avg'] + 5    => 'improving',
            $last['avg']  < $first['avg'] - 5    => 'declining',
            default                              => 'stable',
        };

        return [
            'classroom_id'         => $classroom->id,
            'classroom_name'       => $classroom->name ?? null,
            'from'                 => $from->toDateString(),
            'to'                   => $to->toDateString(),
            'n_sessions'           => $sessions->count(),
            'weekly_avg'           => round((float) $sessions->avg('avg_engagement_score'), 1),
            'best_day'             => $bestDay,
            'worst_day'            => $worstDay,
            'per_day'              => $perDay->toArray(),
            'trend'                => $trend,
            'attention_state_dist' => $stateDist,
            'top_weak_students'    => $weakStudents,
            'teacher_id'           => $sessions->first()->teacher_id ?? null,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────

    public function parseStructuredResponse(string $content): array
    {
        $clean = preg_replace('/```json|```/', '', $content);
        $clean = trim((string) $clean);

        // Если модель вдруг прислала текст до/после JSON, пытаемся выудить
        // первый объект.
        if (!str_starts_with($clean, '{')) {
            if (preg_match('/(\{[\s\S]*\})/', $clean, $m)) {
                $clean = $m[1];
            }
        }

        try {
            $data = json_decode($clean, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return ['content' => $content, 'insights' => [], 'actions' => []];
        }

        return [
            'content'  => $data['content']  ?? $content,
            'insights' => array_values($data['insights'] ?? []),
            'actions'  => array_values($data['actions']  ?? []),
        ];
    }
}
