<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Crush\Providers\SglangServerInfo;

/**
 * Audit 15a A18 (revised 2026-10-02): the live-limits snapshot read from an
 * SGLang server's `/model_info` + `/server_info`.
 *
 * Every body here is a FIXTURE captured from skynet2 on 2026-10-02
 * (`tests/fixtures/sglang-{model,server}-info-qwen3.8.json`, the latter
 * trimmed to the keys this class reads) or a hand-written variant of one;
 * nothing in this file reaches a real server.
 */
final class SglangServerInfoTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__ . '/../fixtures/' . $name), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testTheCapturedSkynet2ResponsesYieldTheServedLimitsAndParsers(): void
    {
        $info = SglangServerInfo::fromResponses(
            self::fixture('sglang-model-info-qwen3.8.json'),
            self::fixture('sglang-server-info-qwen3.8.json'),
        );

        self::assertNotNull($info);
        self::assertSame('Qwen/Qwen3.8-Flash-Next-FP8', $info->servedModelName);
        self::assertSame(1_000_000, $info->contextLength);
        self::assertSame(999_994, $info->maxReqInputLen);
        self::assertSame('qwen3_coder', $info->toolCallParser);
        self::assertSame('qwen3', $info->reasoningParser);
        self::assertTrue($info->reportsToolCallParser);
        self::assertSame(1_000_000, $info->totalWindow());
        // min(1,000,000, 999,994 − 4,096): the live twin of the transcribed
        // Qwen3.8 constant's formula, which still says 744,506.
        self::assertSame(995_898, $info->inputWindow(4096));
    }

    public function testModelInfoAloneStillNamesTheModelAndParserButNoLimits(): void
    {
        $info = SglangServerInfo::fromResponses(self::fixture('sglang-model-info-qwen3.8.json'), null);

        self::assertNotNull($info);
        self::assertSame('Qwen/Qwen3.8-Flash-Next-FP8', $info->servedModelName);
        self::assertSame('qwen3_coder', $info->toolCallParser);
        self::assertNull($info->totalWindow());
        self::assertNull($info->inputWindow(4096));
    }

    /**
     * The DeepSeek-V4 deployment reported `context_length: null` because it
     * was launched without `--context-length`; the scheduler's resolved figure
     * in `internal_states` is the one to read then.
     */
    public function testANullTopLevelContextLengthFallsBackToTheSchedulersInternalState(): void
    {
        $info = SglangServerInfo::fromResponses(null, [
            'context_length' => null,
            'max_req_input_len' => 1_048_570,
            'internal_states' => [['context_length' => 1_048_576]],
        ]);

        self::assertNotNull($info);
        self::assertSame(1_048_576, $info->contextLength);
        self::assertSame(1_048_576, $info->totalWindow());
        self::assertSame(1_048_570 - 4096, $info->inputWindow(4096));
    }

    /**
     * Only the input ceiling known: it stands in for the total, which can
     * only shrink an output budget derived from it.
     */
    public function testTheInputCeilingStandsInForAnUnknownTotal(): void
    {
        $info = SglangServerInfo::fromResponses(null, ['max_req_input_len' => 200_000]);

        self::assertNotNull($info);
        self::assertSame(200_000, $info->totalWindow());
        self::assertSame(200_000 - 4096, $info->inputWindow(4096));
    }

    /**
     * @return array<string, array{0: array<mixed>|null, 1: array<mixed>|null}>
     */
    public static function uselessBodies(): array
    {
        return [
            'both missing' => [null, null],
            'both empty objects' => [[], []],
            'wrong types everywhere' => [
                ['served_model_name' => 42, 'reasoning_parser' => ''],
                ['context_length' => '1000000', 'max_req_input_len' => -5, 'internal_states' => 'x'],
            ],
            'literal "null" strings' => [['served_model_name' => 'null'], ['context_length' => 0]],
        ];
    }

    /**
     * A proxy that answers 200 with nothing usable must count as "discovery
     * failed", so the fallback table applies instead of an empty snapshot
     * suppressing it.
     *
     * @param array<mixed>|null $model
     * @param array<mixed>|null $server
     */
    #[DataProvider('uselessBodies')]
    public function testBodiesWithNothingUsableAreNoSnapshotAtAll(?array $model, ?array $server): void
    {
        self::assertNull(SglangServerInfo::fromResponses($model, $server));
    }

    /**
     * `tool_call_parser: null` (server launched without the flag) and an
     * absent key (older server) mean different things, and only the first
     * should ever drive a warning.
     */
    public function testAnExplicitNullParserIsDistinguishedFromAnUnreportedOne(): void
    {
        $launchedWithout = SglangServerInfo::fromResponses(['served_model_name' => 'm', 'tool_call_parser' => null], null);
        $olderServer = SglangServerInfo::fromResponses(['served_model_name' => 'm'], null);

        self::assertNotNull($launchedWithout);
        self::assertNotNull($olderServer);
        self::assertTrue($launchedWithout->reportsToolCallParser);
        self::assertNull($launchedWithout->toolCallParser);
        self::assertFalse($olderServer->reportsToolCallParser);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function baseUrls(): array
    {
        return [
            'skynet2 as configured' => ['https://skynet2.interserver.net/v1', 'https://skynet2.interserver.net'],
            'trailing slash' => ['https://skynet2.interserver.net/v1/', 'https://skynet2.interserver.net'],
            'bare host' => ['http://localhost:30000', 'http://localhost:30000'],
            'bare host trailing slash' => ['http://localhost:30000/', 'http://localhost:30000'],
            'proxy under a path' => ['https://proxy.example/sglang/v1', 'https://proxy.example/sglang'],
            'upper-case V1' => ['http://h/V1', 'http://h'],
            'v1 only as a substring is kept' => ['http://h/v10', 'http://h/v10'],
        ];
    }

    /**
     * The info endpoints live at the server ROOT: `/v1/model_info` answered
     * 404 on skynet2 (measured 2026-10-02).
     */
    #[DataProvider('baseUrls')]
    public function testTheRootUrlDropsTheOpenAiPrefix(string $baseUrl, string $expected): void
    {
        self::assertSame($expected, SglangServerInfo::rootUrl($baseUrl));
    }

    public function testDiscoverAsksBothRootEndpointsWithAShortBoundAndTheBearerKey(): void
    {
        /** @var list<array{request: RequestInterface, options: array<string, mixed>}> $seen */
        $seen = [];
        $mock = new MockHandler([
            function (RequestInterface $request, array $options) use (&$seen): Response {
                $seen[] = ['request' => $request, 'options' => $options];

                return new Response(200, [], (string) file_get_contents(__DIR__ . '/../fixtures/sglang-model-info-qwen3.8.json'));
            },
            function (RequestInterface $request, array $options) use (&$seen): Response {
                $seen[] = ['request' => $request, 'options' => $options];

                return new Response(200, [], (string) file_get_contents(__DIR__ . '/../fixtures/sglang-server-info-qwen3.8.json'));
            },
        ]);

        $info = SglangServerInfo::discover('https://skynet2.interserver.net/v1', 'sk-test', $mock);

        self::assertNotNull($info);
        self::assertSame(999_994, $info->maxReqInputLen);
        self::assertSame(
            ['https://skynet2.interserver.net/model_info', 'https://skynet2.interserver.net/server_info'],
            array_map(static fn (array $s): string => (string) $s['request']->getUri(), $seen),
        );

        foreach ($seen as $s) {
            self::assertSame('GET', $s['request']->getMethod());
            self::assertSame('Bearer sk-test', $s['request']->getHeaderLine('Authorization'));
            // A metadata read, so a TOTAL bound is right here (never on a
            // completion): it is what keeps a server that accepts and then
            // stalls from holding the TUI's first frame.
            self::assertSame(SglangServerInfo::DISCOVERY_TIMEOUT_SECONDS, $s['options']['timeout']);
            self::assertSame(SglangServerInfo::DISCOVERY_TIMEOUT_SECONDS, $s['options']['connect_timeout']);
        }
    }

    public function testDiscoverSendsNoAuthorizationWithoutAKey(): void
    {
        $seen = [];
        $respond = function (RequestInterface $request) use (&$seen): Response {
            $seen[] = $request;

            return new Response(200, [], '{"served_model_name":"m"}');
        };

        SglangServerInfo::discover('http://localhost:30000', null, new MockHandler([$respond, $respond]));

        self::assertCount(2, $seen);
        foreach ($seen as $request) {
            self::assertFalse($request->hasHeader('Authorization'));
        }
    }

    /**
     * One endpoint failing must not cost the other's answer: `/model_info`
     * 404s on an older SGLang, `/server_info` still has the limits.
     */
    public function testOneFailedEndpointKeepsTheOthersAnswer(): void
    {
        $mock = new MockHandler([
            new Response(404, [], '{"detail":"Not Found"}'),
            new Response(200, [], (string) file_get_contents(__DIR__ . '/../fixtures/sglang-server-info-qwen3.8.json')),
        ]);

        $info = SglangServerInfo::discover('http://localhost:30000/v1', null, $mock);

        self::assertNotNull($info);
        self::assertSame(1_000_000, $info->contextLength);
        self::assertSame('Qwen/Qwen3.8-Flash-Next-FP8', $info->servedModelName);
    }

    /**
     * @return array<string, array{0: list<mixed>}>
     */
    public static function failingServers(): array
    {
        $refused = static fn (RequestInterface $r) => new ConnectException('Connection refused', $r);

        return [
            'connection refused on both' => [[$refused, $refused]],
            'both 404 (a proxy that forwards only /v1)' => [[new Response(404), new Response(404)]],
            'both 401' => [[new Response(401), new Response(401)]],
            'html from a captive proxy' => [[new Response(200, [], '<html>hi</html>'), new Response(200, [], '<html>hi</html>')]],
            'empty JSON objects' => [[new Response(200, [], '{}'), new Response(200, [], '{}')]],
        ];
    }

    /**
     * Fail-soft: discovery is an optimisation over the fallback table, so
     * every way of not answering is null, never an exception.
     *
     * @param list<mixed> $queue
     */
    #[DataProvider('failingServers')]
    public function testDiscoverFailsSoftToNull(array $queue): void
    {
        self::assertNull(SglangServerInfo::discover('http://localhost:30000/v1', null, new MockHandler($queue)));
    }

    public function testTheNamedFactoryNormalisesItsInputs(): void
    {
        $info = SglangServerInfo::new(servedModelName: '', contextLength: -1, maxReqInputLen: 10, toolCallParser: 'qwen3_coder');

        self::assertNull($info->servedModelName);
        self::assertNull($info->contextLength);
        self::assertSame(10, $info->maxReqInputLen);
        self::assertTrue($info->reportsToolCallParser);
        self::assertNull($info->inputWindow(4096), 'a window smaller than the headroom is no window');
    }
}
