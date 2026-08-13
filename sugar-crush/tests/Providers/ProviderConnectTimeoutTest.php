<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Crush\Providers\BedrockProvider;
use SugarCraft\Crush\Providers\CustomProvider;
use SugarCraft\Crush\Providers\OpenAIProvider;
use SugarCraft\Crush\Providers\ProviderFactory;
use SugarCraft\Crush\Providers\SglangProvider;

/**
 * Pins crush_code.md Phase 0 item 4 in BOTH directions.
 *
 * Positive: every provider that owns an HTTP client bounds the connect phase,
 * so an unreachable host fails fast instead of hanging the one-shot `-p`/`run`
 * path (no fork, no idle timer) for the transport's own multi-minute default.
 * Guzzle uses two transports and they take different options for that - see
 * {@see \SugarCraft\Crush\Providers\Concerns\HttpClientDefaults} - so the
 * streaming path is asserted separately from the curl one, because a
 * `connect_timeout` alone is silently discarded on the streaming path and
 * that is the path `completeStream()` actually takes.
 *
 * Negative, and just as load-bearing: NONE of them sets a total request
 * `timeout`. `src/MCP/McpClient.php` sets `timeout => 30` and that is correct
 * *there* (a short MCP RPC), which makes "add the same thing to the providers,
 * it looks asymmetric" the obvious and wrong next edit - it would abort real
 * completions, which routinely run for tens of minutes on a loaded or remote
 * server. The source scrape below fails that edit the moment it is made.
 *
 * SCOPE OF THESE ASSERTIONS. Everything here reads configuration off a
 * constructed client, drives a client whose terminal handler has been
 * replaced, or reads source text. Nothing opens a socket, so nothing can
 * hang - and equally, nothing here proves what a real socket does. The
 * on-the-wire behaviour these options produce was measured separately
 * against a SYN-dropping loopback host and a local fake SSE server; the
 * numbers and what they do and do not cover are recorded in
 * `HttpClientDefaults`' docblock.
 */
final class ProviderConnectTimeoutTest extends TestCase
{
    private const PROVIDERS_DIR = __DIR__ . '/../../src/Providers';

    private const SHARED_SEAM = 'Concerns/HttpClientDefaults.php';

    /**
     * Providers with no HTTP client of their own, and why. Listed rather than
     * silently skipped so a future reader can tell "exempt" from "forgotten".
     */
    private const NO_HTTP_CLIENT = [
        // Shells out to the `claude` binary; no sockets in this process.
        'ClaudeCodeProvider.php' => 'subprocess',
        'ClaudeCodeInvocation.php' => 'subprocess',
        // Test/offline double.
        'EchoProvider.php' => 'no network',
        // gRPC through google/cloud-ai-platform behind an injectable
        // predictor closure - not Guzzle, and the SDK is not even installed
        // (VertexProvider::defaultPredictor() class_exists()-guards it).
        'VertexProvider.php' => 'grpc seam',
    ];

    // -------------------------------------------------------------------------
    // Concrete: the clients providers actually build
    // -------------------------------------------------------------------------

    public function testSglangProviderSetsAConnectTimeoutAndNoTotalTimeout(): void
    {
        $client = self::guzzleClientOf(
            SglangProvider::openAiCompatible('https://sglang.invalid/v1')
        );

        $this->assertIsFloat($client->getConfig('connect_timeout'));
        $this->assertGreaterThan(0.0, $client->getConfig('connect_timeout'));
        $this->assertNull(
            $client->getConfig('timeout'),
            'a total request timeout would abort long-running completions',
        );
    }

    public function testCustomProviderSetsAConnectTimeoutAndNoTotalTimeout(): void
    {
        $client = self::guzzleClientOf(
            CustomProvider::openAiCompatible('custom', 'https://custom.invalid', 'some-model')
        );

        $this->assertGreaterThan(0.0, $client->getConfig('connect_timeout'));
        $this->assertNull($client->getConfig('timeout'));
    }

    public function testTheAnthropicClientTheFactoryBuildsSetsAConnectTimeoutAndNoTotalTimeout(): void
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'anthropic',
            'apiKey' => 'sk-not-a-real-key',
            'model' => 'claude-sonnet-4-6',
        ]);

        $client = self::guzzleClientOf($provider);

        $this->assertGreaterThan(0.0, $client->getConfig('connect_timeout'));
        $this->assertNull($client->getConfig('timeout'));
    }

    /**
     * `\OpenAI::client()` resolves its transport through
     * `Psr18ClientDiscovery`, which hands back a bare Guzzle client with no
     * connect bound - the same hang, one layer down in a dependency. The
     * factory now injects a configured client instead, so reach through
     * openai-php's transporter and prove it arrived.
     */
    public function testTheOpenAiClientTheFactoryBuildsCarriesTheConnectTimeout(): void
    {
        $http = self::openAiTransportClient();

        $this->assertGreaterThan(0.0, $http->getConfig('connect_timeout'));
        $this->assertNull($http->getConfig('timeout'));
    }

    /**
     * The AWS SDK takes transport options as an `http` array rather than a
     * client instance; it resolves them into `AwsClient::$defaultRequestOptions`.
     *
     * Bedrock needs nothing beyond the plain `connect_timeout`: the SDK never
     * sets Guzzle's `stream` option (`ConverseStream` reassembles its event
     * stream from a buffered body), so its requests stay on the curl handler,
     * which honours `connect_timeout` directly.
     */
    public function testBedrockPassesTheConnectTimeoutIntoTheAwsSdksHttpOptions(): void
    {
        $awsClient = self::readProperty(BedrockProvider::create(), 'client');

        $property = new \ReflectionProperty(\Aws\AwsClient::class, 'defaultRequestOptions');
        $property->setAccessible(true);
        /** @var array<string, mixed> $options */
        $options = $property->getValue($awsClient);

        $this->assertArrayHasKey('connect_timeout', $options);
        $this->assertGreaterThan(0.0, $options['connect_timeout']);
        $this->assertArrayNotHasKey('timeout', $options);
    }

    // -------------------------------------------------------------------------
    // The streaming transport: the live path, and the one a bare
    // connect_timeout does nothing for
    // -------------------------------------------------------------------------

    /**
     * Guzzle routes any request carrying `stream => true` to `StreamHandler`,
     * which has no `add_connect_timeout()` and therefore discards
     * `connect_timeout` outright. Assert the two options it DOES honour reach
     * the transport, and that they are the safe pair rather than the unsafe
     * half of it: a `timeout` on its own bounds body reads too and kills a
     * completion the moment the model pauses.
     *
     * @dataProvider streamingClients
     */
    public function testAStreamingRequestReachesTheTransportWithABoundedConnectAndAnUnboundedBody(callable $factory): void
    {
        $client = $factory();
        $options = self::captureHandlerOptions($client, ['stream' => true]);

        $this->assertArrayHasKey('timeout', $options, 'StreamHandler ignores connect_timeout; without this the connect is unbounded');
        $this->assertSame($client->getConfig('connect_timeout'), $options['timeout']);

        $this->assertArrayHasKey('read_timeout', $options, 'a timeout without a read_timeout bounds body reads - it aborts a thinking model');
        $this->assertGreaterThan(
            600.0,
            $options['read_timeout'],
            'the body read budget must be far past any plausible inter-token gap',
        );
        $this->assertGreaterThan($options['timeout'], $options['read_timeout']);
    }

    /**
     * The other half of the same coin: on the curl handler `timeout` IS a
     * total request deadline, so it must never appear there.
     *
     * @dataProvider streamingClients
     */
    public function testANonStreamingRequestNeverReachesTheTransportWithATimeout(callable $factory): void
    {
        $options = self::captureHandlerOptions($factory(), []);

        $this->assertArrayNotHasKey(
            'timeout',
            $options,
            "on curl 'timeout' is CURLOPT_TIMEOUT - a total deadline that would abort a real completion",
        );
        $this->assertArrayNotHasKey('read_timeout', $options);
        $this->assertGreaterThan(0.0, $options['connect_timeout']);
    }

    /**
     * @dataProvider streamingClients
     */
    public function testAnExplicitPerRequestValueStillWins(callable $factory): void
    {
        $options = self::captureHandlerOptions($factory(), ['stream' => true, 'timeout' => 3.5, 'read_timeout' => 7.5]);

        $this->assertSame(3.5, $options['timeout']);
        $this->assertSame(7.5, $options['read_timeout']);
    }

    /**
     * Every client built through the shared seam, so a provider cannot opt
     * out of the streaming assertions above by being added later.
     *
     * @return array<string, array{0: callable(): Client}>
     */
    public static function streamingClients(): array
    {
        return [
            'sglang' => [static fn (): Client => self::guzzleClientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'))],
            'custom' => [static fn (): Client => self::guzzleClientOf(CustomProvider::openAiCompatible('custom', 'https://custom.invalid', 'some-model'))],
            'anthropic' => [static fn (): Client => self::guzzleClientOf((new ProviderFactory())->create([
                'type' => 'anthropic',
                'apiKey' => 'sk-not-a-real-key',
                'model' => 'claude-sonnet-4-6',
            ]))],
            // openai-php streams by calling send($request, ['stream' => true])
            // on this very client, so the middleware has to be on it.
            'openai' => [static fn (): Client => self::openAiTransportClient()],
        ];
    }

    /**
     * A caller-supplied bare handler (a `MockHandler`, in practice) is
     * wrapped, not promoted into a `HandlerStack` - promoting it would
     * quietly add http_errors/redirects/cookies the caller never asked for
     * and change how their test behaves.
     */
    public function testABareCallableHandlerIsWrappedRatherThanPromoted(): void
    {
        $captured = [];
        $inner = static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = $options;

            return new FulfilledPromise(new Response(404, [], 'nope'));
        };

        $client = self::buildSeamClient(['handler' => $inner, 'base_uri' => 'https://seam.invalid/']);

        $this->assertNotInstanceOf(HandlerStack::class, $client->getConfig('handler'));

        // No http_errors middleware was bolted on, so a 404 comes back as a
        // response instead of throwing.
        $response = $client->request('POST', 'chat/completions', ['stream' => true, 'json' => []]);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame($client->getConfig('connect_timeout'), $captured['timeout']);
    }

    public function testReusingAHandlerStackDoesNotStackDuplicateMiddleware(): void
    {
        $stack = HandlerStack::create(static fn (): PromiseInterface => new FulfilledPromise(new Response(200)));

        self::buildSeamClient(['handler' => $stack]);
        self::buildSeamClient(['handler' => $stack]);

        // HandlerStack::__toString() lists every entry twice, so count the
        // stack itself rather than its debug rendering.
        $entries = new \ReflectionProperty(HandlerStack::class, 'stack');
        $entries->setAccessible(true);
        $names = array_column((array) $entries->getValue($stack), 1);

        $this->assertSame(
            1,
            count(array_keys($names, 'sugarcrush.stream_connect_bounds', true)),
            'a stack handed to guzzleClient() twice grew a duplicate middleware',
        );
    }

    // -------------------------------------------------------------------------
    // Scrape-and-pin: a NEW provider must not be able to skip this
    // -------------------------------------------------------------------------

    /**
     * Every PHP file under src/Providers, so a provider added tomorrow is
     * covered by the two tests below without anyone remembering to list it
     * (same rationale as HelpTest's flag scrape).
     *
     * Keyed by path relative to src/Providers, not by basename: two files of
     * the same name in different subdirectories would otherwise collide and
     * one of them would silently go unscraped.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function providerSourceFiles(): array
    {
        $root = (string) realpath(self::PROVIDERS_DIR);
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        ) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getPathname();
            $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
            $files[$relative] = [$relative, (string) file_get_contents($path)];
        }

        ksort($files);

        return $files;
    }

    /**
     * @dataProvider providerSourceFiles
     */
    public function testNoProviderConstructsAGuzzleClientOutsideTheSharedSeam(string $name, string $source): void
    {
        if ($name === self::SHARED_SEAM) {
            $this->assertStringContainsString(
                'new Client(',
                $source,
                'the shared seam is the one place allowed to construct a client',
            );

            return;
        }

        $this->assertDoesNotMatchRegularExpression(
            '/new\s+(?:\\\\GuzzleHttp\\\\)?Client\s*\(/',
            $source,
            $name . ' builds its own Guzzle client; route it through '
                . 'HttpClientDefaults::guzzleClient() so it inherits the connect bounds',
        );
    }

    /**
     * The scrape covers the ways a total timeout can be spelled, not just the
     * one the original edit would have used: either quote style, the
     * `RequestOptions::TIMEOUT` constant, and array-element assignment
     * (including `??=`). A single-pattern scrape is trivially evadable and
     * would then read as proof of something it never checked.
     *
     * @dataProvider providerSourceFiles
     */
    public function testNoProviderSetsATotalRequestTimeout(string $name, string $source): void
    {
        if ($name === self::SHARED_SEAM) {
            $this->assertSharedSeamOnlyBoundsTheStreamingTransport($source);

            return;
        }

        $why = $name . " sets a total request 'timeout'. LLM completions legitimately run for "
            . 'tens of minutes; only a connect bound belongs on a provider client '
            . '(crush_code.md Phase 0 item 4).';

        foreach (self::TOTAL_TIMEOUT_SPELLINGS as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, $source, $why);
        }
    }

    /**
     * `'timeout' => 30`, `"timeout" => 30`, `$opts['timeout'] = 30`,
     * `$opts["timeout"] ??= 30`, and any mention of the option constant.
     */
    private const TOTAL_TIMEOUT_SPELLINGS = [
        '/([\'"])timeout\1\s*=>/',
        '/\[\s*([\'"])timeout\1\s*\]\s*(\?\?)?=[^=]/',
        '/RequestOptions::TIMEOUT\b/',
    ];

    /**
     * The seam is the one file that may name `timeout`, because `StreamHandler`
     * has no other word for a connect bound. Pin the shape that makes that
     * safe rather than exempting the file outright: set once, only for
     * requests Guzzle routes to `StreamHandler`, and always paired with the
     * `read_timeout` that re-arms the socket for the body.
     *
     * The client-level assertions above are the other half of this - they
     * prove the constructed clients carry no `timeout` in their config.
     */
    private function assertSharedSeamOnlyBoundsTheStreamingTransport(string $source): void
    {
        $this->assertSame(
            1,
            substr_count($source, "\$options['timeout'] ??="),
            'the seam must set a stream-transport timeout in exactly one place',
        );
        $this->assertStringContainsString(
            "\$options['read_timeout'] ??=",
            $source,
            'a stream-transport timeout without a read_timeout bounds body reads too',
        );
        $this->assertStringContainsString(
            'routedToStreamHandler(',
            $source,
            'the timeout must be gated on the request actually going to StreamHandler',
        );
        foreach (self::TOTAL_TIMEOUT_SPELLINGS as $pattern) {
            $this->assertDoesNotMatchRegularExpression(
                $pattern,
                str_replace(["\$options['timeout'] ??=", "\$options['read_timeout'] ??="], '', $source),
                'the seam sets a timeout somewhere other than the guarded stream branch',
            );
        }
    }

    public function testTheProviderSourceScrapeActuallyFoundTheKnownProviders(): void
    {
        // Guards both scrapes above from silently passing on an empty or
        // mis-rooted file set.
        $found = array_keys(self::providerSourceFiles());

        foreach (['SglangProvider.php', 'CustomProvider.php', 'ProviderFactory.php', 'BedrockProvider.php', self::SHARED_SEAM] as $expected) {
            $this->assertContains($expected, $found);
        }

        foreach (array_keys(self::NO_HTTP_CLIENT) as $exempt) {
            $this->assertContains($exempt, $found, 'exemption list names a file that no longer exists');
        }

        // The keys are paths, so a nested file keeps its directory - that is
        // what stops two same-named files from collapsing into one.
        $this->assertContains('ToolCallParser/OpenAiArrayToolCallParser.php', $found);
    }

    // -------------------------------------------------------------------------
    // The override seam
    // -------------------------------------------------------------------------

    public function testTheConnectTimeoutIsOverridableViaTheEnvironment(): void
    {
        $previous = getenv('SUGARCRUSH_CONNECT_TIMEOUT');

        try {
            putenv('SUGARCRUSH_CONNECT_TIMEOUT=2.5');
            $client = self::guzzleClientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'));
            $this->assertSame(2.5, $client->getConfig('connect_timeout'));

            // Operator error must not silently disable the bound: every
            // transport in play reads 0 as "use my own default", which is the
            // exact hang being fixed.
            foreach (['0', '-1', 'soon', ''] as $bogus) {
                putenv('SUGARCRUSH_CONNECT_TIMEOUT=' . $bogus);
                $client = self::guzzleClientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'));
                $this->assertGreaterThan(0.0, $client->getConfig('connect_timeout'), $bogus . ' must fall back to the default');
            }
        } finally {
            self::restoreEnv($previous);
        }
    }

    /**
     * Guzzle hands curl `CURLOPT_CONNECTTIMEOUT_MS = connect_timeout * 1000`
     * and curl casts it to an int, so a sub-millisecond override arrives as
     * 0 - which curl reads as "use my default" and turns into a 300s wait.
     * A positive-but-useless value is therefore just as dangerous as `0` and
     * has to fall back the same way, not sail through the `> 0` check.
     *
     * @dataProvider subMillisecondOverrides
     */
    public function testASubMillisecondOverrideCannotReinstateTheHang(string $override): void
    {
        $previous = getenv('SUGARCRUSH_CONNECT_TIMEOUT');

        try {
            putenv('SUGARCRUSH_CONNECT_TIMEOUT=' . $override);
            $connect = self::guzzleClientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'))
                ->getConfig('connect_timeout');

            $this->assertGreaterThanOrEqual(
                0.001,
                $connect,
                $override . ' survived as a connect bound that truncates to 0 milliseconds on the wire',
            );
            // The cast curl itself performs on CURLOPT_CONNECTTIMEOUT_MS.
            $this->assertGreaterThan(0, (int) ($connect * 1000.0));
        } finally {
            self::restoreEnv($previous);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function subMillisecondOverrides(): array
    {
        return [
            'tenth of a millisecond' => ['0.0001'],
            'just under a millisecond' => ['0.0009'],
            'exponent notation' => ['1e-9'],
        ];
    }

    public function testTheSmallestUsableOverrideIsStillAccepted(): void
    {
        $previous = getenv('SUGARCRUSH_CONNECT_TIMEOUT');

        try {
            putenv('SUGARCRUSH_CONNECT_TIMEOUT=0.001');
            $this->assertSame(
                0.001,
                self::guzzleClientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'))
                    ->getConfig('connect_timeout'),
                'the floor is a floor, not a minimum of one second',
            );
        } finally {
            self::restoreEnv($previous);
        }
    }

    public function testTheDefaultConnectTimeoutStaysInTheAuditedWindow(): void
    {
        $client = self::guzzleClientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'));

        // 10-30s per crush_code.md item 4: long enough that a slow but working
        // DNS + TLS handshake never trips it, short enough that a dead host
        // does not sit on the kernel's ~2 minute SYN-retry ceiling.
        $this->assertGreaterThanOrEqual(10.0, $client->getConfig('connect_timeout'));
        $this->assertLessThanOrEqual(30.0, $client->getConfig('connect_timeout'));
    }

    // -------------------------------------------------------------------------

    /**
     * Swaps the client's terminal handler for a recorder, leaving every
     * middleware (including the seam's own) in place, and returns the options
     * the transport would have been called with. No socket is opened.
     *
     * @param array<string, mixed> $requestOptions
     *
     * @return array<string, mixed>
     */
    private static function captureHandlerOptions(Client $client, array $requestOptions): array
    {
        $stack = $client->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);

        $captured = [];
        $stack->setHandler(static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = $options;

            return new FulfilledPromise(new Response(200, [], 'ok'));
        });

        $client->request('POST', 'chat/completions', $requestOptions + ['json' => ['probe' => true]]);

        return $captured;
    }

    /**
     * The seam's own factory, reached through a class that uses the trait.
     *
     * @param array<string, mixed> $options
     */
    private static function buildSeamClient(array $options): Client
    {
        $method = new \ReflectionMethod(SglangProvider::class, 'guzzleClient');
        $method->setAccessible(true);
        $client = $method->invoke(null, $options);
        \assert($client instanceof Client);

        return $client;
    }

    private static function openAiTransportClient(): Client
    {
        $provider = (new ProviderFactory())->create([
            'type' => 'openai',
            'apiKey' => 'sk-not-a-real-key',
        ]);

        self::assertInstanceOf(OpenAIProvider::class, $provider);

        $transporter = self::readProperty(self::readProperty($provider, 'client'), 'transporter');
        $http = self::readProperty($transporter, 'client');
        self::assertInstanceOf(Client::class, $http);

        return $http;
    }

    private static function restoreEnv(string|false $previous): void
    {
        if ($previous === false) {
            putenv('SUGARCRUSH_CONNECT_TIMEOUT');

            return;
        }

        putenv('SUGARCRUSH_CONNECT_TIMEOUT=' . $previous);
    }

    private static function guzzleClientOf(object $provider): Client
    {
        $client = self::readProperty($provider, 'httpClient');
        \assert($client instanceof Client);

        return $client;
    }

    private static function readProperty(object $object, string $name): object
    {
        $property = new \ReflectionProperty($object, $name);
        $property->setAccessible(true);
        $value = $property->getValue($object);
        \assert(\is_object($value));

        return $value;
    }
}
