<?php

namespace Tests\Unit;

use App\Domain\Recommendation\Services\OllamaClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OllamaClientTest extends TestCase
{
    public function test_chat_returns_parsed_payload(): void
    {
        Http::fake([
            'http://ollama:11434/api/chat' => Http::response([
                'model'      => 'qwen2.5:7b-instruct',
                'message'    => ['role' => 'assistant', 'content' => '{"hello":"world"}'],
                'eval_count' => 42,
            ], 200),
        ]);

        config(['services.ollama.url' => 'http://ollama:11434']);
        config(['services.ollama.model' => 'qwen2.5:7b-instruct']);

        $client = new OllamaClient();
        $resp = $client->chat([
            ['role' => 'system', 'content' => 'sys'],
            ['role' => 'user',   'content' => 'hi'],
        ], expectJson: true);

        $this->assertSame('{"hello":"world"}', $resp['content']);
        $this->assertSame(42, $resp['eval_count']);
        $this->assertSame('qwen2.5:7b-instruct', $resp['model']);

        Http::assertSent(function ($request) {
            $body = $request->data();
            return str_ends_with($request->url(), '/api/chat')
                && $body['stream'] === false
                && $body['format'] === 'json'
                && $body['model'] === 'qwen2.5:7b-instruct'
                && count($body['messages']) === 2;
        });
    }

    public function test_chat_throws_on_http_error(): void
    {
        Http::fake([
            'http://ollama:11434/*' => Http::response(['error' => 'oops'], 500),
        ]);

        config(['services.ollama.url' => 'http://ollama:11434']);
        $client = new OllamaClient();

        $this->expectException(\RuntimeException::class);
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function test_ping_uses_tags_endpoint(): void
    {
        Http::fake([
            'http://ollama:11434/api/tags' => Http::response(['models' => []], 200),
        ]);
        config(['services.ollama.url' => 'http://ollama:11434']);
        $client = new OllamaClient();

        $this->assertTrue($client->ping());

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/api/tags'));
    }

    public function test_ping_returns_false_on_failure(): void
    {
        Http::fake([
            '*' => Http::response(null, 503),
        ]);
        config(['services.ollama.url' => 'http://ollama:11434']);
        $client = new OllamaClient();

        $this->assertFalse($client->ping());
    }

    public function test_options_default_temperature_overridable(): void
    {
        Http::fake([
            'http://ollama:11434/*' => Http::response([
                'message' => ['content' => '{}'],
            ], 200),
        ]);
        config(['services.ollama.url' => 'http://ollama:11434']);
        $client = new OllamaClient();

        $client->chat(
            messages: [['role' => 'user', 'content' => 'x']],
            options: ['temperature' => 0.1],
        );

        Http::assertSent(fn ($r) => $r->data()['options']['temperature'] === 0.1);
    }
}
