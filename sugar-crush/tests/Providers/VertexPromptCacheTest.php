<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\ToolResultMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CacheBreakpoints;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\VertexProvider;
use SugarCraft\Crush\Tools\ToolCall;

/**
 * Audit A15, the cache half, on Vertex: cache reads and writes used to be
 * unpriced, and no request carried a breakpoint, so the buckets were always
 * zero. The Anthropic arm now marks `cache_control` through
 * {@see CacheBreakpoints} (system prompt, last tool, conversation tail), every
 * reported cache token is priced at its own rate on both Anthropic arms and on
 * Gemini, and the `promptCache` switch turns the marks off. Fixtures only: the
 * predictor and streamer seams stand in for the network.
 */
final class VertexPromptCacheTest extends TestCase
{
    private const MODEL = 'claude-sonnet-4-6';

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    // -------------------------------------------------------------------------
    // Breakpoints on the wire
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{bool}> */
    public static function arms(): iterable
    {
        yield 'rawPredict' => [false];
        yield 'streamRawPredict' => [true];
    }

    #[DataProvider('arms')]
    public function testTheSystemPromptTheLastToolAndTheConversationTailAreMarked(bool $stream): void
    {
        $body = $this->sentBody($this->provider(), $this->agenticRequest(), $stream);

        $ephemeral = ['type' => 'ephemeral'];

        // The string system prompt travels as one text block so it can carry
        // the mark; its bytes are unchanged.
        self::assertSame([['type' => 'text', 'text' => 'You are terse.', 'cache_control' => $ephemeral]], $body['system']);

        self::assertArrayNotHasKey('cache_control', $body['tools'][0]);
        self::assertSame($ephemeral, $body['tools'][1]['cache_control']);

        $lastTurn = $body['messages'][array_key_last($body['messages'])];
        self::assertSame($ephemeral, $lastTurn['content'][array_key_last($lastTurn['content'])]['cache_control']);

        self::assertSame(CacheBreakpoints::MAX_BREAKPOINTS, $this->markCount($body));
        self::assertSame($stream, $body['stream'] ?? false);
    }

    public function testABlockFormSystemPromptKeepsItsBlocksAndMarksTheLastOne(): void
    {
        $body = $this->sentBody($this->provider(), new CompleteRequest(
            model: self::MODEL,
            messages: [new UserMessage('hi'), new SystemMessage('late notice')],
            systemPrompt: 'Stable.Volatile.',
            systemBlocks: ['Stable.', 'Volatile.'],
        ), false);

        self::assertSame([
            ['type' => 'text', 'text' => 'Stable.'],
            ['type' => 'text', 'text' => 'Volatile.'],
            ['type' => 'text', 'text' => "\n\nlate notice", 'cache_control' => ['type' => 'ephemeral']],
        ], $body['system']);
        self::assertSame([['role' => 'user', 'content' => [
            ['type' => 'text', 'text' => 'hi', 'cache_control' => ['type' => 'ephemeral']],
        ]]], $body['messages']);
    }

    /**
     * Every step of an agentic turn rebuilds the body, and a stale mark a
     * pre-shaped tool still carries is wiped before the plan is re-derived -
     * otherwise the marks pile up across steps and cross the API cap.
     */
    public function testAStaleMarkOnAPreShapedToolIsWipedNotStacked(): void
    {
        $request = $this->agenticRequest(tools: [
            ['name' => 'a', 'description' => 'a', 'input_schema' => ['type' => 'object'], 'cache_control' => ['type' => 'ephemeral']],
            ['name' => 'b', 'description' => 'b', 'input_schema' => ['type' => 'object']],
        ]);

        $body = $this->sentBody($this->provider(), $request, false);

        self::assertArrayNotHasKey('cache_control', $body['tools'][0]);
        self::assertSame(['type' => 'ephemeral'], $body['tools'][1]['cache_control']);
        self::assertLessThanOrEqual(CacheBreakpoints::MAX_BREAKPOINTS, $this->markCount($body));
    }

    #[DataProvider('arms')]
    public function testWithPromptCacheOffTheBodyCarriesNoMarkAndAStringSystem(bool $stream): void
    {
        $body = $this->sentBody($this->provider(promptCache: false), $this->agenticRequest(), $stream);

        self::assertSame('You are terse.', $body['system']);
        self::assertSame(0, $this->markCount($body));
    }

    public function testAFamilyThatNeverOfferedCachingIsNotMarked(): void
    {
        $provider = $this->provider(model: 'claude-3-sonnet@20240229');

        self::assertFalse($provider->marksPromptCache('claude-3-sonnet@20240229'));
        self::assertSame(0, $this->markCount($this->sentBody($provider, $this->agenticRequest('claude-3-sonnet@20240229'), false)));
    }

    public function testGeminiIsNeverMarked(): void
    {
        $provider = $this->provider(model: 'gemini-2.5-pro');

        self::assertFalse($provider->marksPromptCache('gemini-2.5-pro'));
        $body = $this->sentBody($provider, new CompleteRequest(
            model: 'gemini-2.5-pro',
            messages: [new UserMessage('hi')],
            systemPrompt: 'You are terse.',
        ), false);

        self::assertStringNotContainsString('cache_control', (string) json_encode($body));
    }

    public function testThePromptCacheAccessorReportsTheConstructedSwitch(): void
    {
        self::assertTrue($this->provider()->promptCache());
        self::assertFalse($this->provider(promptCache: false)->promptCache());
        self::assertFalse($this->provider(promptCache: false)->marksPromptCache(self::MODEL));
    }

    // -------------------------------------------------------------------------
    // Pricing: Anthropic arms
    // -------------------------------------------------------------------------

    public function testTheUnaryReplyPricesCacheReadsAndWritesAtTheirOwnRates(): void
    {
        $usage = $this->provider()->parseAnthropicUsage([
            'input_tokens' => 100,
            'output_tokens' => 50,
            'cache_read_input_tokens' => 10_000,
            'cache_creation_input_tokens' => 2_000,
        ], self::MODEL);

        // sonnet 4.6: input 3, output 15, read 0.30, write 3.75 per 1M.
        $expected = (100 * 0.003 + 50 * 0.015 + 10_000 * 0.0003 + 2_000 * 0.00375) / 1000;
        self::assertEqualsWithDelta($expected, $usage->costUsd, 1e-12);
        self::assertSame(10_000, $usage->cacheReadTokens);
        self::assertSame(2_000, $usage->cacheCreationTokens);
        self::assertNull($usage->unpricedModel);
        // tokensUsed keeps its input + output contract.
        self::assertSame(150, $usage->totalTokens);
    }

    public function testTheStreamedStartEventPricesItsCacheBuckets(): void
    {
        $responses = $this->stream([
            ['type' => 'message_start', 'message' => ['usage' => [
                'input_tokens' => 20,
                'output_tokens' => 1,
                'cache_read_input_tokens' => 30_000,
                'cache_creation_input_tokens' => 500,
            ]]],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 40]],
            ['type' => 'message_stop'],
        ]);

        $start = $responses[0];
        self::assertEqualsWithDelta((20 * 0.003 + 30_000 * 0.0003 + 500 * 0.00375) / 1000, $start->costUsd, 1e-12);
        self::assertSame(20, $start->tokensUsed);
        self::assertSame(30_000, $start->usage?->cacheReadTokens);
        self::assertSame(500, $start->usage?->cacheCreationTokens);

        // The delta still prices only its own side.
        self::assertEqualsWithDelta(40 * 0.015 / 1000, $responses[1]->costUsd, 1e-12);
    }

    /**
     * A start whose whole prompt was a cache hit used to be dropped by the
     * input-only gate - cost and buckets with it - which breakpoints make an
     * everyday event rather than a corner.
     */
    public function testAnAllCachedStartEventIsStillBilled(): void
    {
        $responses = $this->stream([
            ['type' => 'message_start', 'message' => ['usage' => [
                'input_tokens' => 0,
                'cache_read_input_tokens' => 50_000,
                'cache_creation_input_tokens' => 0,
            ]]],
            ['type' => 'message_stop'],
        ]);

        self::assertCount(1, $responses);
        self::assertEqualsWithDelta(50_000 * 0.0003 / 1000, $responses[0]->costUsd, 1e-12);
        self::assertSame(0, $responses[0]->tokensUsed);
        self::assertSame(50_000, $responses[0]->usage?->cacheReadTokens);
    }

    public function testAStartEventWithNothingOnAnyInputBucketStillYieldsNothing(): void
    {
        self::assertSame([], $this->stream([
            ['type' => 'message_start', 'message' => ['usage' => [
                'input_tokens' => 0,
                'cache_read_input_tokens' => 0,
                'cache_creation_input_tokens' => 0,
            ]]],
            ['type' => 'message_stop'],
        ]));
    }

    // -------------------------------------------------------------------------
    // Pricing: Gemini
    // -------------------------------------------------------------------------

    /**
     * `cachedContentTokenCount` is a subset of `promptTokenCount`: the cached
     * part bills at the cache rate and only the rest at the input rate.
     */
    public function testGeminiBillsItsCachedSubsetAtTheCacheRate(): void
    {
        $usage = $this->provider(model: 'gemini-2.5-pro')->parseUsageMetadata([
            'promptTokenCount' => 10_000,
            'cachedContentTokenCount' => 8_000,
            'candidatesTokenCount' => 100,
        ], 'gemini-2.5-pro');

        // 2.5 pro: input 1.25, output 10, cached 0.125 per 1M.
        self::assertEqualsWithDelta((2_000 * 0.00125 + 8_000 * 0.000125 + 100 * 0.01) / 1000, $usage->costUsd, 1e-12);
        self::assertSame(2_000, $usage->inputTokens);
        self::assertSame(8_000, $usage->cacheReadTokens);
    }

    public function testAGeminiCachedCountAboveThePromptBillsNoPhantomTokens(): void
    {
        $usage = $this->provider(model: 'gemini-2.5-flash')->parseUsageMetadata([
            'promptTokenCount' => 1_000,
            'cachedContentTokenCount' => 5_000,
            'candidatesTokenCount' => 0,
        ], 'gemini-2.5-flash');

        self::assertEqualsWithDelta(1_000 * 0.00003 / 1000, $usage->costUsd, 1e-12);
    }

    // -------------------------------------------------------------------------
    // Rates and the modelPrices seam
    // -------------------------------------------------------------------------

    /** @return iterable<string, array{string, float, ?float}> */
    public static function cacheRates(): iterable
    {
        yield 'sonnet 4.5 dated' => ['claude-sonnet-4-5@20250929', 0.0003, 0.00375];
        yield 'opus 4.6' => ['claude-opus-4-6', 0.0005, 0.00625];
        yield 'haiku 4.5' => ['claude-haiku-4-5@20251001', 0.0001, 0.00125];
        yield 'claude 3 haiku (its own published pair)' => ['claude-3-haiku@20240307', 0.00003, 0.0003];
        yield 'gemini 2.5 flash revision' => ['gemini-2.5-flash-001', 0.00003, 0.0003];
        yield 'gemini 2.0 flash' => ['gemini-2.0-flash', 0.0000375, 0.00015];
    }

    /**
     * A Gemini row has no write rate (the protocol reports no writes), so the
     * write column falls back to the input rate like any missing cache rate.
     */
    #[DataProvider('cacheRates')]
    public function testTheBuiltInCacheRates(string $model, float $read, ?float $write): void
    {
        $provider = $this->provider();

        self::assertEqualsWithDelta($read, $provider->cacheCostPer1kTokens($model, 'read'), 1e-12);
        self::assertEqualsWithDelta($write, $provider->cacheCostPer1kTokens($model, 'write'), 1e-12);
    }

    public function testAFamilyWithNoCacheRowBillsCacheAtItsInputRate(): void
    {
        $provider = $this->provider(modelPrices: ['claude-x' => ['input' => 2, 'output' => 8]]);

        self::assertEqualsWithDelta(0.002, $provider->cacheCostPer1kTokens('claude-x', 'read'), 1e-12);
        self::assertEqualsWithDelta(0.002, $provider->cacheCostPer1kTokens('claude-x', 'write'), 1e-12);
    }

    public function testAnOperatorEntryDeclaresItsOwnCacheRates(): void
    {
        $provider = $this->provider(modelPrices: [
            'claude-sonnet-4-6' => ['input' => 2, 'output' => 10, 'cached' => 0.2, 'cacheWrite' => 2.5],
        ]);

        self::assertEqualsWithDelta(0.0002, $provider->cacheCostPer1kTokens('claude-sonnet-4-6@20260101', 'read'), 1e-12);
        self::assertEqualsWithDelta(0.0025, $provider->cacheCostPer1kTokens('claude-sonnet-4-6@20260101', 'write'), 1e-12);
    }

    /** A named entry replaces the built-in row AND its cache discount. */
    public function testAnOperatorEntryWithoutCacheRatesBillsCacheAtItsOwnInput(): void
    {
        $provider = $this->provider(modelPrices: ['claude-sonnet-4-6' => ['input' => 2, 'output' => 10]]);

        self::assertEqualsWithDelta(0.002, $provider->cacheCostPer1kTokens('claude-sonnet-4-6', 'read'), 1e-12);
        self::assertEqualsWithDelta(0.002, $provider->cacheCostPer1kTokens('claude-sonnet-4-6', 'write'), 1e-12);
    }

    public function testABrokenDeclaredCacheRateTurnsACachedTurnUnpriced(): void
    {
        $provider = $this->provider(modelPrices: ['claude-sonnet-4-6' => ['input' => 3, 'output' => 15, 'cached' => -1]]);

        self::assertNull($provider->cacheCostPer1kTokens('claude-sonnet-4-6', 'read'));

        $usage = $provider->parseAnthropicUsage(['input_tokens' => 10, 'output_tokens' => 1, 'cache_read_input_tokens' => 100], self::MODEL);
        self::assertSame(0.0, $usage->costUsd);
        self::assertSame(self::MODEL, $usage->unpricedModel);

        // A turn with no cache reads never needs the broken rate.
        $clean = $provider->parseAnthropicUsage(['input_tokens' => 10, 'output_tokens' => 1, 'cache_read_input_tokens' => 0], self::MODEL);
        self::assertNull($clean->unpricedModel);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $modelPrices */
    private function provider(string $model = self::MODEL, array $modelPrices = [], bool $promptCache = true): VertexProvider
    {
        return VertexProvider::create(
            projectId: 'p',
            location: 'us-central1',
            model: $model,
            predictor: function (string $endpoint, string $method, array $body): array {
                $this->calls[] = [$endpoint, $method, $body];

                return ['content' => [['type' => 'text', 'text' => 'ok']], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]];
            },
            streamer: function (string $endpoint, string $method, array $body): \Generator {
                $this->calls[] = [$endpoint, $method, $body];

                yield ['type' => 'message_stop'];
            },
            modelPrices: $modelPrices,
            promptCache: $promptCache,
        );
    }

    /**
     * A mid-turn agentic step: system prompt, two tools, a tool round-trip.
     *
     * @param list<mixed>|null $tools
     */
    private function agenticRequest(string $model = self::MODEL, ?array $tools = null): CompleteRequest
    {
        return new CompleteRequest(
            model: $model,
            messages: [
                new UserMessage('list the files'),
                new AssistantMessage('', [ToolCall::fromArray(['id' => 'call_1', 'name' => 'Bash', 'arguments' => ['command' => 'ls']])]),
                new ToolResultMessage('call_1', 'a.txt'),
            ],
            systemPrompt: 'You are terse.',
            tools: $tools ?? [
                ['name' => 'Bash', 'description' => 'run', 'input_schema' => ['type' => 'object']],
                ['name' => 'Read', 'description' => 'read', 'input_schema' => ['type' => 'object']],
            ],
        );
    }

    /** @return array<string, mixed> */
    private function sentBody(VertexProvider $provider, CompleteRequest $request, bool $stream): array
    {
        $this->calls = [];

        if ($stream) {
            iterator_to_array($provider->completeStream($request), false);
        } else {
            $response = $provider->complete($request);
            self::assertFalse($response->isError, (string) $response->errorMessage);
        }

        self::assertCount(1, $this->calls);

        return $this->calls[0][2];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return list<CompleteResponse>
     */
    private function stream(array $events): array
    {
        $provider = VertexProvider::create(
            projectId: 'p',
            location: 'us-central1',
            model: self::MODEL,
            streamer: static function () use ($events): \Generator {
                yield from $events;
            },
        );

        $responses = iterator_to_array($provider->completeStream(new CompleteRequest(
            model: self::MODEL,
            messages: [new UserMessage('hi')],
        )), false);

        return array_values(array_filter(
            $responses,
            static fn (CompleteResponse $r): bool => $r->usage !== null || $r->costUsd > 0.0 || $r->tokensUsed > 0,
        ));
    }

    /** @param array<string, mixed> $body */
    private function markCount(array $body): int
    {
        return substr_count((string) json_encode($body), '"cache_control"');
    }
}
