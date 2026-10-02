<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Meta\MetaInformation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\OpenAIProvider;

/**
 * Audit A14: OpenAI's `prompt_tokens` INCLUDES the cached prefix, and cache
 * hits bill at a discounted rate, but calculateCost() priced every prompt
 * token at the full input rate. In long agentic sessions most of the prompt
 * is a cache hit, so reported spend ran up to ~2x high and `/budget` caps
 * tripped early. These pin the split bill: fresh × input + cached × cached
 * rate + completion × output.
 */
final class OpenAIProviderCachedCostTest extends TestCase
{
    private const USAGE = [
        'prompt_tokens' => 1000,
        'completion_tokens' => 100,
        'total_tokens' => 1100,
        'prompt_tokens_details' => ['cached_tokens' => 900],
    ];

    /**
     * model => [per-1K input, per-1K cached input, per-1K output], from
     * OpenAI's published per-1M sheet (cached: 4o 1.25, 4o-mini 0.075,
     * 4.1 0.50, 4.1-mini 0.10).
     *
     * @return iterable<string, array{string, float, float, float}>
     */
    public static function cachingModels(): iterable
    {
        yield 'gpt-4o' => ['gpt-4o', 0.0025, 0.00125, 0.01];
        yield 'gpt-4o-mini' => ['gpt-4o-mini', 0.00015, 0.000075, 0.0006];
        yield 'gpt-4.1' => ['gpt-4.1', 0.002, 0.0005, 0.008];
        yield 'gpt-4.1-mini' => ['gpt-4.1-mini', 0.0004, 0.0001, 0.0016];
    }

    #[DataProvider('cachingModels')]
    public function testCachedPrefixBillsAtTheDiscountedRate(string $model, float $input, float $cached, float $output): void
    {
        $usage = $this->provider()->parseUsage(self::USAGE, $model);

        $expected = (100 * $input + 900 * $cached + 100 * $output) / 1000;
        $this->assertEqualsWithDelta($expected, $usage->costUsd, 1e-12);
        $this->assertLessThan(
            (1000 * $input + 100 * $output) / 1000,
            $usage->costUsd,
            'the old bill priced all 1000 prompt tokens at the full input rate',
        );
        $this->assertNull($usage->unpricedModel);
    }

    public function testExactGpt4oFigure(): void
    {
        // (100 × 0.0025 + 900 × 0.00125 + 100 × 0.01) / 1000; the pre-fix
        // figure was (1000 × 0.0025 + 100 × 0.01) / 1000 = 0.0035.
        $usage = $this->provider()->parseUsage(self::USAGE, 'gpt-4o');

        $this->assertEqualsWithDelta(0.002375, $usage->costUsd, 1e-12);
    }

    /**
     * @return iterable<string, array{string, float, float}>
     */
    public static function nonCachingModels(): iterable
    {
        yield 'gpt-4-turbo' => ['gpt-4-turbo', 0.01, 0.03];
        yield 'gpt-4' => ['gpt-4', 0.03, 0.06];
        yield 'gpt-3.5-turbo' => ['gpt-3.5-turbo', 0.0005, 0.0015];
    }

    #[DataProvider('nonCachingModels')]
    public function testModelWithoutAPublishedCachedRateBillsCachedAtFullInput(string $model, float $input, float $output): void
    {
        // No published discount means none is invented: a cached token costs
        // what any other prompt token costs.
        $usage = $this->provider()->parseUsage(self::USAGE, $model);

        $this->assertEqualsWithDelta((1000 * $input + 100 * $output) / 1000, $usage->costUsd, 1e-12);
    }

    public function testUsageWithoutDetailsIsUnchanged(): void
    {
        $usage = $this->provider()->parseUsage(
            ['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100],
            'gpt-4o',
        );

        $this->assertEqualsWithDelta((1000 * 0.0025 + 100 * 0.01) / 1000, $usage->costUsd, 1e-12);
    }

    public function testCachedBeyondPromptNeverBillsMoreThanThePrompt(): void
    {
        // A server reporting more cached than prompt is a provider bug; the
        // bill caps the cached share at the prompt rather than charging for
        // tokens that were never sent.
        $usage = $this->provider()->parseUsage(
            ['prompt_tokens' => 10, 'completion_tokens' => 0, 'total_tokens' => 10, 'prompt_tokens_details' => ['cached_tokens' => 15]],
            'gpt-4o',
        );

        $this->assertEqualsWithDelta(10 * 0.00125 / 1000, $usage->costUsd, 1e-15);
    }

    public function testUnaryResponseCarriesTheCacheAwareFigure(): void
    {
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);
        $chat->method('create')->willReturn(ChatCreateResponse::from([
            'id' => 'chatcmpl-1',
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o',
            'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
                'finish_reason' => 'stop',
            ]],
            'usage' => self::USAGE,
        ], MetaInformation::from([])));

        $response = (new OpenAIProvider($client, 'gpt-4o'))->complete(new CompleteRequest(
            model: 'gpt-4o',
            messages: [new UserMessage('hi')],
        ));

        $this->assertEqualsWithDelta(0.002375, $response->costUsd, 1e-12);
        $this->assertSame($response->costUsd, $response->usage?->costUsd);
    }

    public function testOperatorCachedRateIsHonoured(): void
    {
        $provider = $this->provider(['gpt-4o' => ['input' => 2.5, 'output' => 10, 'cached' => 0.5]]);

        $usage = $provider->parseUsage(self::USAGE, 'gpt-4o');

        // (100 × 0.0025 + 900 × 0.0005 + 100 × 0.01) / 1000
        $this->assertEqualsWithDelta(0.0017, $usage->costUsd, 1e-12);
    }

    public function testOperatorOverrideWithoutCachedBillsCachedAtTheDeclaredInputRate(): void
    {
        // Naming the model makes the operator's entry authoritative: the
        // built-in discount belongs to the row the operator replaced, so it
        // must not be mixed with their input rate. No declared discount = none.
        $provider = $this->provider(['gpt-4o' => ['input' => 2.5, 'output' => 10]]);

        $usage = $provider->parseUsage(self::USAGE, 'gpt-4o');

        $this->assertEqualsWithDelta((1000 * 0.0025 + 100 * 0.01) / 1000, $usage->costUsd, 1e-12);
    }

    public function testInvalidOperatorCachedRateIsUnpricedNotGuessed(): void
    {
        $provider = $this->provider(['gpt-4o' => ['input' => 2.5, 'output' => 10, 'cached' => 'banana']]);

        $usage = $provider->parseUsage(self::USAGE, 'gpt-4o');

        // Same loud road as an invalid input/output rate: the lower bound
        // 0.0 with the model named, never a silent full-rate or built-in bill.
        $this->assertSame(0.0, $usage->costUsd);
        $this->assertSame('gpt-4o', $usage->unpricedModel);

        // When the turn reports no cache hits the broken cached rate is never
        // needed, so the bill is fully known.
        $fresh = $provider->parseUsage(['prompt_tokens' => 1000, 'completion_tokens' => 100, 'total_tokens' => 1100], 'gpt-4o');
        $this->assertEqualsWithDelta(0.0035, $fresh->costUsd, 1e-12);
        $this->assertNull($fresh->unpricedModel);
    }

    public function testUnknownModelStaysUnpricedEvenWithCachedTokens(): void
    {
        $usage = $this->provider()->parseUsage(self::USAGE, 'some-future-model');

        $this->assertSame(0.0, $usage->costUsd);
        $this->assertSame('some-future-model', $usage->unpricedModel);
    }

    /**
     * @param array<string, mixed> $modelPrices
     */
    private function provider(array $modelPrices = []): OpenAIProvider
    {
        return new OpenAIProvider($this->createMock(ClientContract::class), 'gpt-4o', $modelPrices);
    }
}
