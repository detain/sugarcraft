<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use GuzzleHttp\Psr7\Utils;
use OpenAI\Contracts\ClientContract;
use OpenAI\Contracts\Resources\ChatContract;
use OpenAI\Responses\Chat\CreateResponse as ChatCreateResponse;
use OpenAI\Responses\Chat\CreateStreamedResponse as ChatCreateStreamedResponse;
use OpenAI\Responses\Meta\MetaInformation;
use OpenAI\Responses\StreamResponse;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Cli\LaunchContext;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\AnthropicProvider;
use SugarCraft\Code\Providers\BedrockProvider;
use SugarCraft\Code\Providers\ClaudeCodeProvider;
use SugarCraft\Code\Providers\CodexProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\EchoProvider;
use SugarCraft\Code\Providers\GeminiProvider;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Providers\ShapesServiceTier;
use SugarCraft\Code\Providers\VertexProvider;
use SugarCraft\Code\Tests\Support\HomeSandboxTrait;

/**
 * C-PRV-3.a: the `serviceTier` setting and the provider overlay it lowers.
 *
 * The banned failure is a config word that silently buys nothing (or worse,
 * silently buys money): a saved `fast` riding onto a pair with no field to
 * read it, or a wire that DOES spend the premium lane while the ledger keeps
 * billing the standard rate. Every pin below is one of the three honest
 * answers the code is allowed to give — field present at the premium lane,
 * field absent because unset (byte-identical history), or the pair refuses
 * and the gate says so with the engine left as built.
 *
 * The Anthropic half rests on the current Messages API docs: `speed` is
 * "standard" | "fast" on claude-opus-5-5 (GA, no header) and claude-opus-5 /
 * claude-opus-4-8 (research preview, which 400 without the
 * `anthropic-beta: fast-mode-2026-02-01` header); every other family errors
 * and so shapes nothing. Pricing for the fast lane is not derivable from the
 * model-keyed tables, so an armed pair answers costPer1kTokens null — the
 * disclosed-unpriced account, never a guessed zero (C-PRV-3.b owns the
 * tier-keyed table).
 */
final class ServiceTierTest extends TestCase
{
    use HomeSandboxTrait;

    private const TIER_ENV = 'CANDY_CODE_SERVICE_TIER';

    private string $tierSandboxHome = '';

    /** @var array<string, mixed> */
    private array $chatCapture = [];

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $responsesLog = [];

    /** @var array{0: string, 1: array<string, mixed>}|null */
    private ?array $anthropicCapture = null;

    private string|false|null $savedTierEnv = null;

    protected function setUp(): void
    {
        parent::setUp();

        // resolvedServiceTier() reads Bootstrap::readUserConfig(); an empty
        // sandboxed home keeps the operator's real config out of every pin
        // below (same law as LaunchEffortNoticeTest).
        $this->tierSandboxHome = sys_get_temp_dir() . '/prv3a_service_tier_home_' . uniqid('', true);
        $this->useHomeSandbox($this->tierSandboxHome);
        Bootstrap::useConfigPath(null);

        $this->savedTierEnv = getenv(self::TIER_ENV);
        putenv(self::TIER_ENV);
        $this->chatCapture = [];
        $this->responsesLog = [];
        $this->anthropicCapture = null;
    }

    protected function tearDown(): void
    {
        Bootstrap::useConfigPath(null);
        $this->restoreHomeSandbox();

        if ($this->savedTierEnv === false || $this->savedTierEnv === null) {
            putenv(self::TIER_ENV);
        } else {
            putenv(self::TIER_ENV . '=' . $this->savedTierEnv);
        }

        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // 1. The roster — who may shape a fast lane at all.
    // -------------------------------------------------------------------------

    public function testTheShaperRosterIsExactlyTheTwoConfirmedWires(): void
    {
        // openai answers `service_tier: priority` on both arms (API docs);
        // anthropic answers `speed: fast` on the gated families (API docs).
        // Every other provider stays off this roster, which is what makes the
        // launch gate's refusal a loud one rather than silence. Pinned
        // against the classes, not a narrated list (EffortBudgetTest law).
        $shapers = [];
        foreach ($this->providerClasses() as $class) {
            if (is_subclass_of($class, ShapesServiceTier::class)) {
                $shapers[] = $class;
            }
        }

        self::assertSame([OpenAIProvider::class, AnthropicProvider::class], $shapers);
    }

    public function testTheRefusalSetCarriesTheMarkerNotAtAll(): void
    {
        foreach ([CustomProvider::class, EchoProvider::class, SglangProvider::class, GeminiProvider::class, VertexProvider::class, BedrockProvider::class, CodexProvider::class, ClaudeCodeProvider::class] as $class) {
            self::assertNotContains(ShapesServiceTier::class, class_implements($class) ?: [], $class . ' must stay a loud refusal');
        }
    }

    // -------------------------------------------------------------------------
    // 2. The openai chat arm — `service_tier` present only when armed.
    // -------------------------------------------------------------------------

    public function testTheChatArmSendsServiceTierPriorityWhenArmed(): void
    {
        $provider = $this->tierChatProvider(OpenAIProvider::SERVICE_TIER_PRIORITY);
        $provider->complete($this->tierTurn('gpt-4o'));

        self::assertSame('priority', $this->chatCapture['service_tier'] ?? null, 'armed fast rides the documented chat field');
    }

    public function testTheChatArmSendsNoFieldWhenUnarmed(): void
    {
        $provider = $this->tierChatProvider(null);
        $provider->complete($this->tierTurn('gpt-4o'));

        self::assertArrayNotHasKey('service_tier', $this->chatCapture, 'unset is the historical byte-identical body');
    }

    public function testTheChatStreamArmCarriesTheSameLeg(): void
    {
        $provider = $this->tierChatProvider(OpenAIProvider::SERVICE_TIER_PRIORITY);
        $provider->completeStream($this->tierTurn('gpt-4o'))->current();

        self::assertSame('priority', $this->chatCapture['service_tier'] ?? null);
    }

    // -------------------------------------------------------------------------
    // 3. The openai Responses arm — same field, raw JSON body.
    // -------------------------------------------------------------------------

    public function testTheResponsesArmSendsServiceTierPriorityWhenArmed(): void
    {
        $provider = $this->tierResponsesProvider(OpenAIProvider::SERVICE_TIER_PRIORITY);
        $provider->complete($this->tierTurn('gpt-4o-mini'));

        self::assertSame('priority', $this->lastResponsesBody()['service_tier'] ?? null);
    }

    public function testTheResponsesArmSendsNoFieldWhenUnarmed(): void
    {
        $provider = $this->tierResponsesProvider(null);
        $provider->complete($this->tierTurn('gpt-4o-mini'));

        self::assertArrayNotHasKey('service_tier', $this->lastResponsesBody());
    }

    public function testTheResponsesStreamArmCarriesTheSameLeg(): void
    {
        $provider = $this->tierResponsesProvider(OpenAIProvider::SERVICE_TIER_PRIORITY, streamed: true);
        $provider->completeStream($this->tierTurn('gpt-4o-mini'))->current();

        self::assertSame('priority', $this->lastResponsesBody()['service_tier'] ?? null, 'both Responses doors ride the same body builder');
    }

    // -------------------------------------------------------------------------
    // 4. The anthropic body — `speed: fast` on the gated families only.
    // -------------------------------------------------------------------------

    public function testTheFastFamiliesStampSpeedAndOthersDoNot(): void
    {
        foreach (['claude-opus-5-5', 'claude-opus-5', 'claude-opus-4-8', 'claude-opus-4-8-20260115'] as $model) {
            $captured = [];
            $provider = $this->tierAnthropic($model, ShapesServiceTier::WORD_FAST, $captured);
            $provider->complete($this->tierTurn($model));

            self::assertArrayHasKey('speed', $captured[1], $model . ' is a fast-mode family and must carry the field');
            self::assertSame('fast', $captured[1]['speed'], $model . ' rides the premium lane');
        }

        foreach (['claude-sonnet-4-6', 'claude-opus-4-7', 'claude-haiku-4-5'] as $model) {
            $captured = [];
            $provider = $this->tierAnthropic($model, ShapesServiceTier::WORD_FAST, $captured);
            $provider->complete($this->tierTurn($model));

            self::assertArrayNotHasKey('speed', $captured[1], $model . ' errors on a speed field — the wire sends it none');
        }
    }

    public function testTheUnarmedAnthropicSendsNoSpeedOnAnyFamily(): void
    {
        $captured = [];
        $provider = $this->tierAnthropic('claude-opus-5-5', null, $captured);
        $provider->complete($this->tierTurn('claude-opus-5-5'));

        self::assertArrayNotHasKey('speed', $captured[1], 'no tier, no field — the body stays historical');
    }

    public function testTheStreamArmStampsSpeedOnTheSameFamilies(): void
    {
        $captured = [];
        $stream = static function (string $endpoint, array $body) use (&$captured): iterable {
            $captured = [$endpoint, $body];
            yield ['type' => 'message_stop'];
        };
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            'claude-opus-5',
            null,
            $stream,
            serviceTier: ShapesServiceTier::WORD_FAST,
        );
        $provider->completeStream($this->tierTurn('claude-opus-5'))->current();

        self::assertSame('fast', $captured[1]['speed'] ?? null);
    }

    // -------------------------------------------------------------------------
    // 5. The research-preview beta gate — opus-5/opus-4-8 yes, opus-5-5 no.
    // -------------------------------------------------------------------------

    public function testFastModeRequestOptionsFollowTheApiGate(): void
    {
        $door = new ReflectionMethod(AnthropicProvider::class, 'fastModeRequestOptions');

        // Unarmed: never a header, never on any model.
        $quiet = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-opus-5');
        self::assertSame([], $door->invoke($quiet, ['model' => 'claude-opus-5']), 'unarmed asks nothing');

        $armed = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-opus-5', serviceTier: ShapesServiceTier::WORD_FAST);

        self::assertSame(
            ['headers' => ['anthropic-beta' => AnthropicProvider::HEADER_FAST_MODE_BETA]],
            $door->invoke($armed, ['model' => 'claude-opus-5']),
            'the research-preview family needs the header or the API answers 400',
        );
        self::assertSame(
            ['headers' => ['anthropic-beta' => AnthropicProvider::HEADER_FAST_MODE_BETA]],
            $door->invoke($armed, ['model' => 'claude-opus-4-8']),
        );
        self::assertSame(
            [],
            $door->invoke($armed, ['model' => 'claude-opus-5-5']),
            'opus-5-5 is GA: a beta header would be noise, and the field alone is the lane',
        );
        self::assertSame(
            [],
            $door->invoke($armed, ['model' => 'claude-sonnet-4-6']),
            'a non-fast family gets neither the field nor the header',
        );
    }

    // -------------------------------------------------------------------------
    // 6. EngineBackend overlay — the word rides, the wire is the provider's.
    // -------------------------------------------------------------------------

    public function testTheEngineOverlayArmsTheProviderPairForFast(): void
    {
        $engine = EngineBackend::new($this->tierChatProvider(null), 'gpt-4o');
        $this->assertNull($engine->serviceTier());

        $armed = $engine->withServiceTier(ShapesServiceTier::WORD_FAST);

        self::assertSame('fast', $armed->serviceTier(), 'the word rides the engine');
        self::assertNotSame($engine, $armed, 'immutable clone, never an in-place write');
        self::assertNotSame($engine->provider(), $armed->provider(), 'the provider leg was re-minted');

        $armed->provider()->complete($this->tierTurn('gpt-4o'));
        self::assertSame('priority', $this->chatCapture['service_tier'] ?? null, 'the wire value resolves through serviceTierFor at the seam');
    }

    public function testTheEngineOverlayClearsBackToDefault(): void
    {
        $engine = EngineBackend::new($this->tierChatProvider(null), 'gpt-4o')->withServiceTier(ShapesServiceTier::WORD_FAST);
        $cleared = $engine->withServiceTier(ShapesServiceTier::WORD_DEFAULT);

        self::assertSame('default', $cleared->serviceTier());
        $this->chatCapture = [];
        $cleared->provider()->complete($this->tierTurn('gpt-4o'));
        self::assertArrayNotHasKey('service_tier', $this->chatCapture, 'the default word clears the provider leg');
    }

    public function testANonShapingProviderStoresTheWordButSendsNothing(): void
    {
        $engine = EngineBackend::new(new EchoProvider(), 'gpt-4o')->withServiceTier(ShapesServiceTier::WORD_FAST);

        self::assertSame('fast', $engine->serviceTier(), 'the engine keeps what it was told');
        self::assertFalse($engine->provider() instanceof ShapesServiceTier, 'and the provider that cannot shape stays untouched');
    }

    public function testAModelRebindUnderAnArmedWordReResolvesTheWire(): void
    {
        // Armed while the pair shapes nothing (sonnet): the provider stays
        // un-armed. A /model move to a fast family re-consults
        // serviceTierFor against the NEW model, so the premium lane follows
        // the pair that can actually pay for it — and a word armed at an
        // unshapeable pair never silently stamps speed onto it either.
        $provider = new AnthropicProvider(
            'sk-test',
            'https://api.anthropic.com',
            'claude-sonnet-4-6',
            $this->tierAnthropicPropertySeam(),
        );
        $engine = EngineBackend::new($provider, 'claude-sonnet-4-6')->withServiceTier(ShapesServiceTier::WORD_FAST);

        $this->anthropicCapture = null;
        $engine->provider()->complete($this->tierTurn('claude-sonnet-4-6'));
        self::assertArrayNotHasKey('speed', $this->anthropicCapture[1], 'the sonnet pair is not armed by the engine word alone');

        $this->anthropicCapture = null;
        $rebound = $engine->withModel('claude-opus-5-5');
        $rebound->provider()->complete($this->tierTurn('claude-opus-5-5'));
        self::assertSame('fast', $this->anthropicCapture[1]['speed'] ?? null, 'the rebind re-resolves the wire for the new pair');
    }

    // -------------------------------------------------------------------------
    // 7. The launch door — resolvedServiceTier + withResolvedServiceTier.
    // -------------------------------------------------------------------------

    public function testUnsetEverythingLeavesTheEngineAsBuilt(): void
    {
        self::assertNull(LaunchContext::resolvedServiceTier());

        [$carried, $reason] = LaunchContext::withResolvedServiceTier(EngineBackend::new(new EchoProvider(), 'm'));
        self::assertNull($reason);
        self::assertNull($carried->serviceTier());
    }

    public function testTheEnvWordWinsOverTheSavedWord(): void
    {
        Bootstrap::writeUserConfig(['serviceTier' => 'default']);
        putenv(self::TIER_ENV . '=FAST ');

        self::assertSame('fast', LaunchContext::resolvedServiceTier(), 'the launch override beats the persisted value; case and padding normalize');

        putenv(self::TIER_ENV . '=');
        self::assertSame('default', LaunchContext::resolvedServiceTier(), 'a cleared env falls through to the saved word');
    }

    public function testAnUnrecognizedWordAsksNothing(): void
    {
        putenv(self::TIER_ENV . '=turbo');
        self::assertNull(LaunchContext::resolvedServiceTier(), 'a junk env value is not a tier');
        putenv(self::TIER_ENV . '=PRIORITY');
        self::assertNull(LaunchContext::resolvedServiceTier(), 'a wire value is not a config word either — the vocabulary is default|fast');
    }

    public function testFastOnAShapingPairRidesWithNoReason(): void
    {
        Bootstrap::writeUserConfig(['serviceTier' => 'fast']);

        [$carried, $reason] = LaunchContext::withResolvedServiceTier(
            EngineBackend::new($this->tierChatProvider(null), 'gpt-4o'),
        );

        self::assertSame('fast', $carried->serviceTier());
        self::assertNull($reason, 'a tier that shapes is not a warning');
    }

    public function testFastOnANonShapingPairComesBackAsEngineAndReason(): void
    {
        Bootstrap::writeUserConfig(['serviceTier' => 'fast']);
        $engine = EngineBackend::new(new EchoProvider(), 'gpt-4o');

        [$carried, $reason] = LaunchContext::withResolvedServiceTier($engine);

        self::assertSame($engine, $carried, 'a verdict of no hands the SAME engine back — the word is not even stored');
        self::assertNull($carried->serviceTier(), 'a status surface must read the truth: no tier armed');
        self::assertIsString($reason);
        self::assertStringContainsString('fast', $reason);
        self::assertStringContainsString('echo', $reason);
        self::assertStringContainsString('gpt-4o', $reason);
        self::assertStringContainsString('left off this launch', $reason, 'the row says where the tier did NOT go');
    }

    public function testFastOnAFamilyTheShaperGatesOffRefusesWithARow(): void
    {
        Bootstrap::writeUserConfig(['serviceTier' => 'fast']);
        $engine = EngineBackend::new(
            new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-sonnet-4-6', $this->tierAnthropicPropertySeam()),
            'claude-sonnet-4-6',
        );

        [$carried, $reason] = LaunchContext::withResolvedServiceTier($engine);

        self::assertSame($engine, $carried, 'sonnet is on the roster of providers but not of fast families — pair-level refusal, same honesty');
        self::assertNull($carried->serviceTier());
        self::assertStringContainsString('claude-sonnet-4-6', (string) $reason);
    }

    public function testTheDefaultWordIsAppliedWithoutConsultingTheGate(): void
    {
        Bootstrap::writeUserConfig(['serviceTier' => 'default']);
        $engine = EngineBackend::new(new EchoProvider(), 'gpt-4o');

        [$carried, $reason] = LaunchContext::withResolvedServiceTier($engine);

        self::assertSame('default', $carried->serviceTier(), 'every pair is on the standard lane, shaper or not');
        self::assertNull($reason, 'default never refuses');
    }

    // -------------------------------------------------------------------------
    // 8. The ledger — an armed pair must not bill the standard rate.
    // -------------------------------------------------------------------------

    public function testTheOpenAiPriceDoorClosesWhileArmed(): void
    {
        $provider = $this->tierChatProvider(null);
        self::assertSame(0.0025, $provider->costPer1kTokens('gpt-4o', 'input'), 'unarmed keeps the table');

        $armed = $provider->withServiceTier(OpenAIProvider::SERVICE_TIER_PRIORITY);
        self::assertNull($armed->costPer1kTokens('gpt-4o', 'input'), 'priority bills higher and the premium is not in the model-keyed table — no figure is honest, never a guessed one');
        self::assertNull($armed->costPer1kTokens('gpt-4o', 'output'));
    }

    public function testTheAnthropicPriceDoorClosesPerPair(): void
    {
        $armed = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-opus-5-5', serviceTier: ShapesServiceTier::WORD_FAST);

        self::assertSame(0.003, $armed->costPer1kTokens('claude-sonnet-4-6', 'input'), 'a pair that gets no speed field keeps its standard rate — pair-precise, not provider-blanket');
        self::assertNull($armed->costPer1kTokens('claude-opus-5-5', 'input'), 'the fast pair has no derivable premium');
        self::assertNull($armed->costPer1kTokens('claude-opus-5-5', 'output'));
        self::assertNull($armed->cacheCostPer1kTokens('claude-opus-5-5', 'read'), 'the cache door closes with the pair');

        $quiet = new AnthropicProvider('sk-test', 'https://api.anthropic.com', 'claude-opus-5-5');
        self::assertSame(0.004, $quiet->costPer1kTokens('claude-opus-5-5', 'input'), 'unarmed stands the table');
        self::assertIsFloat($quiet->cacheCostPer1kTokens('claude-opus-5-5', 'read'), 'unarmed cache pricing rides the trait, untouched');
    }

    // -------------------------------------------------------------------------
    // harness
    // -------------------------------------------------------------------------

    private function tierTurn(string $model): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: [new UserMessage('go')]);
    }

    /**
     * A chat-arm openai provider with an optional armed tier; every create()
     * argument lands in $chatCapture — the ProviderRequestResponseTest shape,
     * named uniquely for this file (DuplicatedTestHelperDrift).
     */
    private function tierChatProvider(?string $wire): OpenAIProvider
    {
        $client = $this->createMock(ClientContract::class);
        $chat = $this->createMock(ChatContract::class);
        $client->method('chat')->willReturn($chat);
        $chat->method('create')->willReturnCallback(function (array $params): ChatCreateResponse {
            $this->chatCapture = $params;

            return ChatCreateResponse::from([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion',
                'created' => 1,
                'model' => $params['model'],
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'ok'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], MetaInformation::from([]));
        });
        $chat->method('createStreamed')->willReturnCallback(function (array $params): StreamResponse {
            $this->chatCapture = $params;
            $body = 'data: ' . json_encode([
                'id' => 'chatcmpl-1',
                'object' => 'chat.completion.chunk',
                'created' => 1,
                'model' => $params['model'],
                'choices' => [['index' => 0, 'delta' => ['content' => 'Hello']]],
            ]) . "\n\ndata: [DONE]\n\n";

            return new StreamResponse(
                ChatCreateStreamedResponse::class,
                new GuzzleResponse(200, [], Utils::streamFor($body)),
            );
        });

        $provider = new OpenAIProvider($client, 'gpt-4o');

        return $wire === null ? $provider : $provider->withServiceTier($wire);
    }

    /**
     * @param array{0: string, 1: array<string, mixed>} $captured
     */
    private function tierAnthropic(string $model, ?string $wire, array &$captured): AnthropicProvider
    {
        $captured = ['', []];
        $seam = static function (string $endpoint, array $body) use (&$captured): array {
            $captured = [$endpoint, $body];

            return ['content' => []];
        };

        $provider = new AnthropicProvider('sk-test', 'https://api.anthropic.com', $model, $seam);

        return $wire === null ? $provider : $provider->withServiceTier($wire);
    }

    /**
     * A unary seam that records into $anthropicCapture, so clones built by
     * withModel()/withServiceTier() keep writing where this file reads.
     */
    private function tierAnthropicPropertySeam(): callable
    {
        return function (string $endpoint, array $body): array {
            $this->anthropicCapture = [$endpoint, $body];

            return ['content' => []];
        };
    }

    private function tierResponsesProvider(?string $wire, bool $streamed = false): OpenAIProvider
    {
        $this->responsesLog = [];
        $jsonDoc = new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'resp_ok',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'hi']]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
        ]));
        $sseDoc = new GuzzleResponse(200, ['Content-Type' => 'text/event-stream'], "event: response.completed\ndata: " . json_encode([
            'type' => 'response.completed',
            'response' => ['id' => 'resp_s', 'output' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2]],
        ]) . "\n\n");
        $stack = HandlerStack::create(new MockHandler($streamed ? [$sseDoc] : [$jsonDoc]));
        $stack->push(Middleware::history($this->responsesLog));

        $provider = new OpenAIProvider(
            $this->createMock(ClientContract::class),
            'gpt-4o-mini',
            [],
            modelsBaseUrl: 'https://gw.test/v1',
            modelsApiKey: 'sk-test',
            api: OpenAIProvider::API_RESPONSES,
            responsesClient: new GuzzleClient(['handler' => $stack]),
        );

        return $wire === null ? $provider : $provider->withServiceTier($wire);
    }

    /** @return array<string, mixed> */
    private function lastResponsesBody(): array
    {
        $entry = end($this->responsesLog);
        self::assertNotFalse($entry, 'the MockHandler client saw the request');

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $entry['request']->getBody(), true);

        return $decoded;
    }

    /** @return list<class-string> */
    private function providerClasses(): array
    {
        return [
            OpenAIProvider::class,
            SglangProvider::class,
            AnthropicProvider::class,
            VertexProvider::class,
            GeminiProvider::class,
            BedrockProvider::class,
            CodexProvider::class,
            CustomProvider::class,
            ClaudeCodeProvider::class,
            EchoProvider::class,
        ];
    }
}
