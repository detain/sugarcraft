<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\SglangServerInfo;
use SugarCraft\Crush\Providers\ToolCallParser\DsmlToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Tests\Support\DiscardsErrorLogTrait;
use SugarCraft\Crush\Util\TokenEstimate;

/**
 * Audit 15a A18 (revised, user decision 2026-10-02): SglangProvider's limits
 * come from the live server first - the context window from
 * `max_req_input_len`, the default `max_tokens` as
 * `min(262144, window − estimated prompt − margin)` - with a per-family
 * fallback table, and `maxOutputTokens` still winning.
 *
 * Before A18 every request without `maxOutputTokens` carried a flat
 * `max_tokens: 4096`, which a max-effort think could spend entirely on
 * reasoning (`finish_reason: length`, empty reply), and the window was a
 * transcription that had already decayed (744,506 against a live 995,898).
 *
 * No test here reaches a server: discovery is injected as a loader closure or
 * pointed at a {@see MockHandler}.
 */
final class SglangProviderServerLimitsTest extends TestCase
{
    use DiscardsErrorLogTrait;

    private const SERVED_QWEN = 'Qwen/Qwen3.8-Flash-Next-FP8';

    /** @var list<array<string, mixed>> */
    private array $history = [];

    protected function setUp(): void
    {
        RuntimeNoticeSink::reset();
        RuntimeNoticeSink::arm(false);
    }

    protected function tearDown(): void
    {
        RuntimeNoticeSink::reset();
    }

    private static function skynet2(): SglangServerInfo
    {
        $info = SglangServerInfo::fromResponses(
            json_decode((string) file_get_contents(__DIR__ . '/../fixtures/sglang-model-info-qwen3.8.json'), true),
            json_decode((string) file_get_contents(__DIR__ . '/../fixtures/sglang-server-info-qwen3.8.json'), true),
        );
        self::assertNotNull($info);

        return $info;
    }

    /**
     * @param (\Closure(): ?SglangServerInfo)|null $loader
     */
    private function provider(
        string $model,
        ?\Closure $loader = null,
        string $body = '{"choices":[{"message":{"content":"ok"},"finish_reason":"stop"}],"usage":{"total_tokens":1}}',
        ?ToolCallParserInterface $parser = null,
    ): SglangProvider {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], $body), new Response(200, [], $body)]));
        $stack->push(Middleware::history($this->history));

        return new SglangProvider(
            'https://skynet2.interserver.net/v1',
            $model,
            null,
            new Client(['base_uri' => 'https://skynet2.interserver.net/v1/', 'handler' => $stack]),
            $parser,
            serverInfoLoader: $loader,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sentBody(int $index = 0): array
    {
        $decoded = json_decode((string) $this->history[$index]['request']->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * The estimate the provider is specified to use, recomputed from what it
     * actually SENT - so these tests pin the formula, not a magic number.
     *
     * @param array<string, mixed> $sent
     */
    private static function expectedDefault(int $total, array $sent): int
    {
        $estimate = TokenEstimate::ofText((string) json_encode(
            ['messages' => $sent['messages'], 'tools' => $sent['tools'] ?? []],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
        ));
        $room = $total - $estimate - max(8192, intdiv($estimate, 4));

        return max(4096, min(262_144, $room));
    }

    private static function request(string $model, string $prompt = 'Hi', ?int $maxTokens = null): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: [new UserMessage($prompt)], maxTokens: $maxTokens);
    }

    // -------------------------------------------------------------------------
    // contextWindow(): live first, transcription as fallback
    // -------------------------------------------------------------------------

    public function testTheContextWindowIsDerivedFromTheDiscoveredInputCeiling(): void
    {
        $provider = $this->provider(self::SERVED_QWEN, static fn (): SglangServerInfo => self::skynet2());

        // min(1,000,000, 999,994 − 4,096), where the transcription says 744,506.
        self::assertSame(995_898, $provider->contextWindow());
    }

    public function testWithoutALoaderTheTranscribedFamilyWindowsStillApply(): void
    {
        self::assertSame(744_506, $this->provider(self::SERVED_QWEN)->contextWindow());
        self::assertSame(1_048_570, $this->provider(SglangProvider::DEFAULT_MODEL)->contextWindow());
        self::assertSame(196_608, $this->provider('MiniMax-M2.7')->contextWindow());
    }

    /**
     * Discovery runs ONCE per provider - on the render path that is the
     * difference between one bounded read and one per frame - and a failure
     * is memoised just like a success.
     */
    public function testTheLoaderRunsOnceAndAFailureIsMemoisedToo(): void
    {
        $calls = 0;
        $provider = $this->provider(self::SERVED_QWEN, static function () use (&$calls): ?SglangServerInfo {
            $calls++;

            return null;
        });

        self::assertSame(744_506, $provider->contextWindow());
        self::assertSame(744_506, $provider->contextWindow());
        $provider->complete(self::request(self::SERVED_QWEN));
        self::assertNull($provider->serverInfo());
        self::assertSame(1, $calls);
    }

    public function testASuccessfulReadIsSharedByTheWindowAndTheRequestPath(): void
    {
        $calls = 0;
        $provider = $this->provider(self::SERVED_QWEN, static function () use (&$calls): SglangServerInfo {
            $calls++;

            return self::skynet2();
        });

        $provider->contextWindow();
        $provider->complete(self::request(self::SERVED_QWEN));
        $provider->contextWindow();

        self::assertSame(1, $calls);
    }

    public function testAThrowingLoaderDegradesToTheFallback(): void
    {
        $provider = $this->provider(self::SERVED_QWEN, static function (): SglangServerInfo {
            throw new \RuntimeException('boom');
        });

        self::assertSame(744_506, $provider->contextWindow());
        self::assertNull($provider->serverInfo());
    }

    public function testTheFactoryHelperArmsNothingUnlessAsked(): void
    {
        $provider = SglangProvider::openAiCompatible('http://127.0.0.1:1/v1', self::SERVED_QWEN);

        self::assertNull($provider->serverInfo(), 'no loader may be armed by default - and none may dial out');
    }

    // -------------------------------------------------------------------------
    // max_tokens: min(262144, room), maxOutputTokens wins
    // -------------------------------------------------------------------------

    /**
     * The A18 regression itself: the default request on the served model used
     * to carry `max_tokens: 4096`.
     */
    public function testASmallPromptOnTheDiscoveredServerGetsTheFullCap(): void
    {
        $provider = $this->provider(self::SERVED_QWEN, static fn (): SglangServerInfo => self::skynet2());
        $provider->complete(self::request(self::SERVED_QWEN));

        self::assertSame(262_144, $this->sentBody()['max_tokens']);
    }

    public function testTheStreamingPathSendsTheSameDerivedDefault(): void
    {
        $sse = 'data: {"choices":[{"delta":{"content":"ok"},"finish_reason":"stop"}]}' . "\n"
            . 'data: [DONE]' . "\n";
        $provider = $this->provider(self::SERVED_QWEN, static fn (): SglangServerInfo => self::skynet2(), $sse);

        foreach ($provider->completeStream(self::request(self::SERVED_QWEN)) as $_) {
            // drain
        }

        $sent = $this->sentBody();
        self::assertTrue($sent['stream']);
        self::assertSame(262_144, $sent['max_tokens']);
    }

    /**
     * Prompt and output share the window, and SGLang 400s a request whose
     * input plus `max_tokens` overruns it, so a large prompt shrinks the
     * default instead of sending the flat cap.
     */
    public function testALargePromptShrinksTheDefaultToTheRoomItLeaves(): void
    {
        $provider = $this->provider(
            self::SERVED_QWEN,
            static fn (): SglangServerInfo => SglangServerInfo::new(contextLength: 100_000, maxReqInputLen: 99_994),
        );
        // ~40k estimated tokens of ASCII.
        $provider->complete(self::request(self::SERVED_QWEN, str_repeat('abcd', 40_000)));

        $sent = $this->sentBody();
        $expected = self::expectedDefault(100_000, $sent);
        self::assertSame($expected, $sent['max_tokens']);
        self::assertLessThan(262_144, $expected);
        self::assertGreaterThan(4096, $expected, 'the fixture must exercise the clamp, not the floor');
    }

    public function testAPromptAtTheEdgeOfTheWindowGetsTheFloorNotANegativeBudget(): void
    {
        $provider = $this->provider(
            self::SERVED_QWEN,
            static fn (): SglangServerInfo => SglangServerInfo::new(contextLength: 20_000),
        );
        $provider->complete(self::request(self::SERVED_QWEN, str_repeat('abcd', 16_000)));

        self::assertSame(4096, $this->sentBody()['max_tokens']);
    }

    /**
     * `maxOutputTokens` arrives as `CompleteRequest::$maxTokens` and wins
     * outright - over the cap and over the clamp.
     */
    public function testAnExplicitMaxTokensWinsOverTheDerivedDefault(): void
    {
        $provider = $this->provider(
            self::SERVED_QWEN,
            static fn (): SglangServerInfo => SglangServerInfo::new(contextLength: 20_000),
        );
        $provider->complete(self::request(self::SERVED_QWEN, 'Hi', maxTokens: 300_000));

        self::assertSame(300_000, $this->sentBody()['max_tokens']);
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function fallbackTable(): array
    {
        return [
            'Qwen3.8 (served id)' => [self::SERVED_QWEN, 262_144],
            'Qwen3.8 (configured alias)' => ['Qwen/Qwen3.8-Flash-Next', 262_144],
            'DeepSeek-V4' => [SglangProvider::DEFAULT_MODEL, 262_144],
            // Unknown window: the conservative pre-A18 figure, not a guess.
            'MiniMax' => ['MiniMax-M2.7', 4096],
            'unknown' => ['some-local-model', 4096],
        ];
    }

    /**
     * Discovery not armed (or failed): the family table decides.
     */
    #[DataProvider('fallbackTable')]
    public function testTheFallbackTableIsKeyedByModelFamily(string $model, int $expected): void
    {
        $provider = $this->provider($model);
        $provider->complete(self::request($model));

        self::assertSame($expected, $this->sentBody()['max_tokens']);
    }

    public function testTheFallbackTableStillClampsAHugePrompt(): void
    {
        $provider = $this->provider(self::SERVED_QWEN);
        // ~800k estimated tokens: 1,000,000 − 800k − 200k margin < floor.
        $provider->complete(self::request(self::SERVED_QWEN, str_repeat('abcd', 800_000)));

        self::assertSame(4096, $this->sentBody()['max_tokens']);
    }

    /**
     * A server's own answer needs no family table: an unknown-family model on
     * a discovered server gets the derived default too.
     */
    public function testADiscoveredWindowAppliesToAModelOutsideTheTable(): void
    {
        $provider = $this->provider(
            'MiniMax-M2.7',
            static fn (): SglangServerInfo => SglangServerInfo::new(servedModelName: 'MiniMax-M2.7', contextLength: 196_608),
        );
        $provider->complete(self::request('MiniMax-M2.7'));

        $sent = $this->sentBody();
        self::assertSame(self::expectedDefault(196_608, $sent), $sent['max_tokens']);
        self::assertGreaterThan(4096, $sent['max_tokens']);
    }

    // -------------------------------------------------------------------------
    // Discovered mismatches surface once
    // -------------------------------------------------------------------------

    public function testAServedModelOfAnotherFamilyIsNamedOnce(): void
    {
        $provider = $this->provider(SglangProvider::DEFAULT_MODEL, static fn (): SglangServerInfo => self::skynet2());

        $logged = self::withErrorLogDiscarded(static function () use ($provider): void {
            $provider->contextWindow();
            $provider->contextWindow();
        }, 'sc_a18_');

        $notices = RuntimeNoticeSink::drain();
        self::assertCount(1, $notices);
        self::assertStringContainsString(self::SERVED_QWEN, $notices[0]);
        self::assertStringContainsString(SglangProvider::DEFAULT_MODEL, $notices[0]);
        self::assertStringContainsString('https://skynet2.interserver.net', $notices[0]);
        self::assertStringContainsString(self::SERVED_QWEN, $logged);
    }

    /**
     * The repo's own dev-sglang block configures `Qwen/Qwen3.8-Flash-Next`
     * against a server serving `…-FP8`: same family, same behaviour, no notice.
     */
    public function testASpellingDifferenceInsideOneFamilyStaysQuiet(): void
    {
        $provider = $this->provider('Qwen/Qwen3.8-Flash-Next', static fn (): SglangServerInfo => self::skynet2());

        self::assertSame('', self::withErrorLogDiscarded(static fn () => $provider->contextWindow(), 'sc_a18_'));
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    /**
     * The under-match the DeepSeek family token's docblock said nothing could
     * detect: an alias configured against a served DeepSeek-V4.
     */
    public function testAnAliasConfiguredAgainstAServedDeepSeekIsNamed(): void
    {
        $provider = $this->provider('default', static fn (): SglangServerInfo => SglangServerInfo::new(
            servedModelName: 'deepseek-ai/DeepSeek-V4-Flash-0731',
            maxReqInputLen: 1_048_570,
        ));

        self::withErrorLogDiscarded(static fn () => $provider->contextWindow(), 'sc_a18_');

        $notices = RuntimeNoticeSink::drain();
        self::assertCount(1, $notices);
        self::assertStringContainsString('"default"', $notices[0]);
    }

    public function testAServerWithoutAToolCallParserIsNamedWhenNoTextualFallbackIsArmed(): void
    {
        $info = static fn (): ?SglangServerInfo => SglangServerInfo::fromResponses(
            ['served_model_name' => self::SERVED_QWEN, 'tool_call_parser' => null],
            null,
        );

        $bare = $this->provider(self::SERVED_QWEN, $info, parser: OpenAiArrayToolCallParser::new(SglangProvider::argumentDecoder()));
        self::withErrorLogDiscarded(static fn () => $bare->contextWindow(), 'sc_a18_');
        $notices = RuntimeNoticeSink::drain();
        self::assertCount(1, $notices);
        self::assertStringContainsString('--tool-call-parser', $notices[0]);

        $armed = $this->provider(
            self::SERVED_QWEN,
            $info,
            parser: DsmlToolCallParser::new(OpenAiArrayToolCallParser::new(SglangProvider::argumentDecoder())),
        );
        self::withErrorLogDiscarded(static fn () => $armed->contextWindow(), 'sc_a18_');
        self::assertSame([], RuntimeNoticeSink::drain(), 'a textual fallback is armed; nothing to warn about');
    }

    public function testAServerThatDoesNotReportItsParserStaysQuiet(): void
    {
        $provider = $this->provider(self::SERVED_QWEN, static fn (): ?SglangServerInfo => SglangServerInfo::fromResponses(
            ['served_model_name' => self::SERVED_QWEN],
            null,
        ));

        self::withErrorLogDiscarded(static fn () => $provider->contextWindow(), 'sc_a18_');
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    // -------------------------------------------------------------------------
    // ProviderFactory wiring
    // -------------------------------------------------------------------------

    private static function property(SglangProvider $provider, string $name): mixed
    {
        $prop = (new \ReflectionClass(SglangProvider::class))->getProperty($name);

        return $prop->getValue($provider);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function factoryBuilt(array $extra = [], string $model = self::SERVED_QWEN): SglangProvider
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'sglang',
            // Unroutable, and construction must not dial it anyway.
            'baseUrl' => 'http://127.0.0.1:1/v1',
            'model' => $model,
        ] + $extra);
        self::assertInstanceOf(SglangProvider::class, $provider);

        return $provider;
    }

    public function testTheFactoryArmsDiscoveryByDefault(): void
    {
        self::assertInstanceOf(\Closure::class, self::property(self::factoryBuilt(), 'serverInfoLoader'));
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function discoverFlags(): array
    {
        return [
            'json false' => [false, false],
            'json true' => [true, true],
            'unset placeholder' => ['', true],
            'string false' => ['false', false],
            'string off' => ['off', false],
            'string 0' => ['0', false],
            'string yes' => ['yes', true],
        ];
    }

    #[DataProvider('discoverFlags')]
    public function testDiscoverServerInfoTurnsTheReadOff(mixed $flag, bool $armed): void
    {
        $loader = self::property(self::factoryBuilt(['discoverServerInfo' => $flag]), 'serverInfoLoader');

        self::assertSame($armed, $loader instanceof \Closure);
    }

    public function testAMisspelledDiscoverFlagIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('discoverServerInfo must be true or false');

        self::factoryBuilt(['discoverServerInfo' => 'maybe']);
    }

    /**
     * The parser half of A18: the served Qwen3.8 runs `qwen3_coder`, which
     * returns the OpenAI `tool_calls[]` array, so the model-derived default is
     * the array parser - and DSML stays DeepSeek-only.
     *
     * @return array<string, array{0: string, 1: class-string}>
     */
    public static function servedModelParsers(): array
    {
        return [
            'served Qwen3.8 id' => [self::SERVED_QWEN, OpenAiArrayToolCallParser::class],
            'configured Qwen3.8 alias' => ['Qwen/Qwen3.8-Flash-Next', OpenAiArrayToolCallParser::class],
            'DeepSeek-V4' => [SglangProvider::DEFAULT_MODEL, DsmlToolCallParser::class],
        ];
    }

    /**
     * @param class-string $expected
     */
    #[DataProvider('servedModelParsers')]
    public function testTheDefaultParserMatchesTheServedFamily(string $model, string $expected): void
    {
        self::assertInstanceOf($expected, self::property(self::factoryBuilt(model: $model), 'toolCallParser'));
    }
}
