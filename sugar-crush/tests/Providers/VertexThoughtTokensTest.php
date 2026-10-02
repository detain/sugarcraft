<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\VertexProvider;

/**
 * Audit A21 (a): Gemini 2.5 on Vertex thinks by default, and the tokens it
 * spends doing so arrive in `usageMetadata.thoughtsTokenCount` - billed as
 * output, excluded from `candidatesTokenCount`. The parse dropped the field,
 * so every Gemini 2.5 turn under-counted its output, total and cost. These
 * pin the fold on the unary and streamed arms. (Part (b), the shared 4096
 * `maxOutputTokens` default, is deferred.)
 */
final class VertexThoughtTokensTest extends TestCase
{
    /** @param list<array<string, mixed>> $events */
    private function provider(string $model = 'gemini-2.5-pro', array $predicted = [], array $events = []): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'my-project',
            location: 'us-central1',
            model: $model,
            predictor: fn (string $endpoint, string $method, array $body): array => $predicted,
            streamer: static function (string $endpoint, string $method, array $body) use ($events): \Generator {
                yield from $events;
            },
        );
    }

    public function testThoughtTokensAreAccountedAsBilledOutput(): void
    {
        $usage = $this->provider()->parseUsageMetadata(
            ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 900, 'totalTokenCount' => 915],
            'gemini-2.5-pro',
        );

        $this->assertSame(915, $usage->totalTokens, 'matches Google\'s own totalTokenCount');
        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(905, $usage->outputTokens, 'candidates + thoughts');
        $this->assertSame(900, $usage->reasoningTokens);
        // 10 x 0.00125/1k + 905 x 0.01/1k
        $this->assertEqualsWithDelta(0.0090625, $usage->costUsd, 1e-12);
    }

    public function testAbsentThoughtsLeaveTheParseUnchangedAndTheBucketUnreported(): void
    {
        $usage = $this->provider()->parseUsageMetadata(
            ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'totalTokenCount' => 99],
            'gemini-2.5-pro',
        );

        $this->assertSame(15, $usage->totalTokens);
        $this->assertSame(5, $usage->outputTokens);
        $this->assertNull($usage->reasoningTokens);
    }

    public function testAThoughtOnlyDocumentStillReportsItsOutput(): void
    {
        $usage = $this->provider()->parseUsageMetadata(
            ['promptTokenCount' => 10, 'thoughtsTokenCount' => 40],
            'gemini-2.5-flash',
        );

        $this->assertSame(50, $usage->totalTokens);
        $this->assertSame(40, $usage->outputTokens);
        $this->assertSame(40, $usage->reasoningTokens);
    }

    public function testANegativeThoughtCountNeverSubtractsFromTheOutput(): void
    {
        $usage = $this->provider()->parseUsageMetadata(
            ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => -100],
            'gemini-2.5-pro',
        );

        $this->assertSame(15, $usage->totalTokens);
        $this->assertSame(5, $usage->outputTokens);
    }

    public function testTheUnaryGeminiCompletionCarriesTheThoughtTokens(): void
    {
        $response = $this->provider(predicted: [
            'candidates' => [['content' => ['parts' => [['text' => 'Hi']]], 'finishReason' => 'STOP']],
            'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 900],
        ])->complete(new CompleteRequest(model: 'gemini-2.5-pro', messages: [new UserMessage('hi')]));

        $this->assertSame(915, $response->tokensUsed);
        $this->assertSame(900, $response->usage?->reasoningTokens);
        $this->assertSame(905, $response->usage?->outputTokens);
    }

    public function testTheStreamedGeminiUsageEmitCarriesTheThoughtTokens(): void
    {
        $chunks = iterator_to_array($this->provider(events: [
            ['candidates' => [['content' => ['parts' => [['text' => 'Hi']]]]]],
            [
                'candidates' => [['content' => ['parts' => [['text' => '!']]], 'finishReason' => 'STOP']],
                'usageMetadata' => ['promptTokenCount' => 10, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 900],
            ],
        ])->completeStream(new CompleteRequest(model: 'gemini-2.5-pro', messages: [new UserMessage('hi')])), false);

        $billed = array_values(array_filter($chunks, static fn (CompleteResponse $c): bool => $c->tokensUsed > 0));
        $this->assertCount(1, $billed, 'cumulative usage is still emitted once');
        $this->assertSame(915, $billed[0]->tokensUsed);
        $this->assertSame(900, $billed[0]->usage?->reasoningTokens);
        $this->assertEqualsWithDelta(0.0090625, $billed[0]->costUsd, 1e-12);
    }
}
