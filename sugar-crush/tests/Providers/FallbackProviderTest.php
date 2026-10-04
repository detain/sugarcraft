<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\ServerException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\CompleteResponse;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\EmbeddingsRequest;
use SugarCraft\Crush\Providers\EmbeddingsResponse;
use SugarCraft\Crush\Providers\FallbackProvider;
use SugarCraft\Crush\Providers\ModelMetadata;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\ProviderInterface;
use SugarCraft\Crush\Providers\ProviderResponseException;
use SugarCraft\Crush\Providers\RebindsModel;
use SugarCraft\Crush\Providers\ReportsServedModel;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;

/**
 * Roadmap 5.13b: `fallbackModels` — switch to the next model on a transient
 * or overflow failure, only before the first token, never silently.
 */
final class FallbackProviderTest extends TestCase
{
    use HomeSandboxTrait;

    /** @var list<string> */
    private array $notices = [];

    private float $now = 1_000.0;

    /** @var array{order?: list<string>, requests?: list<CompleteRequest>} what the fakes were asked, in order */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->useHomeSandbox(sys_get_temp_dir() . '/fallback_home_' . getmypid() . '_' . uniqid('', true));
    }

    protected function tearDown(): void
    {
        $this->restoreHomeSandbox();
        parent::tearDown();
    }

    // ─── classification ──────────────────────────────────────────────────

    public function testOnlyTransientAndOverflowFailuresAreReasons(): void
    {
        self::assertSame('transient', FallbackProvider::reasonFor(self::overloaded()));
        self::assertSame('overflow', FallbackProvider::reasonFor(self::overflow()));
        self::assertNull(FallbackProvider::reasonFor(self::unauthorised()));
        self::assertNull(FallbackProvider::reasonFor(new \RuntimeException('something odd')));

        self::assertSame('transient', FallbackProvider::reasonFor(new CompleteResponse('', isError: true, errorMessage: 'busy', errorTransient: true)));
        self::assertSame('overflow', FallbackProvider::reasonFor(self::overflowResponse()));
        self::assertNull(FallbackProvider::reasonFor(new CompleteResponse('', isError: true, errorMessage: 'bad request')));
    }

    // ─── complete() ──────────────────────────────────────────────────────

    public function testATransientFailureFallsBackAndSaysSo(): void
    {
        $provider = $this->chain(['a' => [self::overloaded()], 'b' => [self::reply('from b')]]);

        $response = $provider->complete(self::requestFor('a'));

        self::assertSame('from b', $response->content);
        self::assertSame(['a', 'b'], $this->asked());
        self::assertCount(1, $this->notices);
        self::assertStringContainsString('a kept failing', $this->notices[0]);
        self::assertStringContainsString('went to b', $this->notices[0]);
    }

    public function testAPermanentFailureIsRethrownWithoutTryingAnotherModel(): void
    {
        $provider = $this->chain(['a' => [self::unauthorised()], 'b' => [self::reply('never')]]);

        try {
            $provider->complete(self::requestFor('a'));
            self::fail('a 401 must not be swallowed by a fallback');
        } catch (ClientException) {
        }

        self::assertSame(['a'], $this->asked());
        self::assertSame([], $this->notices);
    }

    public function testTheLastModelsFailureIsTheOneRaised(): void
    {
        $last = self::overloaded('c is down too');
        $provider = $this->chain(['a' => [self::overloaded()], 'b' => [self::overloaded()], 'c' => [$last]]);

        try {
            $provider->complete(self::requestFor('a'));
            self::fail('every model failed');
        } catch (ServerException $e) {
            self::assertSame($last, $e);
        }
        self::assertSame(['a', 'b', 'c'], $this->asked());
    }

    public function testAnErrorResponseClassifiedTransientFallsBackToo(): void
    {
        $provider = $this->chain([
            'a' => [new CompleteResponse('', isError: true, errorMessage: 'overloaded', errorTransient: true)],
            'b' => [self::reply('ok')],
        ]);

        self::assertSame('ok', $provider->complete(self::requestFor('a'))->content);
    }

    public function testAnOverflowGoesOnlyToALargerKnownWindow(): void
    {
        $provider = $this->chain(
            ['a' => [self::overflow()], 'small' => [self::reply('never')], 'unknown' => [self::reply('from unknown')]],
            ['a' => 8_000, 'small' => 8_000, 'unknown' => 0],
        );

        self::assertSame('from unknown', $provider->complete(self::requestFor('a'))->content);
        self::assertSame(['a', 'unknown'], $this->asked(), 'a same-size window would only overflow again');
        self::assertStringContainsString('could not fit the context', $this->notices[0]);
    }

    public function testAnOverflowWithNoLargerModelRaisesTheOverflow(): void
    {
        $overflow = self::overflow();
        $provider = $this->chain(['a' => [$overflow], 'b' => [self::reply('never')]], ['a' => 128_000, 'b' => 32_000]);

        try {
            $provider->complete(self::requestFor('a'));
            self::fail('no larger window exists');
        } catch (ProviderResponseException $e) {
            self::assertSame($overflow, $e);
        }
        self::assertSame(['a'], $this->asked());
    }

    // ─── the pin ─────────────────────────────────────────────────────────

    public function testATransientSwitchPinsTheFallbackUntilTheCooldownLapses(): void
    {
        $provider = $this->chain([
            'a' => [self::overloaded(), self::reply('a is back')],
            'b' => [self::reply('b1'), self::reply('b2')],
        ]);

        $provider->complete(self::requestFor('a'));
        self::assertSame('b', $provider->servedModel(), 'the status bar names the model answering');

        self::assertSame('b2', $provider->complete(self::requestFor('a'))->content, 'pinned: a is not retried yet');
        self::assertSame(['a', 'b', 'b'], $this->asked());

        $this->now += FallbackProvider::PIN_SECONDS + 1;
        self::assertNull($provider->servedModel());
        self::assertSame('a is back', $provider->complete(self::requestFor('a'))->content);
    }

    public function testAnOverflowSwitchDoesNotPin(): void
    {
        $provider = $this->chain(['a' => [self::overflow(), self::reply('a fits now')], 'b' => [self::reply('b')]], ['a' => 8_000, 'b' => 200_000]);

        $provider->complete(self::requestFor('a'));
        self::assertNull($provider->servedModel());
        self::assertSame('a fits now', $provider->complete(self::requestFor('a'))->content);
    }

    public function testThePinCrossesTheForkThroughNoteServedModel(): void
    {
        // The parent never saw the switch; the turn child's frame carries `b`.
        $parent = $this->chain(['a' => [self::reply('a')], 'b' => [self::reply('b')]]);
        $parent->noteServedModel('b');

        self::assertSame('b', $parent->servedModel());
        self::assertSame('b', $parent->complete(self::requestFor('a'))->content);
    }

    public function testAModelSwitchAwayFromThePinnedModelIsHonoured(): void
    {
        $provider = $this->chain([
            'a' => [self::overloaded()],
            'b' => [self::reply('b')],
            'c' => [self::reply('c, as chosen')],
        ]);
        $provider->complete(self::requestFor('a'));

        self::assertSame('c, as chosen', $provider->complete(self::requestFor('c'))->content);
    }

    // ─── completeStream() ────────────────────────────────────────────────

    public function testAStreamFallsBackBeforeItsFirstChunk(): void
    {
        $provider = $this->chain(['a' => [self::overloaded()], 'b' => [[self::reply('hel'), self::reply('lo')]]]);

        self::assertSame('hello', self::drain($provider->completeStream(self::requestFor('a'))));
        self::assertCount(1, $this->notices);
    }

    public function testAStreamNeverFallsBackAfterItsFirstChunk(): void
    {
        $late = self::overloaded('dropped mid-reply');
        $provider = $this->chain(['a' => [[self::reply('partial'), $late]], 'b' => [[self::reply('never')]]]);

        $seen = '';
        try {
            foreach ($provider->completeStream(self::requestFor('a')) as $chunk) {
                $seen .= $chunk->content;
            }
            self::fail('the mid-stream failure belongs to the caller');
        } catch (ServerException $e) {
            self::assertSame($late, $e);
        }
        self::assertSame('partial', $seen);
        self::assertSame(['a'], $this->asked());
        self::assertSame([], $this->notices);
    }

    public function testAnErrorFirstChunkFallsBack(): void
    {
        $provider = $this->chain([
            'a' => [[new CompleteResponse('', isError: true, errorMessage: 'overloaded', errorTransient: true)]],
            'b' => [[self::reply('fine')]],
        ]);

        self::assertSame('fine', self::drain($provider->completeStream(self::requestFor('a'))));
    }

    // ─── transparency ────────────────────────────────────────────────────

    public function testItAnswersAsThePrimaryAndPricesPerModel(): void
    {
        $provider = $this->chain(['a' => [], 'b' => []]);

        self::assertSame('fake-a', $provider->name());
        self::assertSame(['b'], $provider->fallbackModels());
        self::assertSame(0.5, $provider->costPer1kTokens('b', 'input'));
        self::assertSame(1.0, $provider->costPer1kTokens('a', 'input'));
        self::assertFalse($provider->marksPromptCache('a'));
    }

    /**
     * Why the provider contract rosters exempt this class: it has no wire of
     * its own, so every field but `model` must reach the wrapped provider as
     * sent, and every chunk must come back as yielded.
     */
    public function testRequestsAndChunksPassThroughUntouched(): void
    {
        $chunks = [new CompleteResponse('a', tokensUsed: 3, costUsd: 0.25), new CompleteResponse('b', tokensUsed: 4)];
        $provider = $this->chain(['a' => [$chunks[0], $chunks], 'b' => [$chunks[1]]]);
        $request = new CompleteRequest(model: 'a', messages: [], systemPrompt: 'be brief', temperature: 0.3, maxTokens: 99, sessionId: 's1');

        self::assertSame($chunks[0], $provider->complete($request));
        self::assertSame($chunks, iterator_to_array($provider->completeStream($request), false));
        self::assertSame([$request, $request], $this->calls['requests'], 'the primary is handed the very request');

        $provider->noteServedModel('b');
        self::assertSame($chunks[1], $provider->complete($request));
        self::assertSame(
            array_replace(get_object_vars($request), ['model' => 'b']),
            get_object_vars($this->calls['requests'][2]),
            'a fallback changes the model and nothing else',
        );
    }

    public function testThePrimaryKeepsItsOwnServedModelDiscovery(): void
    {
        $provider = $this->chain(['a' => [], 'b' => []]);

        $provider->noteServedModel('served-by-sglang');
        self::assertSame('served-by-sglang', $provider->servedModel());
    }

    public function testTheChainNeedsAModelOtherThanThePrimary(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FallbackProvider::new($this->fake('a', []), 'a', ['a' => fn () => $this->fake('a', [])]);
    }

    // ─── ProviderFactory ─────────────────────────────────────────────────

    public function testTheFactoryWrapsOnlyABlockThatNamesFallbacks(): void
    {
        $factory = new ProviderFactory(ModelMetadata::fromTable([]));
        $block = ['type' => 'custom', 'name' => 'gw', 'baseUrl' => 'http://127.0.0.1:9', 'model' => 'a'];

        self::assertInstanceOf(CustomProvider::class, $factory->create($block));
        self::assertInstanceOf(CustomProvider::class, $factory->create($block + ['fallbackModels' => ['a', '', 7]]), 'only the primary itself: nothing to fall back to');

        $wrapped = $factory->create($block + ['fallbackModels' => [' b ', 'c', 'b', 'a']]);
        self::assertInstanceOf(FallbackProvider::class, $wrapped);
        self::assertSame(['b', 'c'], $wrapped->fallbackModels());
        self::assertSame('gw', $wrapped->name());
        self::assertInstanceOf(CustomProvider::class, $wrapped->primary());

        self::assertSame(['b'], $factory->create($block + ['fallbackModels' => 'b'])->fallbackModels(), 'a single string is a one-model list');
    }

    public function testEachFallbackIsSizedAsItself(): void
    {
        $factory = new ProviderFactory(ModelMetadata::fromTable([
            'small-model' => ['max_input_tokens' => 8_000],
            'big-model' => ['max_input_tokens' => 200_000],
        ]));
        $wrapped = $factory->create([
            'type' => 'custom', 'name' => 'gw', 'baseUrl' => 'http://127.0.0.1:9',
            'model' => 'small-model', 'fallbackModels' => ['big-model'],
        ]);
        self::assertInstanceOf(FallbackProvider::class, $wrapped);
        self::assertSame(8_000, $wrapped->contextWindow());

        $wrapped->noteServedModel('big-model');
        self::assertSame(200_000, $wrapped->contextWindow(), 'while pinned, the window is the answering model\'s');
    }

    /** Roadmap 4.1-1: a model rebind reaches the wrapped primary, so the chain keeps the capability. */
    public function testAModelRebindReachesThePrimaryAndKeepsTheChain(): void
    {
        $factory = new ProviderFactory(ModelMetadata::fromTable([
            'small-model' => ['max_input_tokens' => 8_000],
            'mid-model' => ['max_input_tokens' => 50_000],
            'big-model' => ['max_input_tokens' => 200_000],
        ]));
        $wrapped = $factory->create([
            'type' => 'custom', 'name' => 'gw', 'baseUrl' => 'http://127.0.0.1:9',
            'model' => 'small-model', 'fallbackModels' => ['big-model'],
        ]);
        self::assertInstanceOf(FallbackProvider::class, $wrapped);
        self::assertInstanceOf(RebindsModel::class, $wrapped);

        $rebound = $wrapped->withModel('mid-model');
        self::assertInstanceOf(FallbackProvider::class, $rebound);
        self::assertSame(50_000, $rebound->contextWindow(), 'the window is the new model\'s');
        self::assertSame(['big-model'], $rebound->fallbackModels());
        self::assertSame(8_000, $wrapped->contextWindow(), 'the receiver is untouched');
    }

    // ─── helpers ─────────────────────────────────────────────────────────

    /**
     * @param array<string, list<\Throwable|CompleteResponse|list<\Throwable|CompleteResponse>>> $scripts
     *        model => outcomes, one per call; a list is a stream's chunks
     * @param array<string, int> $windows
     */
    private function chain(array $scripts, array $windows = []): FallbackProvider
    {
        $models = array_keys($scripts);
        $primaryModel = array_shift($models);
        $builders = [];
        foreach ($models as $model) {
            $builders[$model] = fn (): ProviderInterface => $this->fake($model, $scripts[$model], $windows[$model] ?? 0);
        }

        return FallbackProvider::new(
            $this->fake($primaryModel, $scripts[$primaryModel], $windows[$primaryModel] ?? 0),
            $primaryModel,
            $builders,
            fn (): float => $this->now,
            function (string $line): void {
                $this->notices[] = $line;
            },
        );
    }

    /** @param list<\Throwable|CompleteResponse|list<\Throwable|CompleteResponse>> $script */
    private function fake(string $model, array $script, int $window = 0): ProviderInterface
    {
        $calls = &$this->calls;

        return new class ($model, $script, $window, $calls) implements ProviderInterface, ReportsServedModel {
            private ?string $served = null;

            /** @param list<mixed> $script @param array<string, list<string>> $calls */
            public function __construct(private string $model, private array $script, private int $window, private array &$calls)
            {
            }

            public function name(): string
            {
                return 'fake-' . $this->model;
            }

            public function supportsStreaming(): bool
            {
                return true;
            }

            public function supportsFunctionCalling(): bool
            {
                return true;
            }

            public function supportsVision(): bool
            {
                return false;
            }

            public function supportsJsonSchema(): bool
            {
                return false;
            }

            public function contextWindow(): int
            {
                return $this->window;
            }

            public function costPer1kTokens(string $model, string $direction): ?float
            {
                return $this->model === 'a' ? 1.0 : 0.5;
            }

            public function complete(CompleteRequest $request): CompleteResponse
            {
                $outcome = $this->next($request);
                if ($outcome instanceof \Throwable) {
                    throw $outcome;
                }

                return $outcome;
            }

            public function completeStream(CompleteRequest $request): \Generator
            {
                $outcome = $this->next($request);
                foreach (is_array($outcome) ? $outcome : [$outcome] as $chunk) {
                    if ($chunk instanceof \Throwable) {
                        throw $chunk;
                    }
                    yield $chunk;
                }
            }

            public function embeddings(EmbeddingsRequest $request): EmbeddingsResponse
            {
                throw new \LogicException('not used');
            }

            public function servedModel(): ?string
            {
                return $this->served;
            }

            public function noteServedModel(string $servedModel): void
            {
                $this->served = $servedModel;
            }

            private function next(CompleteRequest $request): mixed
            {
                $this->calls['order'][] = $request->model;
                $this->calls['requests'][] = $request;
                if ($request->model !== $this->model) {
                    throw new \LogicException("fake {$this->model} was addressed to {$request->model}");
                }
                if ($this->script === []) {
                    throw new \LogicException("fake {$this->model} has no outcome left");
                }

                return array_shift($this->script);
            }
        };
    }

    /** @return list<string> */
    private function asked(): array
    {
        return $this->calls['order'] ?? [];
    }

    private static function requestFor(string $model): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: []);
    }

    private static function reply(string $text): CompleteResponse
    {
        return new CompleteResponse($text);
    }

    private static function drain(\Generator $stream): string
    {
        $text = '';
        foreach ($stream as $chunk) {
            $text .= $chunk->content;
        }

        return $text;
    }

    private static function overloaded(string $message = 'overloaded'): ServerException
    {
        return new ServerException($message, new Request('POST', '/v1/chat/completions'), new Response(503));
    }

    private static function unauthorised(): ClientException
    {
        return new ClientException('bad key', new Request('POST', '/v1/chat/completions'), new Response(401));
    }

    private static function overflowResponse(): CompleteResponse
    {
        return new CompleteResponse('', isError: true, errorMessage: "This model's maximum context length is 8192 tokens");
    }

    private static function overflow(): ProviderResponseException
    {
        return ProviderResponseException::fromResponse(self::overflowResponse());
    }
}
