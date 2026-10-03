<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\MockHandler;
use Aws\Result;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Messages\AssistantMessage;
use SugarCraft\Crush\Messages\SystemMessage;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\MarksPromptCache;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;

/**
 * Audit A15, the cache half, on Bedrock: `cacheReadInputTokens` and
 * `cacheWriteInputTokens` were parsed but never priced, and no request carried
 * a cache point, so both were always zero. Requests to a caching-capable model
 * now close the `system` list and the last message with a Converse
 * `cachePoint`, and both cache buckets are priced. Asserted on the params the
 * real runtime client validates and serialises (a MockHandler stands in for
 * AWS; no call leaves the process).
 */
final class BedrockPromptCacheTest extends TestCase
{
    private const POINT = ['cachePoint' => ['type' => 'default']];

    /** @return iterable<string, array{bool}> */
    public static function paths(): iterable
    {
        yield 'converse' => [false];
        yield 'converseStream' => [true];
    }

    #[DataProvider('paths')]
    public function testTheSystemListAndTheLastMessageAreClosedWithACachePoint(bool $stream): void
    {
        $sent = $this->sentParams($stream, 'us.anthropic.claude-sonnet-4-6', [
            new SystemMessage('notice'),
            new UserMessage('u1'),
            new AssistantMessage('a1'),
            new UserMessage('u2'),
        ], 'be brief');

        self::assertSame([['text' => 'be brief'], ['text' => 'notice'], self::POINT], $sent['system']);
        self::assertSame([
            ['role' => 'user', 'content' => [['text' => 'u1']]],
            ['role' => 'assistant', 'content' => [['text' => 'a1']]],
            ['role' => 'user', 'content' => [['text' => 'u2'], self::POINT]],
        ], $sent['messages']);
    }

    #[DataProvider('paths')]
    public function testWithoutASystemPromptOnlyTheConversationIsClosed(bool $stream): void
    {
        $sent = $this->sentParams($stream, 'us.anthropic.claude-sonnet-4-6', [new UserMessage('hi')], null);

        self::assertArrayNotHasKey('system', $sent);
        self::assertSame([['role' => 'user', 'content' => [['text' => 'hi'], self::POINT]]], $sent['messages']);
    }

    #[DataProvider('paths')]
    public function testWithPromptCacheOffNoCachePointIsSent(bool $stream): void
    {
        $sent = $this->sentParams($stream, 'us.anthropic.claude-sonnet-4-6', [new UserMessage('hi')], 'be brief', promptCache: false);

        self::assertSame([['text' => 'be brief']], $sent['system']);
        self::assertSame([['role' => 'user', 'content' => [['text' => 'hi']]]], $sent['messages']);
    }

    /** @return iterable<string, array{string, bool}> */
    public static function models(): iterable
    {
        yield 'sonnet 4.6 profile' => ['us.anthropic.claude-sonnet-4-6', true];
        yield 'opus 4.1 dated' => ['anthropic.claude-opus-4-1-20250805-v1:0', true];
        yield 'claude 3.7 sonnet global' => ['global.anthropic.claude-3-7-sonnet-20250219-v1:0', true];
        yield 'haiku 3.5' => ['anthropic.claude-3-5-haiku-20241022-v1:0', true];
        yield 'a Claude newer than any list' => ['us.anthropic.claude-sonnet-9', true];
        yield 'nova pro' => ['us.amazon.nova-pro-v1:0', true];
        yield 'nova micro' => ['amazon.nova-micro-v1:0', true];
        yield 'claude 3 haiku' => ['anthropic.claude-3-haiku-20240307-v1:0', false];
        yield 'claude 3.5 sonnet v2' => ['anthropic.claude-3-5-sonnet-20241022-v2:0', false];
        yield 'claude 3 opus' => ['anthropic.claude-3-opus-20240229-v1:0', false];
        yield 'claude v2' => ['anthropic.claude-v2:1', false];
        yield 'llama 3' => ['meta.llama3-70b-instruct-v1:0', false];
        yield 'nova canvas (not a text model)' => ['amazon.nova-canvas-v1:0', false];
        yield 'application inference profile' => ['arn:aws:bedrock:us-east-1:123456789012:application-inference-profile/abc123', false];
    }

    /**
     * Converse fails the WHOLE request on a cache point an unsupported model
     * does not accept, so only families known to cache are marked.
     */
    #[DataProvider('models')]
    public function testOnlyACachingCapableModelIsMarked(string $model, bool $marked): void
    {
        self::assertSame($marked, $this->provider(new MockHandler(), $model)->marksPromptCache($model));

        $sent = $this->sentParams(false, $model, [new UserMessage('hi')], 'be brief');
        self::assertSame($marked, str_contains((string) json_encode($sent), 'cachePoint'));
    }

    /**
     * The backend asks with ITS model id, which may be empty: an empty id is
     * the configured default, exactly as on the wire.
     */
    public function testAnEmptyModelIdAnswersForTheConfiguredDefault(): void
    {
        self::assertInstanceOf(MarksPromptCache::class, $this->provider(new MockHandler()));
        self::assertTrue($this->provider(new MockHandler())->marksPromptCache(''));
        self::assertFalse($this->provider(new MockHandler(), 'anthropic.claude-3-sonnet-20240229-v1:0')->marksPromptCache(''));
    }

    public function testThePromptCacheAccessorReportsTheConstructedSwitch(): void
    {
        self::assertTrue($this->provider(new MockHandler())->promptCache());
        self::assertFalse($this->provider(new MockHandler(), promptCache: false)->promptCache());
        self::assertFalse(
            $this->provider(new MockHandler(), promptCache: false)->marksPromptCache('us.anthropic.claude-sonnet-4-6'),
        );
    }

    // -------------------------------------------------------------------------
    // Pricing
    // -------------------------------------------------------------------------

    public function testTheUnaryReplyPricesCacheReadsAndWrites(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
            'stopReason' => 'end_turn',
            'usage' => [
                'inputTokens' => 100,
                'outputTokens' => 50,
                'totalTokens' => 12_150,
                'cacheReadInputTokens' => 10_000,
                'cacheWriteInputTokens' => 2_000,
            ],
        ]));

        $response = $this->provider($mock)->complete(new CompleteRequest(
            model: 'us.anthropic.claude-sonnet-4-6',
            messages: [new UserMessage('hi')],
        ));

        // sonnet 4.6: input 3, output 15, read 0.30, write 3.75 per 1M.
        self::assertEqualsWithDelta(
            (100 * 0.003 + 50 * 0.015 + 10_000 * 0.0003 + 2_000 * 0.00375) / 1000,
            $response->costUsd,
            1e-12,
        );
        self::assertSame(150, $response->tokensUsed);
        self::assertSame(10_000, $response->usage?->cacheReadTokens);
        self::assertSame(2_000, $response->usage?->cacheCreationTokens);
        self::assertNull($response->usage?->unpricedModel);
    }

    public function testTheStreamedMetadataEventPricesCacheReadsAndWrites(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result(['stream' => new \ArrayIterator([
            ['contentBlockDelta' => ['delta' => ['text' => 'ok'], 'contentBlockIndex' => 0]],
            ['messageStop' => ['stopReason' => 'end_turn']],
            ['metadata' => ['usage' => [
                'inputTokens' => 5,
                'outputTokens' => 7,
                'totalTokens' => 3_012,
                'cacheReadInputTokens' => 3_000,
                'cacheWriteInputTokens' => 0,
            ]]],
        ])]));

        $chunks = iterator_to_array($this->provider($mock, 'us.anthropic.claude-haiku-4-5')->completeStream(new CompleteRequest(
            model: 'us.anthropic.claude-haiku-4-5',
            messages: [new UserMessage('hi')],
        )), false);

        $total = array_sum(array_map(static fn (CompleteResponse $c): float => $c->costUsd, $chunks));
        // haiku 4.5: input 1, output 5, read 0.10 per 1M.
        self::assertEqualsWithDelta((5 * 0.001 + 7 * 0.005 + 3_000 * 0.0001) / 1000, $total, 1e-12);
    }

    /** @return iterable<string, array{string, float, float}> */
    public static function cacheRates(): iterable
    {
        yield 'sonnet 4.6 profile' => ['us.anthropic.claude-sonnet-4-6', 0.0003, 0.00375];
        yield 'opus 4.1 dated' => ['anthropic.claude-opus-4-1-20250805-v1:0', 0.0015, 0.01875];
        yield 'haiku 3.5' => ['anthropic.claude-3-5-haiku-20241022-v1:0', 0.00008, 0.001];
        // No cache row: its own input rate, never an invented discount.
        yield 'llama 3 70b' => ['meta.llama3-70b-instruct-v1:0', 0.00065, 0.00065];
    }

    #[DataProvider('cacheRates')]
    public function testTheBuiltInCacheRates(string $model, float $read, float $write): void
    {
        $provider = $this->provider(new MockHandler());

        self::assertEqualsWithDelta($read, $provider->cacheCostPer1kTokens($model, 'read'), 1e-12);
        self::assertEqualsWithDelta($write, $provider->cacheCostPer1kTokens($model, 'write'), 1e-12);
    }

    public function testAnOperatorEntryDeclaresOrInheritsItsCacheRates(): void
    {
        $declared = $this->provider(new MockHandler(), modelPrices: [
            'anthropic.claude-sonnet-4-6' => ['input' => 3.3, 'output' => 16.5, 'cached' => 0.33, 'cacheWrite' => 4.125],
        ]);
        self::assertEqualsWithDelta(0.00033, $declared->cacheCostPer1kTokens('us.anthropic.claude-sonnet-4-6', 'read'), 1e-12);
        self::assertEqualsWithDelta(0.004125, $declared->cacheCostPer1kTokens('us.anthropic.claude-sonnet-4-6', 'write'), 1e-12);

        $inherited = $this->provider(new MockHandler(), modelPrices: [
            'anthropic.claude-sonnet-4-6' => ['input' => 3.3, 'output' => 16.5],
        ]);
        self::assertEqualsWithDelta(0.0033, $inherited->cacheCostPer1kTokens('us.anthropic.claude-sonnet-4-6', 'read'), 1e-12);
    }

    public function testAnUnknownModelsCacheTokensStayUnpriced(): void
    {
        $usage = $this->provider(new MockHandler(), 'made-up')->parseUsage([
            'inputTokens' => 0,
            'outputTokens' => 0,
            'cacheReadInputTokens' => 500,
        ], 'made-up');

        self::assertSame(0.0, $usage->costUsd);
        self::assertSame('made-up', $usage->unpricedModel);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** @param array<string, mixed> $modelPrices */
    private function provider(
        MockHandler $mock,
        string $model = 'us.anthropic.claude-sonnet-4-6',
        array $modelPrices = [],
        bool $promptCache = true,
    ): BedrockProvider {
        return new BedrockProvider(
            new BedrockRuntimeClient([
                'region' => 'us-east-1',
                'version' => 'latest',
                'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
                'handler' => $mock,
            ]),
            'us-east-1',
            $model,
            $modelPrices,
            $promptCache,
        );
    }

    /**
     * @param list<\SugarCraft\Crush\Messages\Message> $history
     * @return array<string, mixed>
     */
    private function sentParams(bool $stream, string $model, array $history, ?string $systemPrompt, bool $promptCache = true): array
    {
        $mock = new MockHandler();
        $mock->append($stream
            ? new Result(['stream' => new \ArrayIterator([['messageStop' => ['stopReason' => 'end_turn']]])])
            : new Result([
                'output' => ['message' => ['role' => 'assistant', 'content' => [['text' => 'ok']]]],
                'stopReason' => 'end_turn',
                'usage' => ['inputTokens' => 1, 'outputTokens' => 1],
            ]));

        $provider = $this->provider($mock, $model, promptCache: $promptCache);
        $request = new CompleteRequest(model: $model, messages: $history, systemPrompt: $systemPrompt);

        if ($stream) {
            iterator_to_array($provider->completeStream($request), false);
        } else {
            $provider->complete($request);
        }

        self::assertSame($stream ? 'ConverseStream' : 'Converse', $mock->getLastCommand()->getName());

        return $mock->getLastCommand()->toArray();
    }
}
