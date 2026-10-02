<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Diagnostics\RuntimeNoticeSink;
use SugarCraft\Crush\Messages\UserMessage;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Providers\SglangProvider;
use SugarCraft\Crush\Providers\SglangServerInfo;
use SugarCraft\Crush\Providers\ToolCallParser\OpenAiArrayToolCallParser;
use SugarCraft\Crush\Providers\ToolCallParser\ToolCallParserInterface;
use SugarCraft\Crush\Tests\Support\DiscardsErrorLogTrait;

/**
 * Audit A26: {@see SglangProvider::DEFAULT_MODEL} named DeepSeek-V4 after
 * skynet2 had moved to Qwen3.8, so a launch relying on the default asked for
 * a model the server no longer had and drew A18's family-mismatch notice.
 *
 * The fix is two halves, both pinned here: the static id now names what
 * skynet2 serves, and a provider built on the default id with discovery
 * armed ADOPTS the served model - addressing requests to it and taking its
 * family's defaults - so the next redeploy does not reopen the finding.
 */
final class SglangProviderServedModelTest extends TestCase
{
    use DiscardsErrorLogTrait;

    /**
     * A DeepSeek-V4-family id that is neither the old nor the new default, so
     * an assertion that it reached the wire can only pass by adoption.
     */
    private const DEEPSEEK_V4_5_FLASH = 'deepseek-ai/DeepSeek-V4.5-Flash';

    private const DSML = "\xEF\xBD\x9CDSML\xEF\xBD\x9C";

    /** @var array<int, array<string, mixed>> */
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

    /** The captured live skynet2 `/model_info` + `/server_info` (2026-10-02). */
    private static function skynet2(): SglangServerInfo
    {
        $info = SglangServerInfo::fromResponses(
            json_decode((string) file_get_contents(__DIR__ . '/../fixtures/sglang-model-info-qwen3.8.json'), true),
            json_decode((string) file_get_contents(__DIR__ . '/../fixtures/sglang-server-info-qwen3.8.json'), true),
        );
        self::assertNotNull($info);

        return $info;
    }

    private static function servingDeepSeek(?string $toolCallParser = 'deepseekv4', bool $reports = true): SglangServerInfo
    {
        return SglangServerInfo::new(
            servedModelName: self::DEEPSEEK_V4_5_FLASH,
            maxReqInputLen: 1_048_570,
            toolCallParser: $toolCallParser,
            reportsToolCallParser: $reports,
        );
    }

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

    /** @return array<string, mixed> */
    private function firstSentBody(): array
    {
        $decoded = json_decode((string) $this->history[0]['request']->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private static function requestNamed(string $model): CompleteRequest
    {
        return new CompleteRequest(model: $model, messages: [new UserMessage('Hi')]);
    }

    // -------------------------------------------------------------------------
    // The static half
    // -------------------------------------------------------------------------

    public function testTheDefaultIdIsTheModelSkynet2Serves(): void
    {
        self::assertSame(self::skynet2()->servedModelName, SglangProvider::DEFAULT_MODEL);
    }

    /** The finding's own test: the default config against the live capture is quiet. */
    public function testADefaultConfigProviderAgainstSkynet2RaisesNoFamilyNotice(): void
    {
        $provider = $this->provider(SglangProvider::DEFAULT_MODEL, static fn (): SglangServerInfo => self::skynet2());

        self::assertSame('', self::withErrorLogDiscarded(static fn () => $provider->contextWindow(), 'sc_a26_'));
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    // -------------------------------------------------------------------------
    // The adoption half
    // -------------------------------------------------------------------------

    /**
     * A default-id request is addressed to the served model, and takes that
     * family's defaults - DeepSeek-V4's temperature 1.0 and top-level
     * `reasoning_effort: max` - rather than the fallback id's.
     */
    public function testADefaultIdRequestIsAddressedToTheServedModel(): void
    {
        $provider = $this->provider(SglangProvider::DEFAULT_MODEL, static fn (): SglangServerInfo => self::servingDeepSeek());

        self::withErrorLogDiscarded(static fn () => $provider->complete(self::requestNamed(SglangProvider::DEFAULT_MODEL)), 'sc_a26_');

        $sent = $this->firstSentBody();
        self::assertSame(self::DEEPSEEK_V4_5_FLASH, $sent['model']);
        // JSON has no float/int distinction: 1.0 decodes as 1.
        self::assertSame(1.0, (float) $sent['temperature']);
        self::assertSame('max', $sent['reasoning_effort'] ?? null);
        // Adopting is not a mismatch: nothing to warn about.
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    public function testTheStreamingPathIsAddressedTheSameWay(): void
    {
        $provider = $this->provider(
            SglangProvider::DEFAULT_MODEL,
            static fn (): SglangServerInfo => self::servingDeepSeek(),
            "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"},\"finish_reason\":\"stop\"}]}\n\ndata: [DONE]\n",
        );

        self::withErrorLogDiscarded(
            static fn () => iterator_to_array($provider->completeStream(self::requestNamed(SglangProvider::DEFAULT_MODEL)), false),
            'sc_a26_',
        );

        self::assertSame(self::DEEPSEEK_V4_5_FLASH, $this->firstSentBody()['model']);
    }

    /** A model somebody NAMED is sent as named, whatever the server serves. */
    public function testANamedModelIsSentAsNamed(): void
    {
        $provider = $this->provider(SglangProvider::DEFAULT_MODEL, static fn (): SglangServerInfo => self::servingDeepSeek());

        self::withErrorLogDiscarded(static fn () => $provider->complete(self::requestNamed('MiniMax-M2.7')), 'sc_a26_');

        self::assertSame('MiniMax-M2.7', $this->firstSentBody()['model']);
    }

    /** A provider configured with its own id never adopts - it gets the notice instead. */
    public function testAProviderBuiltOnAnotherIdDoesNotAdopt(): void
    {
        $provider = $this->provider('MiniMax-M2.7', static fn (): SglangServerInfo => self::servingDeepSeek());

        self::withErrorLogDiscarded(static function () use ($provider): void {
            $provider->complete(self::requestNamed(SglangProvider::DEFAULT_MODEL));
        }, 'sc_a26_');

        self::assertSame(SglangProvider::DEFAULT_MODEL, $this->firstSentBody()['model']);
        self::assertCount(1, RuntimeNoticeSink::drain(), 'a configured MiniMax against a served DeepSeek is a real mismatch');
    }

    /**
     * @return array<string, array{\Closure|null}>
     */
    public static function nothingToAdopt(): array
    {
        return [
            'no loader armed' => [null],
            'discovery failed' => [static fn (): ?SglangServerInfo => null],
            'server names no model' => [static fn (): SglangServerInfo => SglangServerInfo::new(maxReqInputLen: 1_000_000)],
        ];
    }

    /**
     * @dataProvider nothingToAdopt
     */
    public function testWithNothingToAdoptTheStaticDefaultIsSent(?\Closure $loader): void
    {
        $provider = $this->provider(SglangProvider::DEFAULT_MODEL, $loader);

        self::withErrorLogDiscarded(static fn () => $provider->complete(self::requestNamed(SglangProvider::DEFAULT_MODEL)), 'sc_a26_');

        self::assertSame(SglangProvider::DEFAULT_MODEL, $this->firstSentBody()['model']);
    }

    // -------------------------------------------------------------------------
    // The parser follows the adopted model
    // -------------------------------------------------------------------------

    /**
     * An unnamed parser on an adopting provider is chosen from the SERVED
     * model, so a server serving DeepSeek-V4 without `--tool-call-parser`
     * still has its DSML calls recovered - the safety net a configured
     * DeepSeek-V4 id gets from the factory.
     */
    public function testAnAdoptedDeepSeekGetsTheDsmlSafetyNet(): void
    {
        $d = self::DSML;
        $envelope = "<{$d}tool_calls>\n<{$d}invoke name=\"read\">\n"
            . "<{$d}parameter name=\"path\" string=\"true\">/etc/hosts</{$d}parameter>\n"
            . "</{$d}invoke>\n</{$d}tool_calls>";
        $body = (string) json_encode([
            'choices' => [['message' => ['content' => $envelope], 'finish_reason' => 'stop']],
            'usage' => ['total_tokens' => 1],
        ]);

        $provider = $this->provider(
            SglangProvider::DEFAULT_MODEL,
            static fn (): SglangServerInfo => self::servingDeepSeek(null),
            $body,
        );

        $response = null;
        self::withErrorLogDiscarded(static function () use ($provider, &$response): void {
            $response = $provider->complete(self::requestNamed(SglangProvider::DEFAULT_MODEL));
        }, 'sc_a26_');

        self::assertNotNull($response);
        self::assertCount(1, $response->toolCalls ?? []);
        self::assertSame('read', $response->toolCalls[0]->name());
        // ...and because DSML is armed, the parser-less server is not warned about.
        self::assertSame([], RuntimeNoticeSink::drain());
    }

    public function testAnAdoptedQwenKeepsTheArrayParser(): void
    {
        $provider = $this->provider(SglangProvider::DEFAULT_MODEL, static fn (): SglangServerInfo => self::skynet2());

        $parser = null;
        self::withErrorLogDiscarded(static function () use ($provider, &$parser): void {
            $parser = (new \ReflectionMethod(SglangProvider::class, 'resolvedToolCallParser'))->invoke($provider);
        }, 'sc_a26_');

        self::assertInstanceOf(OpenAiArrayToolCallParser::class, $parser);
    }
}
