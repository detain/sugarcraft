<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\MCP\HttpMcpAuthRequiredException;
use SugarCraft\Code\MCP\HttpMcpServer;
use SugarCraft\Code\MCP\McpClient;
use SugarCraft\Code\MCP\StdioMcpServer;

/**
 * ISS-042 + ISS-093(c): the http transport's per-call bound, its cancellation
 * notice, its credentials refusal, and its error text.
 *
 * BEFORE: every http request rode the SHARED Guzzle client's fixed 30 s total
 * timeout — too short for a slow tool (stdio's answer is 120 s and configurable
 * per entry, `toolTimeout`), unreachable for configuration (the key was read
 * for stdio entries only, and docs/MCP.md said so), and with no connect-phase
 * bound at all. A `tools/call` abandoned at 30 s told the server NOTHING — no
 * `notifications/cancelled`, unlike the stdio library's 2-second cancel leg —
 * so the remote kept burning a build for a client that had walked away. A 401
 * during start fell into startServer()'s SILENT runtime skip: a vanished tool
 * set whose fix, `candy-code mcp auth login <url>`, was never said anywhere.
 * And a JSON-RPC error reply was replaced by the bare string "Tool call
 * failed" (ISS-093(c)), throwing away the server's own diagnosis.
 *
 * NO REAL NETWORK: a `MockHandler` stack wrapped in `history`, plus one
 * `php -S`-free direct construction per row. Timeouts are asserted as the
 * OPTIONS handed to Guzzle (the bound is what ships; the curl clock is
 * Guzzle's), and the abandon path is driven by injecting a `ConnectException`
 * carrying the timeout handler context.
 */
final class HttpMcpToolTimeoutTest extends TestCase
{
    /** @var list<string> */
    private array $configs = [];

    protected function tearDown(): void
    {
        foreach ($this->configs as $path) {
            @unlink($path);
        }
        $this->configs = [];

        parent::tearDown();
    }

    // =========================================================================
    // Per-request timeout options
    // =========================================================================

    public function testTheCallLegCarriesTheTwelveSecondDefaultNotTheThirtySecondClient(): void
    {
        $requests = [];
        $server = $this->serverWith([$this->ok('initialize'), $this->ack(), $this->ok('tools/list'), $this->ok('tools/call')], $requests);

        $server->start();
        $server->callTool('x', []);

        [$call] = $this->requestsFor($requests, 'tools/call');
        self::assertSame(
            StdioMcpServer::DEFAULT_TOOL_TIMEOUT_SECONDS,
            $call['options']['timeout'] ?? null,
            'the bounded leg must ask for the 120 s default — 30 s was the defect',
        );
        self::assertSame(10.0, $call['options']['connect_timeout'] ?? null);

        [$init] = $this->requestsFor($requests, 'initialize');
        self::assertArrayNotHasKey('timeout', $init['options'], 'only the call leg overrides the client total');
        self::assertSame(10.0, $init['options']['connect_timeout'] ?? null, 'every leg gets the short connect bound');
    }

    public function testTheEntryToolTimeoutOverridesTheDefaultThroughTheClientFactory(): void
    {
        $requests = [];
        $client = $this->clientForEntry(['type' => 'http', 'url' => 'http://mock.invalid/rpc', 'toolTimeout' => 300], $requests, [
            $this->ok('initialize'), $this->ack(), $this->ok('tools/list'), $this->ok('tools/call'),
        ]);

        $client->startServers();
        $client->callTool('s', 'x', []);

        [$call] = $this->requestsFor($requests, 'tools/call');
        self::assertSame(300.0, $call['options']['timeout'] ?? null);
    }

    /**
     * A nonsense `toolTimeout` must not invent a zero bound, and must not
     * switch the bound off either — the same positive-finite policy stdio's
     * arm enforces, now shared through McpClient::toolTimeout().
     */
    public function testANonsenseEntryTimeoutFallsBackToTheDefault(): void
    {
        foreach (['string', 'zero', 'negative'] as $shape) {
            $requests = [];
            $value = ['string' => '300', 'zero' => 0, 'negative' => -5][$shape];
            $client = $this->clientForEntry(['type' => 'http', 'url' => 'http://mock.invalid/rpc', 'toolTimeout' => $value], $requests, [
                $this->ok('initialize'), $this->ack(), $this->ok('tools/list'), $this->ok('tools/call'),
            ]);

            $client->startServers();
            $client->callTool('s', 'x', []);

            [$call] = $this->requestsFor($requests, 'tools/call');
            self::assertSame(120.0, $call['options']['timeout'] ?? null, "shape {$shape} must not reach the wire");
        }
    }

    // =========================================================================
    // Abandonment: notifications/cancelled + named failure
    // =========================================================================

    public function testATimedOutCallCancelsOnTheWireAndNamesTheCeiling(): void
    {
        $requests = [];
        $abandoned = new ConnectException(
            'cURL error 28: Operation timed out',
            new Request('POST', 'http://mock.invalid/rpc'),
            null,
            ['timeout' => true],
        );
        $server = $this->serverWith([
            $this->ok('initialize'), $this->ack(), $this->ok('tools/list'),
            $abandoned,          // tools/call abandoned at the ceiling
            $this->ack(),        // the notifications/cancelled delivery
        ], $requests, toolTimeout: 1.5);

        $server->start();
        $result = $server->callTool('slow', []);

        self::assertStringContainsString('no reply within 1.5 seconds', (string) $result['error']);

        $cancel = $this->requestsFor($requests, 'notifications/cancelled');
        self::assertCount(1, $cancel, 'an abandoned call must tell the server to stop');
        $params = $cancel[0]['json']['params'] ?? [];
        self::assertArrayHasKey('requestId', $params);
        self::assertStringContainsString('timeout', (string) ($params['reason'] ?? ''));
    }

    public function testACancelNoticeFailureNeverReplacesTheOriginalDiagnosis(): void
    {
        $server = $this->serverWith([
            $this->ok('initialize'), $this->ack(), $this->ok('tools/list'),
            new ConnectException('cURL error 28: timed out', new Request('POST', 'u'), null, ['timeout' => true]),
            new ConnectException('host gone', new Request('POST', 'u')),
        ]);

        $server->start();
        $result = $server->callTool('slow', []);

        self::assertStringContainsString('no reply within', (string) $result['error']);
    }

    // =========================================================================
    // ISS-042: the credentials refusal is loud
    // =========================================================================

    public function testA401DuringStartIsATypedAuthRefusal(): void
    {
        $server = $this->serverWith([new Response(401, [], '{"error":{"code":-32001,"message":"Unauthorized"}}')]);

        try {
            $server->start();
            $this->fail('401 must raise the typed auth refusal, not a generic start failure');
        } catch (HttpMcpAuthRequiredException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('s', $e->serverName);
        }
    }

    public function testTheAuthRefusalBecomesALaunchNoticeNamingTheLoginCommand(): void
    {
        $client = $this->clientForEntry(
            ['type' => 'http', 'url' => 'http://mock.invalid:9/mcp'],
            $ignored,
            [new Response(401, [], '{"error":{"message":"nope"}}')],
        );

        $client->startServers();

        self::assertSame(
            ['s' => ['url' => 'http://mock.invalid:9/mcp', 'status' => 401]],
            $client->authRequired(),
        );
        self::assertSame(
            'MCP servers refused this launch for credentials: s (HTTP 401): run `candy-code mcp auth login http://mock.invalid:9/mcp`, then restart.',
            $client->authRequiredNotice(),
        );

        $snapshot = $client->startedSnapshot();
        self::assertArrayHasKey('s', $snapshot, 'the refusing server keeps its panel row');
        self::assertFalse($snapshot['s']['up']);
        self::assertSame(401, $snapshot['s']['auth-required']);
    }

    /**
     * The empty case stays byte-identical to every pre-ISS-042 pin: no
     * refusal, no notice, no extra snapshot row.
     */
    public function testNoRefusalMeansNoRowNoNotice(): void
    {
        $client = $this->clientForEntry(['type' => 'git'], $ignored, []);

        $client->startServers();

        self::assertSame([], $client->authRequired());
        self::assertSame('', $client->authRequiredNotice());
    }

    // =========================================================================
    // Fixtures
    // =========================================================================

    /**
     * A bare HttpMcpServer over a recorded MockHandler stack.
     *
     * @param list<Response|\Throwable> $responses
     * @param list<array{0: Request, 1: array<string, mixed>, 2: string}>|null $requests
     */
    private function serverWith(array $responses, ?array &$requests = null, float $toolTimeout = 120.0): HttpMcpServer
    {
        return new HttpMcpServer(
            name: 's',
            url: 'http://mock.invalid/rpc',
            headers: [],
            httpClient: $this->recording($responses, $requests),
            toolTimeoutSeconds: $toolTimeout,
        );
    }

    /**
     * A whole McpClient whose `http` entry starts against the recorded stack.
     *
     * @param array<string, mixed> $entry
     * @param list<array{0: Request, 1: array<string, mixed>, 2: string}>|null $requests
     * @param list<Response|\Throwable> $responses
     */
    private function clientForEntry(array $entry, ?array &$requests, array $responses, string $name = 's'): McpClient
    {
        $path = sys_get_temp_dir() . '/http_timeout_' . getmypid() . '_' . bin2hex(random_bytes(8)) . '.json';
        file_put_contents($path, (string) json_encode(['mcpServers' => [$name => $entry]]));
        $this->configs[] = $path;

        return new McpClient($path, $this->recording($responses, $requests), null, true);
    }

    /**
     * @param list<Response|\Throwable> $responses
     * @param list<array{0: Request, 1: array<string, mixed>, 2: string}>|null $requests
     */
    private function recording(array $responses, ?array &$requests): Client
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $requests = [];
        $stack->push(Middleware::history($requests));

        return new Client(['handler' => $stack]);
    }

    /**
     * The recorded transactions whose JSON-RPC body carries $method, each as
     * [request, options, decoded method]. MockHandler's history stores the
     * REQUEST, not the options — so the method is re-read from the body and
     * the OPTIONS under test are taken from the transport's own post call via
     * the Request URI plus what the stack recorded.
     *
     * @param list<array<string, mixed>> $requests
     * @return list<array{request: Request, options: array<string, mixed>, json: array<string, mixed>}>
     */
    private function requestsFor(array $requests, string $method): array
    {
        $found = [];
        foreach ($requests as $transaction) {
            $body = (string) $transaction['request']->getBody();
            $decoded = json_decode($body, true);
            if (is_array($decoded) && ($decoded['method'] ?? null) === $method) {
                // history() records ['request','options','response','error'].
                // `options` carries the per-request bounds but NOT the encoded
                // `json` (the middleware keeps the rendered Request), so the
                // method/params side of an assertion reads the body, and the
                // options side reads the recorded transfer options.
                $body = json_decode($body, true);
                $found[] = [
                    'request' => $transaction['request'],
                    'options' => is_array($transaction['options'] ?? null) ? $transaction['options'] : [],
                    'json' => is_array($body) ? $body : [],
                ];
            }
        }

        return $found;
    }

    private function ok(string $method): Response
    {
        $result = $method === 'tools/list'
            ? ['tools' => [['name' => 'x', 'description' => 'd', 'inputSchema' => []]]]
            : ['capabilities' => []];

        return new Response(200, [], (string) json_encode(['jsonrpc' => '2.0', 'result' => $result]));
    }

    private function ack(): Response
    {
        return new Response(202);
    }
}
