<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Crush\MCP\HttpMcpServer;

/**
 * Audit MCP-2: {@see HttpMcpServer} could not complete a Streamable HTTP
 * handshake. MEASURED against `@modelcontextprotocol/server-everything
 * streamableHttp`: `start()` died on `406 Not Acceptable: Client must accept
 * both application/json and text/event-stream`, and past that the server's
 * `Mcp-Session-Id` was never echoed (400 "No valid session ID"),
 * `notifications/initialized` was never sent, an SSE reply body was
 * json_decoded into "invalid response", the initialize reply was ignored, and
 * empty maps reached the wire as `[]`.
 *
 * Every test drives a REAL Guzzle client over a MockHandler with the history
 * middleware, so what is asserted is the request that would hit the wire —
 * headers, raw JSON body bytes, method — not the options array a mocked
 * client happened to be handed.
 */
final class HttpMcpServerStreamableHttpTest extends TestCase
{
    private const URL = 'http://mcp.test/mcp';

    private const SESSION = 'session-abc-123';

    /** @var list<array{request: RequestInterface}> */
    private array $history = [];

    /**
     * @param list<Response|\Throwable|callable> $responses
     * @param array<string, string> $headers
     */
    private function server(array $responses, array $headers = [], ?\Closure $pidProvider = null): HttpMcpServer
    {
        $this->history = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return new HttpMcpServer(
            name: 'streamable',
            url: self::URL,
            headers: $headers,
            httpClient: new Client(['handler' => $stack]),
            pidProvider: $pidProvider,
        );
    }

    /** @param array<string, mixed> $result */
    private static function initReply(array $result = [], ?string $session = self::SESSION): Response
    {
        $headers = ['Content-Type' => 'application/json'];
        if ($session !== null) {
            $headers['Mcp-Session-Id'] = $session;
        }

        return new Response(200, $headers, (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 0,
            'result' => $result + [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'fixture', 'version' => '0.0.1'],
            ],
        ]));
    }

    /** @param list<array{name: string}> $tools */
    private static function toolsReply(int $id, array $tools = [['name' => 'echo']]): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => ['tools' => $tools],
        ]));
    }

    private static function sse(string $body): Response
    {
        return new Response(200, ['Content-Type' => 'text/event-stream; charset=utf-8'], $body);
    }

    private function request(int $index): RequestInterface
    {
        self::assertArrayHasKey($index, $this->history, "request #{$index} was never sent");

        return $this->history[$index]['request'];
    }

    /** @return array<mixed> */
    private function body(int $index): array
    {
        $decoded = json_decode((string) $this->request($index)->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testEveryPostAcceptsBothMediaTypesAndDeclaresJson(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(1),
            new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":2,"result":{"content":[]}}'),
        ]);

        $server->start();
        $server->callTool('echo', ['message' => 'hi']);

        self::assertCount(4, $this->history);
        foreach ($this->history as $i => $entry) {
            $request = $entry['request'];
            self::assertSame('POST', $request->getMethod());
            self::assertSame(HttpMcpServer::ACCEPT, $request->getHeaderLine('Accept'), "request #{$i} Accept");
            self::assertStringContainsString('application/json', $request->getHeaderLine('Accept'));
            self::assertStringContainsString('text/event-stream', $request->getHeaderLine('Accept'));
            self::assertSame('application/json', $request->getHeaderLine('Content-Type'), "request #{$i} Content-Type");
        }
    }

    public function testAnOperatorAcceptHeaderCannotDropTheEventStreamRequirement(): void
    {
        $server = $this->server(
            [self::initReply(), new Response(202), self::toolsReply(1)],
            ['accept' => 'application/json', 'X-Api-Key' => 'k'],
        );

        $server->start();

        foreach ($this->history as $entry) {
            self::assertSame([HttpMcpServer::ACCEPT], $entry['request']->getHeader('Accept'));
            self::assertSame('k', $entry['request']->getHeaderLine('X-Api-Key'));
        }
    }

    public function testTheInitializeSessionIdIsEchoedOnEveryLaterRequest(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(1),
            new Response(200, [], '{"jsonrpc":"2.0","id":2,"result":{"content":[]}}'),
        ]);

        $server->start();
        $server->callTool('echo', []);

        self::assertFalse($this->request(0)->hasHeader('Mcp-Session-Id'), 'initialize must not carry a session id');
        foreach ([1, 2, 3] as $i) {
            self::assertSame(self::SESSION, $this->request($i)->getHeaderLine('Mcp-Session-Id'), "request #{$i}");
        }
    }

    public function testAStatelessServerGetsNoSessionHeader(): void
    {
        $server = $this->server([self::initReply([], null), new Response(202), self::toolsReply(1)]);

        $server->start();

        foreach ($this->history as $entry) {
            self::assertFalse($entry['request']->hasHeader('Mcp-Session-Id'));
        }
        self::assertTrue($server->isUp());
    }

    public function testTheInitializedNotificationIsAnIdlessPostBetweenInitializeAndToolsList(): void
    {
        $server = $this->server([self::initReply(), new Response(202), self::toolsReply(1)]);

        $server->start();

        self::assertSame('initialize', $this->body(0)['method']);
        self::assertSame(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->body(1));
        self::assertSame('tools/list', $this->body(2)['method']);
    }

    public function testEmptyMapsReachTheWireAsObjects(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(1),
            new Response(200, [], '{"jsonrpc":"2.0","id":2,"result":{"content":[]}}'),
        ]);

        $server->start();
        $server->callTool('get-tiny-image', []);

        $raw = fn (int $i): string => (string) $this->request($i)->getBody();
        self::assertStringContainsString('"capabilities":{}', $raw(0));
        self::assertStringNotContainsString('"params"', $raw(2), 'tools/list sends no params, like the reference TS client');
        self::assertStringContainsString('"arguments":{}', $raw(3));
        self::assertStringNotContainsString('[]', $raw(0) . $raw(2) . $raw(3));
    }

    public function testRequestIdsAreUniqueAcrossTheSession(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(1),
            new Response(200, [], '{"jsonrpc":"2.0","id":2,"result":{}}'),
            new Response(200, [], '{"jsonrpc":"2.0","id":3,"result":{}}'),
        ]);

        $server->start();
        $server->callTool('a', []);
        $server->callTool('b', []);

        $ids = [];
        foreach ($this->history as $i => $entry) {
            $body = $this->body($i);
            if (array_key_exists('id', $body)) {
                $ids[] = $body['id'];
            }
        }
        self::assertCount(4, $ids);
        self::assertSame($ids, array_values(array_unique($ids)));
    }

    /**
     * Both legs answered as `text/event-stream`. The tools/list stream opens
     * with a comment, splits its JSON over two `data:` lines (joined with
     * "\n", which is JSON whitespace) and uses CRLF line endings; the
     * tools/call stream carries a progress notification, an unrelated event
     * type and a reply to some other id BEFORE our response.
     */
    public function testSseRepliesToToolsListAndToolsCallAreParsed(): void
    {
        $listStream = ": keep-alive\r\n"
            . "event: message\r\n"
            . "id: evt-1\r\n"
            . "data: {\"jsonrpc\":\"2.0\",\"id\":1,\r\n"
            . "data: \"result\":{\"tools\":[{\"name\":\"echo\",\"description\":\"Echoes\"},{\"name\":\"get-sum\"}]}}\r\n"
            . "\r\n";

        $callStream = "event: message\n"
            . 'data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":1,"progress":1}}' . "\n\n"
            . "event: endpoint\n"
            . 'data: {"jsonrpc":"2.0","id":2,"result":{"content":[{"type":"text","text":"wrong event type"}]}}' . "\n\n"
            . 'data: {"jsonrpc":"2.0","id":99,"result":{"content":[{"type":"text","text":"someone else"}]}}' . "\n\n"
            . 'data: {"jsonrpc":"2.0","id":2,"method":"sampling/createMessage","params":{}}' . "\n\n"
            . "data:{\"jsonrpc\":\"2.0\",\"id\":2,\"result\":{\"content\":[{\"type\":\"text\",\"text\":\"Echo: hi\"}]}}\n\n";

        $server = $this->server([self::initReply(), new Response(202), self::sse($listStream), self::sse($callStream)]);

        $server->start();

        $names = array_map(static fn ($tool): string => $tool->name, $server->listTools());
        self::assertSame(['echo', 'get-sum'], $names);
        self::assertSame(
            ['content' => [['type' => 'text', 'text' => 'Echo: hi']]],
            $server->callTool('echo', ['message' => 'hi']),
        );
    }

    public function testAnSseInitializeReplyIsParsedAndItsSessionKept(): void
    {
        $init = self::sse('data: {"jsonrpc":"2.0","id":0,"result":{"protocolVersion":"2024-11-05","capabilities":{}}}' . "\n\n")
            ->withHeader('Mcp-Session-Id', self::SESSION);

        $server = $this->server([$init, new Response(202), self::toolsReply(1)]);
        $server->start();

        self::assertCount(1, $server->listTools());
        self::assertSame(self::SESSION, $this->request(2)->getHeaderLine('Mcp-Session-Id'));
    }

    public function testAnSseStreamWithoutOurResponseIsAnErrorNotASilentEmptyResult(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(1),
            self::sse('data: {"jsonrpc":"2.0","method":"notifications/message","params":{}}' . "\n\n"),
        ]);
        $server->start();

        $result = $server->callTool('echo', []);

        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('without a response to request 2', $result['error']);
    }

    public function testAnInitializeErrorReplyFailsStartWithTheServersMessage(): void
    {
        $server = $this->server([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 0,
                'error' => ['code' => -32602, 'message' => 'Unsupported protocol version: fixture refusal'],
            ])),
        ]);

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'an initialize error reply must fail start()');
        self::assertStringContainsString('Failed to start MCP server streamable', $caught->getMessage());
        self::assertStringContainsString('-32602', $caught->getMessage());
        self::assertStringContainsString('Unsupported protocol version: fixture refusal', $caught->getMessage());
        self::assertCount(1, $this->history, 'nothing may follow a refused initialize');
        self::assertFalse($server->isUp());
        self::assertSame([], $server->listTools());
    }

    /**
     * The start-time `tools/list` is gated like `initialize`: an error reply
     * (session- or capability-gated servers answer -32601 here) is not "up,
     * 0 tools" — that shape looks connected, exposes nothing, and throws the
     * server's own diagnosis away. Same gate as sugar-mcp StdioMcpServer::start().
     */
    public function testAToolsListErrorReplyFailsStartWithTheServersMessage(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => 1,
                'error' => ['code' => -32601, 'message' => 'tools are session-gated'],
            ])),
            new Response(200),
        ]);

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a tools/list error reply started the server as "up, 0 tools"');
        self::assertStringContainsString('Failed to start MCP server streamable', $caught->getMessage());
        self::assertStringContainsString('tools/list refused (-32601)', $caught->getMessage());
        self::assertStringContainsString('tools are session-gated', $caught->getMessage());
        self::assertFalse($server->isUp());
        self::assertSame([], $server->listTools());
        self::assertCount(4, $this->history, 'the refused start must end the session the server issued');
        $this->assertSessionDelete(3);
    }

    /**
     * A start that fails AFTER the server issued a session id ends that
     * session with the same best-effort DELETE stop() sends. Forgetting it
     * only locally left it open on the server for good: stop() then finds no
     * session to end, and a retried start() opens a second one.
     */
    public function testAStartThatFailsAfterTheHandshakeDeletesTheSession(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            new Response(500, [], 'boom'),
            new Response(200),
        ]);

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertStringContainsString('HTTP 500', $caught->getMessage());
        self::assertCount(4, $this->history);
        $this->assertSessionDelete(3);

        $server->stop();
        self::assertCount(4, $this->history, 'the session was already ended; stop() has nothing left to delete');
    }

    /**
     * The DELETE is best-effort here exactly as in stop(): a refused or failed
     * one must not replace the start failure's own diagnosis.
     */
    public function testAFailedSessionDeleteDoesNotMaskTheStartFailure(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1}'),
            new ConnectException('host went away', new Request('DELETE', self::URL)),
        ]);

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertStringContainsString('tools/list returned no result', $caught->getMessage());
        self::assertCount(4, $this->history);
        $this->assertSessionDelete(3);
        self::assertFalse($server->isUp());
    }

    private function assertSessionDelete(int $index): void
    {
        $delete = $this->request($index);
        self::assertSame('DELETE', $delete->getMethod());
        self::assertSame(self::URL, (string) $delete->getUri());
        self::assertSame(self::SESSION, $delete->getHeaderLine('Mcp-Session-Id'));
    }

    /**
     * An error member with no usable message is described by encoding it — and
     * a decoded error may carry INF (`1e999` on the wire), which json_encode()
     * refuses. The diagnosis must still say something rather than go blank.
     */
    public function testAnUnencodableErrorIsStillDescribed(): void
    {
        $server = $this->server([
            new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":0,"error":{"code":-32000,"message":"","data":1e999}}'),
        ]);

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught);
        self::assertStringContainsString('"message":""', $caught->getMessage(), 'the refusal lost its diagnosis to an encode failure');
    }

    public function testAToolsListReplyWithoutResultFailsStart(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            new Response(200, ['Content-Type' => 'application/json'], '{"jsonrpc":"2.0","id":1}'),
            new Response(200),
        ]);

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a tools/list reply without a result started the server');
        self::assertStringContainsString('tools/list returned no result', $caught->getMessage());

        self::assertCount(4, $this->history, 'the failed start must end the session the server issued');
        $this->assertSessionDelete(3);
    }

    public function testAnInitializeReplyWithoutResultFailsStart(): void
    {
        $server = $this->server([new Response(200, [], '{"jsonrpc":"2.0","id":0}')]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('initialize returned no result');

        $server->start();
    }

    /** A 406 the way server-everything sends it: the JSON-RPC error message leads the failure. */
    public function testANon2xxReplyNamesTheServersOwnErrorMessage(): void
    {
        $server = $this->server([
            new Response(406, ['Content-Type' => 'application/json'], (string) json_encode([
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32000, 'message' => 'Not Acceptable: Client must accept both application/json and text/event-stream'],
            ])),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('HTTP 406 Not Acceptable (-32000): Not Acceptable: Client must accept both');

        $server->start();
    }

    public function testARefusedInitializedNotificationFailsStart(): void
    {
        $server = $this->server([self::initReply(), new Response(400, [], 'Bad Request'), new Response(200)]);

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a refused notifications/initialized started the server');
        self::assertStringContainsString('HTTP 400 Bad Request: Bad Request', $caught->getMessage());

        self::assertCount(3, $this->history, 'the failed start must end the session the server issued');
        $this->assertSessionDelete(2);
    }

    public function testProtocolVersionHeaderIsOmittedForA2024Session(): void
    {
        $server = $this->server([self::initReply(), new Response(202), self::toolsReply(1)]);
        $server->start();

        foreach ($this->history as $entry) {
            self::assertFalse($entry['request']->hasHeader('MCP-Protocol-Version'));
        }
    }

    public function testProtocolVersionHeaderFollowsA2025_06_18Negotiation(): void
    {
        $server = $this->server([
            self::initReply(['protocolVersion' => '2025-06-18']),
            new Response(202),
            self::toolsReply(1),
        ]);
        $server->start();

        self::assertFalse($this->request(0)->hasHeader('MCP-Protocol-Version'), 'the version is not known until initialize answers');
        self::assertSame('2025-06-18', $this->request(1)->getHeaderLine('MCP-Protocol-Version'));
        self::assertSame('2025-06-18', $this->request(2)->getHeaderLine('MCP-Protocol-Version'));
    }

    /**
     * Spec: a 404 to a request carrying a session id means the session is
     * gone and the client MUST start a new one. Done once: re-initialize,
     * re-notify, resend under the NEW session id.
     */
    public function testAnExpiredSessionIsReinitializedOnceAndTheCallRetried(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(1),
            new Response(404, [], 'Session not found'),
            self::initReply([], 'session-two'),
            new Response(202),
            new Response(200, [], '{"jsonrpc":"2.0","id":4,"result":{"content":[{"type":"text","text":"ok"}]}}'),
        ]);
        $server->start();

        $result = $server->callTool('echo', []);

        self::assertSame(['content' => [['type' => 'text', 'text' => 'ok']]], $result);
        self::assertCount(7, $this->history);
        self::assertSame(self::SESSION, $this->request(3)->getHeaderLine('Mcp-Session-Id'));
        self::assertSame('initialize', $this->body(4)['method']);
        self::assertFalse($this->request(4)->hasHeader('Mcp-Session-Id'), 're-initialize starts sessionless');
        self::assertSame('notifications/initialized', $this->body(5)['method']);
        self::assertSame('session-two', $this->request(6)->getHeaderLine('Mcp-Session-Id'));
        self::assertSame('tools/call', $this->body(6)['method']);
    }

    public function testASecondExpiryIsSurfacedNotLooped(): void
    {
        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(1),
            new Response(404, [], 'Session not found'),
            self::initReply([], 'session-two'),
            new Response(202),
            new Response(404, [], 'Session not found'),
        ]);
        $server->start();

        $result = $server->callTool('echo', []);

        self::assertSame(['error' => 'HTTP 404 Not Found: Session not found'], $result);
        self::assertCount(7, $this->history);
    }

    public function testA404WithoutASessionIsAPlainFailure(): void
    {
        $server = $this->server([
            self::initReply([], null),
            new Response(202),
            self::toolsReply(1),
            new Response(404, [], 'nope'),
        ]);
        $server->start();

        self::assertSame(['error' => 'HTTP 404 Not Found: nope'], $server->callTool('echo', []));
        self::assertCount(4, $this->history, 'no session was held, so there is nothing to re-initialize');
    }

    public function testStopDeletesTheSessionAndForgetsIt(): void
    {
        $server = $this->server([self::initReply(), new Response(202), self::toolsReply(1), new Response(200)]);
        $server->start();
        self::assertTrue($server->isUp());

        $server->stop();
        $server->stop();

        self::assertCount(4, $this->history, 'the second stop() has no session to end');
        $delete = $this->request(3);
        self::assertSame('DELETE', $delete->getMethod());
        self::assertSame(self::URL, (string) $delete->getUri());
        self::assertSame(self::SESSION, $delete->getHeaderLine('Mcp-Session-Id'));
        self::assertFalse($server->isUp());
    }

    public function testStopToleratesAServerThatRefusesTheDelete(): void
    {
        $server = $this->server([self::initReply(), new Response(202), self::toolsReply(1), new Response(405)]);
        $server->start();

        $server->stop();

        self::assertSame('DELETE', $this->request(3)->getMethod());
        self::assertFalse($server->isUp());
    }

    public function testStopWithoutASessionSendsNothing(): void
    {
        $server = $this->server([self::initReply([], null), new Response(202), self::toolsReply(1)]);
        $server->start();

        $server->stop();

        self::assertCount(3, $this->history);
        self::assertFalse($server->isUp());
    }

    /**
     * Audit AG-1: a forked turn or sub-agent inherits this object — the id
     * counter AND the Guzzle client whose curl handle holds the parent's
     * keep-alive socket. Measured: three forked children posting at once over
     * that one socket each read a sibling's reply. A non-owner pid must mint
     * pid-tagged ids and ask for a fresh, unshared connection.
     */
    public function testAForkedProcessSendsPidTaggedIdsOnAFreshConnection(): void
    {
        $pid = 100;
        // Answers over SSE with a stale reply for another id FIRST, so the
        // string-id matcher has to pick ours out of the stream.
        $answer = static function (RequestInterface $request): Response {
            $id = json_decode((string) $request->getBody(), true)['id'];

            return self::sse(
                'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => '2', 'result' => ['content' => [['type' => 'text', 'text' => 'stale']]]]) . "\n\n"
                . 'data: ' . json_encode(['jsonrpc' => '2.0', 'id' => $id, 'result' => ['content' => [['type' => 'text', 'text' => "for {$id}"]]]]) . "\n\n"
            );
        };
        $server = $this->server(
            [self::initReply(), new Response(202), self::toolsReply(1), $answer, $answer],
            pidProvider: static function () use (&$pid): int {
                return $pid;
            },
        );
        $server->start();

        $pid = 4242; // now running in a forked child
        $first = $server->callTool('echo', ['n' => 'a']);
        $second = $server->callTool('echo', ['n' => 'b']);

        $firstId = $this->body(3)['id'];
        $secondId = $this->body(4)['id'];
        self::assertIsString($firstId);
        self::assertMatchesRegularExpression('/^4242-[0-9a-f]{12}-0$/', $firstId);
        self::assertMatchesRegularExpression('/^4242-[0-9a-f]{12}-1$/', (string) $secondId);
        self::assertSame("for {$firstId}", $first['content'][0]['text'] ?? null);
        self::assertSame("for {$secondId}", $second['content'][0]['text'] ?? null);

        foreach ([3, 4] as $index) {
            $curl = $this->history[$index]['options']['curl'] ?? [];
            self::assertTrue($curl[CURLOPT_FRESH_CONNECT] ?? false, "request #{$index} must not reuse the inherited socket");
            self::assertTrue($curl[CURLOPT_FORBID_REUSE] ?? false, "request #{$index} must not leave its socket for reuse");
        }
        foreach ([0, 1, 2] as $index) {
            self::assertArrayNotHasKey('curl', $this->history[$index]['options'], 'the owner keeps its keep-alive connection');
        }
        self::assertSame(self::SESSION, $this->request(4)->getHeaderLine('Mcp-Session-Id'), 'the session is shared, not re-opened');
    }

    public function testTheOwnerKeepsIntegerIds(): void
    {
        $server = $this->server(
            [self::initReply(), new Response(202), self::toolsReply(1)],
            pidProvider: static fn (): int => 100,
        );
        $server->start();

        self::assertSame(0, $this->body(0)['id']);
        self::assertSame(1, $this->body(2)['id']);
    }

    public function testAForkedProcessStopDoesNotEndTheSharedSession(): void
    {
        $pid = 100;
        $server = $this->server(
            [self::initReply(), new Response(202), self::toolsReply(1), new Response(200)],
            pidProvider: static function () use (&$pid): int {
                return $pid;
            },
        );
        $server->start();

        $pid = 4242;
        $server->stop();

        self::assertCount(3, $this->history, 'a forked process must not DELETE the session its parent still uses');
    }
}
