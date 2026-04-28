<?php

namespace App\Infrastructure\ML;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP клиент для управления ML сервисом.
 * Все запросы подписываются HMAC токеном.
 */
class MlServiceClient
{
    private string $baseUrl;
    private string $secret;

    public function __construct()
    {
        $this->baseUrl = config('services.ml_service.url', 'http://ml-service:8001');
        $this->secret  = config('services.ml_service.secret');
    }

    public function startCapture(string $sessionId, string $classroomId, array $cameras): bool
    {
        return $this->post('/capture/start', [
            'session_id'   => $sessionId,
            'classroom_id' => $classroomId,
            'cameras'      => $cameras,
        ]);
    }

    public function stopCapture(string $sessionId): bool
    {
        return $this->post('/capture/stop', ['session_id' => $sessionId]);
    }

    public function pauseCapture(string $sessionId): bool
    {
        return $this->post('/capture/pause', ['session_id' => $sessionId]);
    }

    public function resumeCapture(string $sessionId): bool
    {
        return $this->post('/capture/resume', ['session_id' => $sessionId]);
    }

    public function getStatus(): array
    {
        try {
            $response = $this->client()->get("{$this->baseUrl}/status");
            return $response->json() ?? [];
        } catch (\Throwable $e) {
            Log::warning('ML service status check failed', ['error' => $e->getMessage()]);
            return ['status' => 'unavailable'];
        }
    }

    // ── Private ─────────────────────────────────────────────────

    private function post(string $path, array $data): bool
    {
        try {
            $body      = json_encode($data);
            $timestamp = (string) time();
            $signature = hash_hmac('sha256', $timestamp . $body, $this->secret);

            $response = Http::withHeaders([
                'Content-Type'           => 'application/json',
                'X-Internal-Signature'   => $signature,
                'X-Internal-Timestamp'   => $timestamp,
            ])
            ->timeout(10)
            ->withBody($body, 'application/json')
            ->post("{$this->baseUrl}{$path}");

            if ($response->failed()) {
                Log::warning("ML service request failed: {$path}", [
                    'status' => $response->status(),
                    'body'   => $response->body(),
                ]);
                return false;
            }

            return true;

        } catch (\Throwable $e) {
            Log::error("ML service communication error: {$path}", [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        $timestamp = (string) time();
        $signature = hash_hmac('sha256', $timestamp, $this->secret);

        return Http::withHeaders([
            'X-Internal-Signature' => $signature,
            'X-Internal-Timestamp' => $timestamp,
        ])->timeout(5);
    }
}
