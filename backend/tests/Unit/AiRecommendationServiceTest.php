<?php

namespace Tests\Unit;

use App\Domain\Engagement\Services\EngagementAggregatorService;
use App\Domain\Recommendation\Services\AiRecommendationService;
use App\Domain\Recommendation\Services\OllamaClient;
use Tests\TestCase;

class AiRecommendationServiceTest extends TestCase
{
    private AiRecommendationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AiRecommendationService(
            $this->createMock(OllamaClient::class),
            $this->createMock(EngagementAggregatorService::class),
        );
    }

    public function test_parse_structured_response_handles_clean_json(): void
    {
        $json = '{"content":"hi","insights":["a","b"],"actions":[{"priority":"high","action":"x"}]}';
        $parsed = $this->service->parseStructuredResponse($json);

        $this->assertSame('hi', $parsed['content']);
        $this->assertSame(['a', 'b'], $parsed['insights']);
        $this->assertSame([['priority' => 'high', 'action' => 'x']], $parsed['actions']);
    }

    public function test_parse_structured_response_strips_markdown_code_fences(): void
    {
        $wrapped = "```json\n{\"content\":\"y\",\"insights\":[],\"actions\":[]}\n```";
        $parsed = $this->service->parseStructuredResponse($wrapped);

        $this->assertSame('y', $parsed['content']);
    }

    public function test_parse_structured_response_extracts_json_from_padded_text(): void
    {
        $padded = "Конечно! Вот ответ:\n{\"content\":\"ok\",\"insights\":[\"i\"],\"actions\":[]}\nСпасибо.";
        $parsed = $this->service->parseStructuredResponse($padded);

        $this->assertSame('ok', $parsed['content']);
        $this->assertSame(['i'], $parsed['insights']);
    }

    public function test_parse_structured_response_falls_back_on_invalid_json(): void
    {
        $broken = "this is not json at all";
        $parsed = $this->service->parseStructuredResponse($broken);

        $this->assertSame($broken, $parsed['content']);
        $this->assertSame([], $parsed['insights']);
        $this->assertSame([], $parsed['actions']);
    }

    public function test_system_prompt_demands_strict_json_and_russian_tone(): void
    {
        $prompt = $this->service->getSystemPrompt();

        $this->assertStringContainsString('JSON', $prompt);
        $this->assertStringContainsString('русском', $prompt);
        $this->assertStringContainsString('5–12', $prompt);
    }
}
