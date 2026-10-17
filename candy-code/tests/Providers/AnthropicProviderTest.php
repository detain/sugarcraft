<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Messages\AssistantMessage;
use SugarCraft\Code\Messages\SystemMessage;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\EmbeddingsRequest;
use SugarCraft\Code\Providers\MarksPromptCache;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\RebindsModel;

/**
 * ISS-023: the native `api.anthropic.com/v1/messages` arm.
 *
 * The wire DOCUMENT and the parsers are shared with Vertex through
 * {@see \SugarCraft\Code\Providers\Concerns\AnthropicMessagesWire} and are
 * pinned byte-for-byte by `VertexProviderTest` against the trait's old
 * inline spelling; what this file owns is what is NATIVE-ONLY: the `model`
 * body key instead of `anthropic_version`, the absolute endpoint the
 * transport posts to, the native label in diagnostics, the shipped
 * context-window table, and the seam-rebuilding `withModel()`. The batch and
 * stream transports are additionally proven end-to-end at HTTP level by
 * `ProviderRequestResponseTest`'s mock-handler block.
 */
final class AnthropicProviderTest extends TestCase
{
    private const MODERN = 'claude-sonnet-4-6';
    private const RETIRED = 'claude-3-sonnet@20240229';

    /**
     * Drive one unary call through the seam, capturing [endpoint, body].
     *
     * @param array<string, mixed>   $response the canned response document
     * @param array{0: string, 1: array<string, mixed>} $captured filled by reference
     */
    private function nativeUnarySeam(array $response, array &$captured): callable
    {
        $captured = ['', []];

        return static function (string $endpoint, array $body) use ($response, &$captured): array {
            $captured = [$endpoint, $body];

            return $response;
        };
    }

    /**
     * A stream seam yielding the given canned events; kept as a helper so
     * every stream test below balances its own parentheses independently.
     *
     * @param list<array<string, mixed>> $events
     */
    private function streamSeam(array $events): callable
    {
        return static function (string $endpoint, array $body) use ($events): iterable {
            yield from $events;
        };
    }

    private function turnRequest(string $model = self::MODERN, ?array $messages = null, ?string $system = null): CompleteRequest
    {
        return new CompleteRequest(
            model: $model,
            messages: $messages ?? [new UserMessage('go')],
            systemPrompt: $system,
        );
    }

    public function testTheBodyNamesTheModelInTheDocumentAndCarriesNoVersionKey(): void
    {
        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            $this->nativeUnarySeam(['content' => []], $captured),
        );

        $provider->complete($this->turnRequest());

        [$endpoint, $body] = $captured;
        $this->assertSame('https://api.anthropic.com/v1/messages', $endpoint, 'the seam receives the absolute Messages URL');
        $this->assertSame('model', array_key_first($body), 'the native prelude merges `model` as the FIRST body key');
        $this->assertSame(self::MODERN, $body['model']);
        $this->assertArrayNotHasKey('anthropic_version', $body, 'the protocol date is a header on this host, never a body key');
        $this->assertSame(4096, $body['max_tokens'], 'the Messages API has no server-side max_tokens default');
        $this->assertArrayNotHasKey('stream', $body, 'the unary body must not stream');
    }

    public function testTheRequestModelRebindsTheBodyKey(): void
    {
        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            $this->nativeUnarySeam(['content' => []], $captured),
        );

        $provider->complete($this->turnRequest('claude-opus-4-1'));

        $this->assertSame('claude-opus-4-1', $captured[1]['model']);
    }

    public function testAssistantPrefillRidesTheNativeTranscript(): void
    {
        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            $this->nativeUnarySeam(['content' => []], $captured),
        );

        $provider->complete($this->turnRequest(messages: [new UserMessage('go'), new AssistantMessage('sure')]));

        $roles = array_map(static fn (array $m): string => (string) $m['role'], $captured[1]['messages']);
        $this->assertSame(['user', 'assistant'], $roles);
    }

    public function testCacheMarksAreArmedOnModernClaudesAndOffOnTheRetiredFamily(): void
    {
        foreach ([self::MODERN => true, self::RETIRED => false] as $model => $marked) {
            $captured = [];
            $provider = new AnthropicProvider(
                'sk-test',
                'https://api.anthropic.com',
                $model,
                $this->nativeUnarySeam(['content' => []], $captured),
            );
            $provider->complete($this->turnRequest($model, system: 'sys'));

            $encoded = json_encode($captured[1]);
            $this->assertIsString($encoded);
            $this->assertSame($marked, str_contains($encoded, 'cache_control'), "{$model} cache marking wrong");
        }
    }

    public function testPromptCacheFlagTakesTheMarksOffTheWire(): void
    {
        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            $this->nativeUnarySeam(['content' => []], $captured),
            promptCache: false,
        );
        $provider->complete($this->turnRequest());

        $encoded = json_encode($captured[1]);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString('cache_control', $encoded);
        $this->assertFalse($provider->promptCache());
    }

    public function testTheEnvironmentSwitchDisablesMarksThroughTheFactory(): void
    {
        putenv('CANDY_CODE_DISABLE_PROMPT_CACHE=1');

        try {
            $provider = (new ProviderFactory())->create(['type' => 'anthropic', 'apiKey' => 'k', 'model' => self::MODERN]);
            $this->assertInstanceOf(AnthropicProvider::class, $provider);
            $this->assertFalse($provider->promptCache(), 'the environment switch outranks the default-on flag');
            $this->assertFalse($provider->marksPromptCache(self::MODERN));
        } finally {
            putenv('CANDY_CODE_DISABLE_PROMPT_CACHE');
        }
    }

    public function testUnaryParseCarriesTextToolUseUsageBucketsCost(): void
    {
        $document = [
            'content' => [
                ['type' => 'text', 'text' => 'working'],
                ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'ProvbEchoTool', 'input' => ['say' => 'hi']],
            ],
            'stop_reason' => 'tool_use',
            'usage' => ['input_tokens' => 1000, 'output_tokens' => 500, 'cache_read_input_tokens' => 2000, 'cache_creation_input_tokens' => 100],
        ];
        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            $this->nativeUnarySeam($document, $captured),
        );

        $response = $provider->complete($this->turnRequest());

        $this->assertSame('working', $response->content);
        $this->assertCount(1, $response->toolCalls ?? []);
        $this->assertSame('toolu_9', ($response->toolCalls ?? [])[0]->id());
        $this->assertNotNull($response->usage);
        $this->assertSame(1000, $response->usage->inputTokens);
        $this->assertSame(500, $response->usage->outputTokens);
        $this->assertSame(2000, $response->usage->cacheReadTokens);
        $this->assertSame(100, $response->usage->cacheCreationTokens);
        $this->assertNotNull($response->costUsd);
        $this->assertGreaterThan(0.0, $response->costUsd);
        $this->assertFalse($response->truncated);
    }

    public function testTheOutputCeilingIsReportedAsTruncatedOnBothPaths(): void
    {
        $capCaptured = [];
        $capped = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            $this->nativeUnarySeam(['content' => [['type' => 'text', 'text' => 'half']], 'stop_reason' => 'max_tokens'], $capCaptured),
        );
        $this->assertTrue($capped->complete($this->turnRequest())->truncated);

        $streamCapped = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            null,
            $this->streamSeam([
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'max_tokens'], 'usage' => ['output_tokens' => 7]],
                ['type' => 'message_stop'],
            ]),
        );
        $anyTruncated = false;
        foreach ($streamCapped->completeStream($this->turnRequest()) as $chunk) {
            $anyTruncated = $anyTruncated || $chunk->truncated;
        }
        $this->assertTrue($anyTruncated);
    }

    public function testStreamReassemblesToolArgumentsAcrossDeltas(): void
    {
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            null,
            $this->streamSeam([
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'ProvbEchoTool']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"say":']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => ' "hi"}']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use']],
                ['type' => 'message_stop'],
            ]),
        );

        $calls = [];
        foreach ($provider->completeStream($this->turnRequest()) as $chunk) {
            foreach ($chunk->toolCalls ?? [] as $call) {
                $calls[] = $call;
            }
        }
        $this->assertCount(1, $calls);
        $this->assertSame('ProvbEchoTool', $calls[0]->name());
        $this->assertSame(['say' => 'hi'], $calls[0]->arguments());
    }

    public function testStreamWithoutTerminalEventNamesTheNativeArm(): void
    {
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            null,
            $this->streamSeam([
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'partial']],
            ]),
        );

        $chunks = iterator_to_array($provider->completeStream($this->turnRequest()));
        $last = $chunks[count($chunks) - 1];

        $this->assertTrue($last->isError);
        $this->assertStringContainsString('Anthropic Messages', (string) $last->errorMessage, 'the cut-stream diagnostic names the native transport');
    }

    public function testErrorEventNamesTheNativeArmAndPropagatesTheServerError(): void
    {
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            null,
            $this->streamSeam([
                ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'Overloaded']],
            ]),
        );

        $chunks = iterator_to_array($provider->completeStream($this->turnRequest()));

        $this->assertCount(1, $chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertStringContainsString('Overloaded', (string) $chunks[0]->errorMessage);
        $this->assertStringNotContainsString('Vertex', (string) $chunks[0]->errorMessage, 'the native diagnostics never name the other host');
    }

    public function testAnEmptyTranscriptRefusalNamesTheNativeArmOnBothPaths(): void
    {
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            static fn (string $endpoint, array $body): array => [],
        );

        $unary = $provider->complete($this->turnRequest(messages: [new SystemMessage('only system')]));
        $this->assertTrue($unary->isError);
        $this->assertStringContainsString('Anthropic Messages: the Anthropic Messages API requires', (string) $unary->errorMessage);

        $chunks = iterator_to_array($provider->completeStream($this->turnRequest(messages: [])));
        $this->assertNotEmpty($chunks);
        $this->assertTrue($chunks[0]->isError);
        $this->assertStringContainsString('Anthropic Messages: the Anthropic Messages API requires', (string) $chunks[0]->errorMessage);
    }

    public function testTransportFailuresBecomeClassifiedErrorResponsesNotThrows(): void
    {
        $permanent = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            static fn (string $endpoint, array $body): array => throw new \RuntimeException('a bad thing'),
        );
        $response = $permanent->complete($this->turnRequest());
        $this->assertTrue($response->isError);
        $this->assertSame('a bad thing', $response->errorMessage);
        $this->assertFalse($response->errorTransient, 'an unclassified failure is permanent - same posture as every other arm');

        $transient = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            self::MODERN,
            static fn (string $endpoint, array $body): array => throw new ConnectException('connection refused', new GuzzleRequest('POST', $endpoint)),
        );
        $response = $transient->complete($this->turnRequest());
        $this->assertTrue($response->isError);
        $this->assertTrue($response->errorTransient, 'a Guzzle transfer death without a response is transient');
    }

    public function testContextWindowComesFromTheShippedTableAndTheOverrideOutranksIt(): void
    {
        $modern = new AnthropicProvider('sk-test', 'https://api.anthropic.com', self::MODERN);
        $this->assertSame(1_000_000, $modern->contextWindow(), 'the 4-6 family ships 1M, Bedrock-parity');

        $dated = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-sonnet-4-6@20260101');
        $this->assertSame(1_000_000, $dated->contextWindow(), 'the versioned alias reads the family row');

        $older = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-haiku-4-5');
        $this->assertSame(200_000, $older->contextWindow());

        $unknown = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-nextnext-9');
        $this->assertSame(200_000, $unknown->contextWindow(), 'an unlisted family takes the floor, not zero');

        $overridden = new AnthropicProvider('sk-test', 'https://api.anthropic.com', self::MODERN, contextWindowOverride: 64_000);
        $this->assertSame(64_000, $overridden->contextWindow(), 'the operator setting outranks the table');
    }

    public function testTheFactoryRoutesSizingAndPricingSettingsIntoTheProvider(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'anthropic',
            'apiKey' => 'k',
            'model' => 'provb-nonesuch-9',
            'contextWindow' => 432_000,
            // Operator rates are dollars per 1M tokens; the accessor answers per 1K.
            'modelPrices' => ['provb-nonesuch-9' => ['input' => 10.0, 'output' => 20.0]],
        ]);
        $this->assertInstanceOf(AnthropicProvider::class, $provider);
        $this->assertSame(432_000, $provider->contextWindow());
        $this->assertSame(0.01, $provider->costPer1kTokens('provb-nonesuch-9', 'input'));

        $unpriced = (new ProviderFactory())->create(['type' => 'anthropic', 'apiKey' => 'k', 'model' => 'provb-nonesuch-9']);
        $this->assertNull($unpriced->costPer1kTokens('provb-nonesuch-9', 'input'), 'loud-unknown replaces the compat arm database guess (disclosed behavior change)');
    }

    public function testWithModelRebuildsKeepingEverySeamAndSetting(): void
    {
        $captured = [];
        $provider = new AnthropicProvider(
            'sk-test',
            'https://gw.example/anthropic',
            self::MODERN,
            $this->nativeUnarySeam(['content' => []], $captured),
            null,
            ['claude-opus-4-1' => ['input' => 15.0, 'output' => 75.0]],
            false,
            null,
        );

        $rebound = $provider->withModel('claude-opus-4-1');

        $this->assertInstanceOf(RebindsModel::class, $rebound);
        $this->assertSame('claude-opus-4-1', $rebound->model());
        $this->assertFalse($rebound->promptCache(), 'the cache flag survives the rebind');
        $this->assertSame(0.015, $rebound->costPer1kTokens('claude-opus-4-1', 'input'), 'operator prices survive the rebind');
        $this->assertSame(200_000, $rebound->contextWindow(), 'the table follows the NEW model');

        $rebound->complete($this->turnRequest('claude-opus-4-1'));
        $this->assertSame('https://gw.example/anthropic/v1/messages', $captured[0], 'the custom base survives the rebind');
    }

    public function testCapabilitiesAndIdentity(): void
    {
        $provider = new AnthropicProvider('sk-test');

        $this->assertSame('anthropic', $provider->name());
        $this->assertTrue($provider->supportsStreaming());
        $this->assertTrue($provider->supportsFunctionCalling());
        $this->assertTrue($provider->supportsVision());
        $this->assertFalse($provider->supportsJsonSchema());
        $this->assertInstanceOf(MarksPromptCache::class, $provider);
        $this->assertTrue($provider->marksPromptCache(self::MODERN));
        $this->assertFalse($provider->marksPromptCache('gpt-5'));
        $this->assertTrue($provider->acceptsAssistantPrefill(self::MODERN));
        $this->assertFalse($provider->acceptsAssistantPrefill('gpt-5'));
    }

    public function testEmbeddingsAnswerEmptyLikeTheOtherChatProviders(): void
    {
        $provider = new AnthropicProvider('sk-test');
        $response = $provider->embeddings(new EmbeddingsRequest(model: self::MODERN, input: ['x']));
        $this->assertSame([], $response->embeddings);
    }
}
