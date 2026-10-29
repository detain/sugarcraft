<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\MCP\HttpMcpServer;
use SugarCraft\Code\MCP\McpServerRequests;

/**
 * C-MCP-3.c: the Streamable HTTP arm's inbound legs.
 *
 * Two channels the old walk never served: server requests that RIDE THE POST
 * REPLY STREAM (`replyFromEventStream` passed them over) and the opt-in
 * streamable-HTTP GET channel (`"stream": true`) that lets a server push
 * between calls. The POST side runs on a MockHandler + history stack (real
 * Guzzle middleware, so the transport-owned headers, the session echo and
 * the fork rules all apply for real); the GET side swaps ONLY the socket
 * connect — the request bytes, the head gate and the chunked SSE decode run
 * for real over a `stream_socket_pair`, the harness shape the legacy sse
 * suite pioneered and {@see \SugarCraft\Code\MCP\SseLineParser} now shares
 * with it.
 */
final class HttpMcpServerGetStreamTest extends TestCase
{
    private const URL = 'http://desk.example:8793/mcp';
    private const SESSION = 'sess-get-1';

    /** @var list<array{0:RequestInterface,1:array<string,mixed>}> */
    private array $history = [];

    /** @var list<resource> fixture-side GET ends */
    private array $fixtures = [];

    /** @var bool did the GET connector ever get called? */
    private bool $getConnected = false;

    protected function tearDown(): void
    {
        foreach ($this->fixtures as $socket) {
            if (\is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->fixtures = [];
        $this->history = [];
    }

    /**
     * A ping pushed onto the GET channel between calls is ANSWERED — by a
     * POST of the JSON-RPC response carrying the `Mcp-Session-Id` — when the
     * next exchange starts. It also pins the GET itself: request line on the
     * configured path, the stream accept, the live session id, and none of
     * the POST-only transport headers.
     */
    public function testAPingOnTheGetChannelIsAnsweredByASessionBearingPost(): void
    {
        [$server, $fixture] = $this->streamServer([
            self::initReply(),
            new Response(202),
            self::toolsReply(),
            new Response(200, ['Content-Type' => 'application/json'], '{}'),
            self::callReply(),
        ]);

        // The ping rode the GET while nothing was in flight; the exchange
        // start drains it and POSTs the answer BEFORE the tools/call.
        $this->feedChunked($fixture, 'data: {"jsonrpc":"2.0","id":"g-ping-1","method":"ping"}' . "\n\n");

        $result = $server->callTool('echo', ['message' => 'hi']);

        self::assertSame([['type' => 'text', 'text' => 'Echo: hi']], $result['content']);

        $answer = $this->body(3);
        self::assertSame('g-ping-1', $answer['id'], 'the GET-channel ping must be answered by a POST');
        self::assertArrayHasKey('result', $answer, 'a ping is answered, not refused');
        self::assertArrayNotHasKey('method', $answer);
        self::assertSame(
            self::SESSION,
            $this->request(3)->getHeaderLine('Mcp-Session-Id'),
            'the answer must carry the session header',
        );
        self::assertSame('tools/call', $this->body(4)['method'], 'the drain must precede the call, not replace it');

        // The GET request bytes the fixture-side socket received.
        stream_set_blocking($fixture, false);
        $requestBytes = (string) fread($fixture, 65535);
        self::assertStringContainsString('GET /mcp HTTP/1.1', $requestBytes);
        self::assertStringContainsString('Accept: text/event-stream', $requestBytes);
        self::assertStringContainsString('Mcp-Session-Id: ' . self::SESSION, $requestBytes);
        self::assertStringNotContainsString('Content-Type:', $requestBytes, 'a GET carries no body to type');
    }

    /**
     * An `elicitation/create` riding the POST reply stream reaches the
     * PER-CALL answerer (the approver-bound desk of a live turn), and the
     * progress notification ahead of it reaches the call's wait sink — the
     * shapes the buffered walk used to pass over.
     */
    public function testElicitationInsideAPostReplyStreamReachesTheAnswerer(): void
    {
        $streamReply = new Response(200, ['Content-Type' => 'text/event-stream'], implode('', [
            'data: {"jsonrpc":"2.0","id":"elicit-1","method":"elicitation/create","params":{"message":"ship it?","requestedSchema":{"type":"object","properties":{"ack":{"type":"string","description":"confirm"}},"required":["ack"]}}}' . "\n\n",
            'data: {"jsonrpc":"2.0","method":"notifications/progress","params":{"progressToken":1,"progress":50,"total":100,"message":"halfway"}}' . "\n\n",
            'data: {"jsonrpc":"2.0","id":2,"result":{"content":[]}}' . "\n\n",
        ]));

        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(),
            $streamReply,
            new Response(202),
        ]);
        $server->start();

        $desk = new McpServerRequests(
            \sys_get_temp_dir(),
            static fn (array $questions): array => ['ack' => 'yes'],
        );
        $waited = [];
        $result = $server->callToolAnsweringRequests(
            'echo',
            ['message' => 'go'],
            static function (array $params) use (&$waited): void {
                $waited[] = $params;
            },
            $desk,
        );

        self::assertSame([], $result['content'], 'the call completes on its own reply');
        self::assertCount(1, $waited);
        self::assertSame(50, $waited[0]['progress']);

        $answer = $this->body(4);
        self::assertSame('elicit-1', $answer['id']);
        self::assertSame(
            ['action' => 'accept', 'content' => ['ack' => 'yes']],
            $answer['result'],
            'the per-call desk must answer inside the reply walk',
        );
        self::assertSame(
            self::SESSION,
            $this->request(4)->getHeaderLine('Mcp-Session-Id'),
        );
        self::assertFalse($this->getConnected, 'the GET channel is opt-in only; this server never asked for one');
    }

    /**
     * The default path stays exactly what it was: without a desk the frames
     * a reply stream carries ahead of the answer are passed over — NO answer
     * POST, no extra history entries — and the buffered call returns. The
     * GET connector is never touched without `stream`.
     */
    public function testWithoutADeskTheReplyStreamWalkIsUnchanged(): void
    {
        $streamReply = new Response(200, ['Content-Type' => 'text/event-stream'], implode('', [
            'data: {"jsonrpc":"2.0","id":"who-1","method":"ping"}' . "\n\n",
            'data: {"jsonrpc":"2.0","id":2,"result":{"content":[{"type":"text","text":"Echo: hi"}]}}' . "\n\n",
        ]));

        $server = $this->server([
            self::initReply(),
            new Response(202),
            self::toolsReply(),
            $streamReply,
        ]);
        $server->start();

        $result = $server->callTool('echo', ['message' => 'hi']);

        self::assertSame('Echo: hi', $result['content'][0]['text']);
        self::assertCount(4, $this->history, 'a desk-less walk invents no extra POSTs');
        self::assertFalse($this->getConnected);
    }

    /**
     * A server that refuses the GET (405 — the spec's own "this server does
     * not stream on GET") costs the CHANNEL, never the session: start()
     * stands, calls run buffered, and later pumps stay silent because no
     * stream exists.
     */
    public function testAGetRefusalCostsTheChannelNotTheSession(): void
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_SOCK_STREAM);
        self::assertIsArray($pair);
        [$client, $fixture] = $pair;
        stream_set_read_buffer($client, 0);
        $this->fixtures[] = $fixture;
        fwrite($fixture, "HTTP/1.1 405 Method Not Allowed\r\nContent-Type: application/json\r\n\r\n");
        $this->getConnected = true;

        $server = new HttpMcpServer(
            name: 'streamable',
            url: self::URL,
            headers: [],
            httpClient: new Client(['handler' => $this->mockStack([
                self::initReply(),
                new Response(202),
                self::toolsReply(),
                self::callReply(),
            ])]),
            eventStream: true,
            onServerMessage: (new McpServerRequests(\sys_get_temp_dir()))->handler(),
            connector: static fn (string $scheme, string $host, int $port, float $timeoutSeconds): mixed => $client,
        );

        $server->start();
        self::assertTrue($server->isUp(), 'a refused GET must not fail the start');

        $result = $server->callTool('echo', ['message' => 'hi']);
        self::assertSame('Echo: hi', $result['content'][0]['text']);
        self::assertCount(4, $this->history, 'the refusal left no answer-POST trail');
    }

    /**
     * The wiring the transport cannot show from the inside: the factory reads
     * `stream` into the http arm, and the client's idle pump reaches BOTH
     * GET-stream arms — without these legs the channel above would exist but
     * nothing would ever open or drain it in a running session.
     */
    public function testTheFactoryAndIdlePumpAreWiredToBothGetStreamArms(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/MCP/McpClient.php');

        self::assertStringContainsString(
            '($config[\'stream\'] ?? false) === true',
            $source,
            'the http arm stopped reading `stream` — the opt-in channel lost its door',
        );
        self::assertMatchesRegularExpression(
            '/elseif \(\$server instanceof SseMcpServer \|\| \$server instanceof HttpMcpServer\) \{\s*\$server->pumpStream\(\);/',
            $source,
            'pumpStderr() stopped draining both GET-stream arms — between-call traffic would wait forever',
        );
        // (the pattern above is the ARM as the source spells it — `($server`
        // opens tight and `HttpMcpServer)` closes tight; a reformat that
        // spaces the parens must move this pin with it.)
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * A stream-enabled server: `stream: true`, the spawn-time desk, and the
     * GET connect swapped for a socket pair whose fixture side has already
     * buffered the stream head (the reader finds it instantly, exactly like
     * the sse suite's eager-session shape).
     *
     * @param list<Response|\Throwable|callable> $responses
     * @return array{0:HttpMcpServer,1:resource} server + fixture-side GET
     */
    private function streamServer(array $responses): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_SOCK_STREAM);
        self::assertIsArray($pair);
        [$client, $fixture] = $pair;
        stream_set_read_buffer($client, 0);
        $this->fixtures[] = $fixture;
        fwrite($fixture, "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nTransfer-Encoding: chunked\r\n\r\n");
        $this->getConnected = true;

        $server = new HttpMcpServer(
            name: 'streamable',
            url: self::URL,
            headers: [],
            httpClient: new Client(['handler' => $this->mockStack($responses)]),
            eventStream: true,
            onServerMessage: function (string $method, ?array $params, bool $isNotification) {
                return $isNotification ? null : ['result' => new \stdClass()];
            },
            connector: static fn (string $scheme, string $host, int $port, float $timeoutSeconds): mixed => $client,
        );
        $server->start();

        return [$server, $fixture];
    }

    /**
     * The default-shaped arm: no `stream`, no desk — every existing
     * Streamable HTTP pin runs against exactly this construction.
     *
     * @param list<Response|\Throwable|callable> $responses
     */
    private function server(array $responses): HttpMcpServer
    {
        return new HttpMcpServer(
            name: 'streamable',
            url: self::URL,
            headers: [],
            httpClient: new Client(['handler' => $this->mockStack($responses)]),
        );
    }

    /** @param list<Response|\Throwable|callable> $responses */
    private function mockStack(array $responses): HandlerStack
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));

        return $stack;
    }

    /** @param resource $fixture */
    private function feedChunked($fixture, string $text): void
    {
        fwrite($fixture, dechex(strlen($text)) . "\r\n" . $text . "\r\n");
    }

    private static function initReply(): Response
    {
        return new Response(200, [
            'Content-Type' => 'application/json',
            'Mcp-Session-Id' => self::SESSION,
        ], (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 0,
            'result' => [
                'protocolVersion' => '2024-11-05',
                'capabilities' => ['tools' => new \stdClass()],
                'serverInfo' => ['name' => 'fixture', 'version' => '0.0.1'],
            ],
        ]));
    }

    private static function toolsReply(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['tools' => [['name' => 'echo']]],
        ]));
    }

    private static function callReply(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['content' => [['type' => 'text', 'text' => 'Echo: hi']]],
        ]));
    }

    /** @return array<string,mixed> */
    private function body(int $index): array
    {
        self::assertArrayHasKey($index, $this->history, "request #{$index} was never sent");
        $decoded = json_decode((string) $this->history[$index]['request']->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function request(int $index): RequestInterface
    {
        self::assertArrayHasKey($index, $this->history, "request #{$index} was never sent");

        return $this->history[$index]['request'];
    }
}
