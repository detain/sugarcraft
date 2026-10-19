<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\Messages\UserMessage;
use SugarCraft\Code\Providers\BedrockProvider;
use SugarCraft\Code\Providers\CompleteRequest;
use SugarCraft\Code\Providers\CustomProvider;
use SugarCraft\Code\Providers\OpenAIProvider;
use SugarCraft\Code\Providers\ProviderFactory;
use SugarCraft\Code\Providers\SglangProvider;
use SugarCraft\Code\Providers\TransientFailure;

/**
 * Pins crush_code.md Phase 0 item 4 in BOTH directions.
 *
 * Positive: every provider that owns an HTTP client bounds the connect phase,
 * so an unreachable host fails fast instead of hanging the one-shot `-p`/`run`
 * path (no fork, no idle timer) for the transport's own multi-minute default.
 * Guzzle uses two transports and they take different options for that - see
 * {@see \SugarCraft\Code\Providers\Concerns\HttpClientDefaults} - so the
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
 * replaced, reflects the seam's private static helpers, or reads source
 * text. Nothing opens a socket, so nothing can hang - and equally, nothing
 * here proves what a real socket does. The curl-arm helpers are reflected
 * rather than lived-through because allow_url_fopen is PHP_INI_SYSTEM:
 * the routing decision it feeds is pinned as a pure table here. The
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

    /**
     * Files under `src/Providers/` that make a METADATA read, never a
     * completion, and may therefore bound its TOTAL time - exempt from
     * {@see testNoProviderSetsATotalRequestTimeout()} only (they must still
     * build their client through the shared seam), and listed by name with
     * the reason for the same "exempt, not forgotten" rule as above.
     */
    private const METADATA_READS = [
        // Audit 15a A18: `/model_info` + `/server_info`, read on the TUI's
        // first frame; a server that accepts and then stalls must not hold it.
        'SglangServerInfo.php' => 'two metadata GETs on the render path',
        // Roadmap 5.13a: one download of LiteLLM's model database, normally in
        // a detached grandchild, inline only on a build without ext-pcntl.
        'ModelMetadata.php' => 'one model-database download, bounded so the inline path cannot stall a launch',
        // PRV-1 (§6.5): one `GET {base}/models` per catalog, fired only from
        // palette-open prefetch and `models all`, never a completion path.
        'OpenAiModelsEndpoint.php' => 'one models-list metadata GET, bounded so a dead endpoint cannot stall a palette open',
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
     * ISS-075: `retries => 0` hands the ladder to TransientFailure (3 SDK ×
     * 3 engine attempts made nine per failing turn), and `read_timeout`
     * replaces `default_socket_timeout` for SSE gaps. NO `timeout` here:
     * these defaults reach the BATCH converse() too, where curl would read it
     * as a total deadline - the header bound rides the stream operation only
     * (pinned by testBedrockStreamOperationCarriesTheHeaderBoundAndBatchDoesNot).
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
        $this->assertFalse(
            $awsClient->getHandlerList()->hasMiddleware('retry'),
            'the SDK ladder must be off; TransientFailure owns retries (ISS-075)'
        );
        // Non-vacuity control on the SAME SDK version: without the zero, the
        // legacy default wires the retry middleware right back in.
        $control = new \Aws\BedrockRuntime\BedrockRuntimeClient(['region' => 'us-east-1', 'version' => 'latest']);
        $this->assertTrue(
            $control->getHandlerList()->hasMiddleware('retry'),
            'the SDK default no longer installs a retry middleware - this probe stopped discriminating (ISS-075)'
        );
        $this->assertGreaterThan(600.0, $options['read_timeout'] ?? 0.0, 'the idle body-read bound mirrors streamTransportBounds; default_socket_timeout must not govern SSE gaps');
        $this->assertArrayNotHasKey('timeout', $options, 'a client-level timeout would be a TOTAL deadline on the curl batch path (ISS-075)');
    }

    /**
     * ISS-075, second half: the header bound lands on ConverseStream (whose
     * requests the SDK flags `@http.stream = true`, so StreamHandler serves
     * them and discards connect_timeout outright) and NOT on the batch
     * converse().
     */
    public function testBedrockStreamOperationCarriesTheHeaderBoundAndBatchDoesNot(): void
    {
        $client = new class (['service' => 'bedrock-runtime', 'region' => 'us-east-1', 'version' => 'latest']) extends \Aws\BedrockRuntime\BedrockRuntimeClient {
            /** @var array<string, array<string, mixed>> */
            public array $capturedOps = [];

            public function __call($name, $args)
            {
                $this->capturedOps[(string) $name] = $args[0] ?? [];

                return new \Aws\Result(['stream' => new \ArrayIterator([])]);
            }
        };

        $provider = new BedrockProvider($client, 'us-east-1');

        iterator_to_array($provider->completeStream(new CompleteRequest(
            model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
            messages: [new UserMessage('Hello')],
        )));
        $this->assertSame(
            120.0,
            $client->capturedOps['converseStream']['@http']['timeout'] ?? null,
            'the streaming operation must carry the decoupled header bound (ISS-075)',
        );

        try {
            $provider->complete(new CompleteRequest(
                model: 'anthropic.claude-3-5-sonnet-20241022-v2:0',
                messages: [new UserMessage('Hello')],
            ));
        } catch (\Throwable) {
            // The canned Result is not a full converse() payload; the pin is
            // about what reached the client, captured before parsing died.
        }

        $this->assertArrayHasKey('converse', $client->capturedOps, 'batch complete() must have reached the client');
        $this->assertArrayNotHasKey(
            '@http',
            $client->capturedOps['converse'],
            'the batch converse() ride must not inherit the stream header bound - on curl that key is a TOTAL deadline (ISS-075)',
        );
    }

    // -------------------------------------------------------------------------
    // The streaming transport: the live path, and the one a bare
    // connect_timeout does nothing for
    // -------------------------------------------------------------------------

    /**
     * Guzzle routes any request carrying `stream => true` to `StreamHandler`,
     * which has no `add_connect_timeout()` and therefore discards
     * `connect_timeout` outright. ISS-024: what reaches the transport now is
     * the DECOUPLED pair - the dedicated header bound in `timeout`, and the
     * generous per-read `read_timeout` — while the connect budget itself is
     * enforced by the pre-flight probe (pinned separately below). Asserting
     * `timeout` still equalled `connect_timeout` here was the pin that ENCODED
     * the bug: a 15s "connect" setting capping the response-header wait.
     *
     * @dataProvider streamingClients
     */
    public function testAStreamingRequestReachesTheTransportWithADecoupledHeaderBoundAndAnUnboundedBody(callable $factory): void
    {
        $client = $factory();
        $options = self::captureHandlerOptions($client, ['stream' => true]);

        $this->assertArrayHasKey('timeout', $options, 'StreamHandler has no connect option at all; timeout is what bounds the head-wait once the pre-flight has bounded the connect');
        $this->assertSame(120.0, $options['timeout'], 'the header bound is its own generous default, not the 15s connect budget wearing two hats');
        $this->assertNotSame($client->getConfig('connect_timeout'), $options['timeout'], 'ISS-024: the connect bound must NOT be what caps the header wait');

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
        $response = $client->request('POST', 'chat/completions', ['stream' => true, 'json' => [], 'candy_connect_preflight' => false]);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(120.0, $captured['timeout'], 'the wrapped bare handler still receives the decoupled header bound (ISS-024)');
    }

    /**
     * ISS-024: the pre-flight probe settles DNS honestly. StreamHandler maps
     * EVERY fopen() failure — including a name that never resolved — to
     * "Connection refused for URI", which is the lie the connect-timeout
     * error text was reported to bury.
     */
    public function testTheConnectPreflightRejectsAnUnresolvableHostWithHonestProse(): void
    {
        $reached = false;
        $inner = static function (RequestInterface $request, array $options) use (&$reached): PromiseInterface {
            $reached = true;

            return new FulfilledPromise(new Response(200, [], 'ok'));
        };

        $client = self::buildSeamClient(['handler' => $inner, 'base_uri' => 'https://candy-preflight.invalid/']);

        try {
            $client->request('POST', 'chat/completions', ['stream' => true, 'json' => []]);
            $this->fail('the probe should have settled the unresolvable host before the transport ran');
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $this->assertStringContainsString('Could not resolve host', $e->getMessage());
            $this->assertStringContainsString('candy-preflight.invalid', $e->getMessage());
        }

        $this->assertFalse($reached, 'the transport must not be dialed after the probe answered DNS');
    }

    /**
     * ISS-024: once the probe HAS connected, StreamHandler's catch-all
     * "Connection refused" is a known lie — the remaining failure it can be
     * describing is the head-wait. The rewriter names the setting that owns
     * that bound and keeps the chain (`TransientFailure::isTransient()` walks
     * `getPrevious()`).
     */
    public function testAPassedPreflightRewritesTheStreamsCatchAllRefusalToNameTheHeaderBound(): void
    {
        $server = @\stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($server, 'a local listener is the whole fixture: ' . $errstr);
        $address = (string) \stream_socket_get_name($server, false);

        try {
            $client = self::buildSeamClient([
                'handler' => static fn (RequestInterface $request, array $options): PromiseInterface => new \GuzzleHttp\Promise\RejectedPromise(
                    new \GuzzleHttp\Exception\ConnectException('Connection refused for URI ' . (string) $request->getUri(), $request),
                ),
                'base_uri' => 'http://' . $address . '/',
            ]);

            try {
                $client->request('POST', 'chat/completions', ['stream' => true, 'json' => []]);
                $this->fail('the lying handler must still surface a rejection');
            } catch (\GuzzleHttp\Exception\ConnectException $e) {
                $this->assertStringContainsString('streamHeaderTimeoutSeconds', $e->getMessage());
                $this->assertStringContainsString('TCP connect itself succeeded', $e->getMessage());
                $this->assertStringNotContainsString('Connection refused for URI', $e->getMessage());
                $this->assertInstanceOf(\GuzzleHttp\Exception\ConnectException::class, $e->getPrevious(), 'the original chain rides through');
            }
        } finally {
            \fclose($server);
        }
    }

    /**
     * The opt-out key is total: with it, no probe, no rewriter — the handler
     * promise is returned untouched so capture-style tests see exactly what
     * they would have seen before this seam existed.
     */
    public function testAPreflightOptOutLeavesEvenTheCatchAllRefusalUntouched(): void
    {
        $client = self::buildSeamClient([
            'handler' => static fn (RequestInterface $request, array $options): PromiseInterface => new \GuzzleHttp\Promise\RejectedPromise(
                new \GuzzleHttp\Exception\ConnectException('Connection refused for URI ' . (string) $request->getUri(), $request),
            ),
            'base_uri' => 'http://127.0.0.1:1/',
        ]);

        try {
            $client->request('POST', 'chat/completions', ['stream' => true, 'json' => [], 'candy_connect_preflight' => false]);
            $this->fail('the handler was built to reject');
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            $this->assertStringStartsWith('Connection refused for URI', $e->getMessage(), 'opt-out must skip the rewriter as well as the probe');
            $this->assertNull($e->getPrevious());
        }
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
            count(array_keys($names, 'candy-code.stream_connect_bounds', true)),
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

        if (isset(self::METADATA_READS[$name])) {
            // The exemption is for a SHORT bound: pin that the file names its
            // own discovery budget rather than an arbitrary figure.
            $this->assertStringContainsString('DISCOVERY_TIMEOUT_SECONDS', $source, $name . ' lost its named discovery budget');

            return;
        }

        if ($name === 'BedrockProvider.php') {
            // ISS-075 carve-in: the ONE legal timeout spelling on this file is
            // the per-operation header bound on converseStream (which the SDK
            // routes to StreamHandler, where it is a head-wait bound, never a
            // total). A client-level `http['timeout']` would reach the curl
            // batch converse() as a total deadline, and an arbitrary figure
            // would drift from the shared accessor - both must redden here.
            $this->assertSame(
                1,
                substr_count($source, "'timeout' => self::streamHeaderTimeoutSeconds()"),
                'Bedrock must carry exactly the one named streamHeaderTimeoutSeconds() bound ISS-075 added',
            );

            foreach (self::TOTAL_TIMEOUT_SPELLINGS as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    str_replace("'timeout' => self::streamHeaderTimeoutSeconds()", '', $source),
                    $name . ' sets a timeout other than the named stream header bound',
                );
            }

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

        foreach ([...array_keys(self::NO_HTTP_CLIENT), ...array_keys(self::METADATA_READS)] as $exempt) {
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
        $previous = getenv('CANDY_CODE_CONNECT_TIMEOUT');

        try {
            putenv('CANDY_CODE_CONNECT_TIMEOUT=2.5');
            $client = self::guzzleClientOf(SglangProvider::openAiCompatible('https://sglang.invalid/v1'));
            $this->assertSame(2.5, $client->getConfig('connect_timeout'));

            // Operator error must not silently disable the bound: every
            // transport in play reads 0 as "use my own default", which is the
            // exact hang being fixed.
            foreach (['0', '-1', 'soon', ''] as $bogus) {
                putenv('CANDY_CODE_CONNECT_TIMEOUT=' . $bogus);
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
        $previous = getenv('CANDY_CODE_CONNECT_TIMEOUT');

        try {
            putenv('CANDY_CODE_CONNECT_TIMEOUT=' . $override);
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
        $previous = getenv('CANDY_CODE_CONNECT_TIMEOUT');

        try {
            putenv('CANDY_CODE_CONNECT_TIMEOUT=0.001');
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

    /**
     * The ISS-024 review fix. Guzzle picks its transport at request time
     * (Utils::chooseHandler consults allow_url_fopen, not our option), so a
     * `stream` flag is only a stream request when the stream wrapper can
     * actually serve it. This table is the routing decision the middleware
     * now makes; the misroute it kills - flagged transfer handed to cURL,
     * where our header bound would land as CURLOPT_TIMEOUT_MS, a TOTAL
     * deadline on an LLM stream - is exactly row 2.
     *
     * @param array<string, mixed> $options
     *
     * @dataProvider streamTransportRows
     */
    public function testTheRoutingTableFollowsTheTransportGuzzleWillActuallyBuild(array $options, bool $fopen, bool $curl, string $expected): void
    {
        $this->assertSame($expected, self::invokeSeamStatic('streamTransportFor', $options, $fopen, $curl));
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, bool, string}>
     */
    public static function streamTransportRows(): array
    {
        return [
            'flagged, both transports available -> the stream wrapper serves it' => [['stream' => true], true, true, 'stream'],
            'flagged, fopen wrappers off -> cURL serves it, stream options must not ride' => [['stream' => true], false, true, 'curl'],
            'unflagged with cURL available -> plain transport, untouched' => [[], true, true, 'none'],
            'unflagged even with fopen off -> still untouched; the middleware is for streams' => [[], false, true, 'none'],
            'no cURL, fopen on -> the stream wrapper is all there is' => [['stream' => true], true, false, 'stream'],
            'no cURL, fopen off -> still stream semantics; Guzzle itself refuses at construction' => [['stream' => true], false, false, 'stream'],
            'no cURL, unflagged, fopen on -> stream transport serves everything' => [[], true, false, 'stream'],
            'no cURL, unflagged, fopen off -> same answer; the refusal is Guzzle\'s to make' => [[], false, false, 'stream'],
        ];
    }

    public function testTheCurlWatchdogStallsAgainstTheHeaderBoundBeforeTheFirstByte(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $stall = new \ArrayObject(['aborted' => false, 'bound' => 0.0, 'phase' => 'response headers']);
        $bounds = self::invokeSeamStatic('curlStreamBounds', [], $stall, $clock);
        \assert(\is_callable($bounds['progress']));
        $headerBound = (float) self::invokeSeamStatic('streamHeaderTimeoutSeconds');

        $now = $headerBound - 1.0;
        $this->assertSame(0, $bounds['progress'](0, 0, 0, 0), 'just under the header bound keeps the transfer - the bound is per-operation, not a total');
        $this->assertFalse((bool) $stall['aborted']);

        $now = $headerBound + 1.0;
        $this->assertSame(1, $bounds['progress'](0, 0, 0, 0), 'silence past the header bound with nothing downloaded yet answers libcurl the abort');
        $this->assertTrue((bool) $stall['aborted']);
        $this->assertSame($headerBound, (float) $stall['bound']);
        $this->assertSame('response headers', (string) $stall['phase']);
    }

    public function testTheCurlWatchdogReArmsOnActivityAndSwitchesToTheIdleBound(): void
    {
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $stall = new \ArrayObject(['aborted' => false, 'bound' => 0.0, 'phase' => 'response headers']);
        $bounds = self::invokeSeamStatic('curlStreamBounds', [], $stall, $clock);
        \assert(\is_callable($bounds['progress']));
        $headerBound = (float) self::invokeSeamStatic('streamHeaderTimeoutSeconds');
        $idleBound = (float) self::invokeSeamStatic('streamReadIdleTimeoutSeconds');
        $this->assertGreaterThan($headerBound, $idleBound, 'the idle bound is the longer one - the legs below only discriminate because it is');

        // Long past the header bound, but bytes are landing: the tick that
        // sees growth re-arms the clock AND switches the phase, so a body
        // still streaming is never accused of missing its header deadline.
        $now = $headerBound + 1.0;
        $this->assertSame(0, $bounds['progress'](0, 42, 0, 0));
        $this->assertFalse((bool) $stall['aborted']);

        $now = $headerBound + 1.0 + $idleBound - 1.0;
        $this->assertSame(0, $bounds['progress'](0, 42, 0, 0), 'just under the idle bound keeps the transfer');
        $this->assertFalse((bool) $stall['aborted']);

        $now += 2.0;
        $this->assertSame(1, $bounds['progress'](0, 42, 0, 0), 'silence past the re-armed idle bound aborts');
        $this->assertTrue((bool) $stall['aborted']);
        $this->assertSame($idleBound, (float) $stall['bound']);
        $this->assertSame('a further body frame', (string) $stall['phase']);
    }

    public function testTheCurlWatchdogChainsTheHeartbeatProgressAndSurvivesItsThrow(): void
    {
        $ticks = 0;
        $existing = static function (int $total, int $downloaded, int $uTotal, int $uploaded) use (&$ticks): void {
            $ticks++;

            throw new \RuntimeException('a heartbeat consumer must never break the transfer');
        };
        $now = 0.0;
        $clock = static function () use (&$now): float {
            return $now;
        };
        $stall = new \ArrayObject(['aborted' => false, 'bound' => 0.0, 'phase' => 'response headers']);
        $bounds = self::invokeSeamStatic('curlStreamBounds', ['progress' => $existing], $stall, $clock);
        \assert(\is_callable($bounds['progress']));

        $this->assertSame(0, $bounds['progress'](0, 0, 0, 0), 'the chained heartbeat throw is swallowed and the watchdog keeps ticking');
        $this->assertSame(1, $ticks);
        $this->assertFalse((bool) $stall['aborted']);

        $now = (float) self::invokeSeamStatic('streamHeaderTimeoutSeconds') + 1.0;
        $this->assertSame(1, $bounds['progress'](0, 0, 0, 0));
        $this->assertSame(1, $ticks, 'the aborting tick answers libcurl without consulting the chain');
    }

    public function testTheCurlArmAttachesTheWatchdogWithoutWritingAnyTimeoutOption(): void
    {
        $captured = [];
        $handler = static function (RequestInterface $request, array $options) use (&$captured): PromiseInterface {
            $captured = $options;

            return new FulfilledPromise(new Response(200, [], 'ok'));
        };
        $promise = self::invokeSeamStatic(
            'curlStreamArm',
            $handler,
            new Request('POST', 'https://seam.invalid/stream'),
            ['stream' => true],
        );
        $this->assertInstanceOf(PromiseInterface::class, $promise);
        \assert($promise instanceof PromiseInterface);
        $this->assertSame('ok', (string) $promise->wait()->getBody());

        // THE anti-total law, asserted at the arm itself: on this route the
        // bounds live ONLY in the progress watchdog. `timeout` here would be
        // CURLOPT_TIMEOUT_MS - a wall-clock deadline over the whole stream -
        // which is the defect class ISS-024 exists to keep closed.
        $this->assertIsCallable($captured['progress']);
        $this->assertArrayNotHasKey('timeout', $captured);
        $this->assertArrayNotHasKey('read_timeout', $captured);
    }

    public function testTheStallRewriterNamesTheBoundThatFiredAndKeepsTheFailureTransient(): void
    {
        $request = new Request('POST', 'https://seam.invalid/stream');
        $stall = new \ArrayObject(['aborted' => true, 'bound' => 120.0, 'phase' => 'response headers']);

        $modern = new RequestException(
            'cURL error 42: Operation was aborted by an application callback (see https://curl.se/libcurl/c/libcurl-errors.html) for https://seam.invalid/stream',
            $request,
            null,
            null,
            ['errno' => 42],
        );
        $rewritten = self::invokeSeamStatic('curlStallFailure', $modern, $request, $stall);
        $this->assertNotSame($modern, $rewritten);
        $this->assertStringContainsString('no bytes for 120s while waiting for response headers', $rewritten->getMessage());
        $this->assertStringContainsString('NOT a total deadline', $rewritten->getMessage());
        $this->assertSame($modern, $rewritten->getPrevious());
        $this->assertTrue(TransientFailure::isTransient($rewritten), 'a stall cut stays in the retryable transport family');

        // Pre-7.69 libcurl spells the same abort with different words and
        // older shapes may not carry the context at all - the errno gate has
        // a text fallback for exactly that.
        $legacy = new RequestException('Abort was called on an request', $request);
        $this->assertNotSame($legacy, self::invokeSeamStatic('curlStallFailure', $legacy, $request, $stall));

        // A transfer aborted after its 2xx headers carry a partial response
        // in Guzzle's exception. It is dropped, deliberately: a truncated
        // stream is not a response to route on, and keeping it would push
        // the failure out of the no-response family TransientFailure retries.
        $partial = new RequestException(
            'cURL error 42: Operation was aborted by an application callback (see https://curl.se/libcurl/c/libcurl-errors.html) for https://seam.invalid/stream',
            $request,
            new Response(200),
            null,
            ['errno' => 42],
        );
        $dropped = self::invokeSeamStatic('curlStallFailure', $partial, $request, new \ArrayObject(['aborted' => true, 'bound' => 600.0, 'phase' => 'a further body frame']));
        $this->assertInstanceOf(RequestException::class, $dropped);
        \assert($dropped instanceof RequestException);
        $this->assertFalse($dropped->hasResponse());
        $this->assertSame($partial, $dropped->getPrevious());
        $this->assertStringContainsString('no bytes for 600s while waiting for a further body frame', $dropped->getMessage());
    }

    public function testTheStallRewriterLeavesEveryOtherFailureExactlyAsItArrived(): void
    {
        $request = new Request('POST', 'https://seam.invalid/stream');
        $aborted = new \ArrayObject(['aborted' => true, 'bound' => 120.0, 'phase' => 'response headers']);
        $silent = new \ArrayObject(['aborted' => false, 'bound' => 0.0, 'phase' => 'response headers']);

        $timeout = new RequestException('cURL error 28: Timeout was reached (see https://curl.se/libcurl/c/libcurl-errors.html) for https://seam.invalid/stream', $request, null, null, ['errno' => 28]);
        $this->assertSame($timeout, self::invokeSeamStatic('curlStallFailure', $timeout, $request, $aborted), "curl's own timeout text is not the watchdog's sentence to overwrite");

        $connect = new ConnectException('cURL error 7: Failed to connect to seam.invalid port 443 after 5 ms (see https://curl.se/libcurl/c/libcurl-errors.html) for https://seam.invalid/stream', $request);
        $this->assertSame($connect, self::invokeSeamStatic('curlStallFailure', $connect, $request, $aborted));

        $abortTextWithoutTheWatchdog = new RequestException('cURL error 42: Operation was aborted by an application callback', $request, null, null, ['errno' => 42]);
        $this->assertSame(
            $abortTextWithoutTheWatchdog,
            self::invokeSeamStatic('curlStallFailure', $abortTextWithoutTheWatchdog, $request, $silent),
            'when the watchdog never fired, the canned libcurl text survives - rewriting someone else\'s abort would be a lie',
        );

        $plain = new \RuntimeException('other');
        $this->assertSame($plain, self::invokeSeamStatic('curlStallFailure', $plain, $request, $aborted));
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

        $client->request('POST', 'chat/completions', $requestOptions + ['json' => ['probe' => true], 'candy_connect_preflight' => false]);

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

    /**
     * The seam's own private static helpers, reached through a class that
     * uses the trait - the buildSeamClient idiom widened to any method.
     */
    private static function invokeSeamStatic(string $method, mixed ...$args): mixed
    {
        $reflection = new \ReflectionMethod(SglangProvider::class, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke(null, ...$args);
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
            putenv('CANDY_CODE_CONNECT_TIMEOUT');

            return;
        }

        putenv('CANDY_CODE_CONNECT_TIMEOUT=' . $previous);
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
