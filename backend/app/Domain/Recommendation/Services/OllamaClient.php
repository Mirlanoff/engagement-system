<?php

namespace App\Domain\Recommendation\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Тонкая обёртка над локальным Ollama (`/api/chat`).
 *
 * Используется вместо Anthropic Claude API — всё крутится on-premise
 * на школьном сервере. Endpoint и модель настраиваются через
 * config('services.ollama.url') и config('services.ollama.model').
 *
 * Поддерживает structured JSON через параметр `format=json` Ollama —
 * это резко повышает шанс что модель выдаст валидный JSON.
 */
class OllamaClient
{
    public function __construct(
        private readonly ?string $baseUrl = null,
        private readonly ?string $defaultModel = null,
        private readonly int $timeoutSeconds = 120,
    ) {}

    /**
     * Один turn чата.
     *
     * @param  array<int,array{role:string,content:string}>  $messages
     * @param  array<string,mixed>  $options Ollama generation options (temperature, num_predict, ...)
     * @return array{content:string, eval_count:?int, model:string, raw:array}
     */
    public function chat(array $messages, array $options = [], ?string $model = null, bool $expectJson = false): array
    {
        $payload = [
            'model'    => $model ?? $this->defaultModel ?? config('services.ollama.model', 'qwen2.5:7b-instruct'),
            'messages' => $messages,
            'stream'   => false,
            'options'  => $options + [
                'temperature' => 0.4,
                'num_predict' => 1024,
            ],
        ];
        if ($expectJson) {
            $payload['format'] = 'json';
        }

        $url = rtrim($this->baseUrl ?? config('services.ollama.url', 'http://ollama:11434'), '/');
        $response = $this->httpClient()->post($url . '/api/chat', $payload);

        if ($response->failed()) {
            Log::error('Ollama chat failed', [
                'status' => $response->status(),
                'body'   => substr((string) $response->body(), 0, 500),
            ]);
            throw new \RuntimeException(
                "Ollama API error: HTTP {$response->status()}"
            );
        }

        $body = $response->json();
        $content = $body['message']['content'] ?? '';

        return [
            'content'    => is_string($content) ? $content : (string) json_encode($content),
            'eval_count' => $body['eval_count'] ?? null,
            'model'      => $body['model'] ?? $payload['model'],
            'raw'        => $body,
        ];
    }

    /**
     * Проверяет доступность сервиса.
     */
    public function ping(): bool
    {
        $url = rtrim($this->baseUrl ?? config('services.ollama.url', 'http://ollama:11434'), '/');
        try {
            return $this->httpClient()->timeout(3)->get($url . '/api/tags')->successful();
        } catch (\Throwable $e) {
            return false;
        }
    }

    private function httpClient(): PendingRequest
    {
        return Http::timeout($this->timeoutSeconds)
            ->acceptJson()
            ->asJson();
    }
}
