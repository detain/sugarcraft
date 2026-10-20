<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use SugarCraft\Code\MCP\AuthEntry;
use SugarCraft\Code\MCP\HttpMcpAuthRequiredException;
use SugarCraft\Code\MCP\McpAuthStore;
use SugarCraft\Code\MCP\OAuthClientRegistration;
use SugarCraft\Code\MCP\SseMcpServer;
use SugarCraft\Code\MCP\SseStreamDeadlineException;

/**
 * MCP-4: the legacy HTTP+SSE transport over socket-pair fixtures.
 *
 * The connector seam swaps ONLY the socket connect: the HTTP request bytes,
 * the response head, the chunked decode, the endpoint handshake, THE ORIGIN
 * LAW and every wait deadline run for real over a `stream_socket_pair`. The
 * POST side is a closure handler that answers 202-accepted AND, exactly like
 * a legacy server, writes each JSON-RPC reply back onto the CURRENT session's
 * event stream with the id echoed from the request body — so reconnect and
 * fork-tagged ids work without the test guessing them. No real network, no
 * child process, no long waits: the only deliberate clock consumption is the
 * 0.2 s call-ceiling test, and the reconnect backoff rides the sleeper seam.
 *
 * Pinned, in the order the class docblock promises:
 * - the GET advertises `text/event-stream` EXACTLY once, never `application/json`,
 *   carries configured headers and bearer, drops transport-owned names;
 * - the origin law: a cross-origin `endpoint` event is refused and NO POST is
 *   ever sent (the mutation keystone); absolute, path-absolute and relative
 *   references on the SAME origin all resolve; query-only refuses;
 * - head gates: redirects refuse, 401 raises the typed auth exception, the
 *   content-type must be the stream type;
 * - replies ride the stream matched by id; `toolTimeout` bounds only the
 *   reply wait and delivers `notifications/cancelled`;
 * - a dropped stream fails the interrupted call WITHOUT resending, then a
 *   bounded burst (schedule pinned: 0.5, 1, 2) recovers it or marks the
 *   session lost until a later call's fresh burst.
 */
final class SseMcpServerTest extends TestCase
{
    private const CONFIGURED_URL = 'http://locked.example:8777/sse';

    /** @var list<resource> every fixture-side socket, in session order */
    private array $sessions = [];

    /** @var list<array{0:string,1:string,2:int,3:float}> */
    private array $connectArgs = [];

    /** @var list<float> recorded sleeper delays (reconnect backoff) */
    private array $sleeps = [];

    /** @var list<array{0:RequestInterface,1:array<string,mixed>}> */
    private array $postLog = [];

    /** @var list<(\Closure(RequestInterface): void)|null> one per queued POST */
    private array $postHooks = [];

    /**
     * MCP-4 (audit re-run F-1): when set, the NEXT logged POST is answered
     * with this response instead of the 202 delivery — the evil-redirect
     * fixture. It is consumed on first use.
     */
    private ?Response $postRefuse = null;

    /** Session spec consumed at (re)connect time. */
    private ?string $endpointData = '/messages';

    private string $headStatusLine = 'HTTP/1.1 200 OK';

    /** @var array<string,string> */
    private array $headHeaders = ['Content-Type' => 'text/event-stream', 'Transfer-Encoding' => 'chunked'];

    private ?\Closure $rawSpec = null;

    private bool $terminateStream = false;

    private bool $connectFails = false;

    private int $fakePid = 0;

    protected function tearDown(): void
    {
        foreach ($this->sessions as $socket) {
            if (\is_resource($socket)) {
                fclose($socket);
            }
        }
        foreach ($this->authDirs as $dir) {
            foreach (glob($dir . '/' . \chr(42)) ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        $this->sessions = [];
        $this->connectArgs = [];
        $this->sleeps = [];
        $this->postLog = [];
        $this->postHooks = [];
        $this->postRefuse = null;
        $this->authDirs = [];
        $this->endpointData = '/messages';
        $this->headStatusLine = 'HTTP/1.1 200 OK';
        $this->headHeaders = ['Content-Type' => 'text/event-stream', 'Transfer-Encoding' => 'chunked'];
        $this->rawSpec = null;
        $this->terminateStream = false;
        $this->connectFails = false;
        $this->fakePid = 0;
        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Handshake + the origin law
    // ------------------------------------------------------------------

    public function testTheHandshakeLocksAPathAbsoluteEndpointAndStartsTheSession(): void
    {
        $sse = $this->server();
        $sse->start();

        self::assertTrue($sse->isUp());
        // endpoint() stays the CONFIGURED URL: `mcp auth login` keys the store
        // by what the operator wrote, never by the server-published target.
        self::assertSame(self::CONFIGURED_URL, $sse->endpoint());
        self::assertSame([['http', 'locked.example', 8777, 10.0]], $this->connectArgs);

        self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $this->postedMethods());
        foreach ($this->postLog as [$request]) {
            self::assertSame('POST', $request->getMethod());
            self::assertSame('http://locked.example:8777/messages', (string) $request->getUri());
        }

        $initialize = $this->postBody('initialize');
        self::assertSame(SseMcpServer::PROTOCOL_VERSION, $initialize['params']['protocolVersion']);
        self::assertSame(['name' => 'candy-code', 'version' => '1.0.0'], $initialize['params']['clientInfo']);
    }

    public function testTheGetAdvertisesTheStreamOnceAndDropsTransportOwnedConfigHeaders(): void
    {
        $sse = $this->server(headers: [
            'X-Api-Key' => 'k-7',
            'Accept' => 'application/json',
            'MCP-Session-Id' => 'stale-2025-header',
        ]);
        $sse->start();

        $get = $this->captureGet(0);
        self::assertStringStartsWith("GET /sse HTTP/1.1\r\n", $get);
        self::assertStringContainsString("Host: locked.example:8777\r\n", $get);
        self::assertStringContainsString("Accept: text/event-stream\r\n", $get);
        self::assertStringContainsString("X-Api-Key: k-7\r\n", $get);
        self::assertStringNotContainsString('application/json', $get, 'the stream must not be advertised as accepting JSON');
        self::assertStringNotContainsString('stale-2025-header', $get);
        self::assertSame(1, substr_count($get, "\r\nAccept:"), 'exactly one Accept header on the GET');
    }

    public function testAnAbsoluteSameOriginEndpointIsAcceptedAndAnExplicitDefaultPortComposes(): void
    {
        $absolute = 'http://locked.example:8777/messages' . '?' . 'id=9';
        $this->endpointData = $absolute;
        $sse = $this->server();
        $sse->start();
        self::assertSame($absolute, (string) $this->postLog[0][0]->getUri());

        // A second session on a port-less URL composes the target with the
        // EXPLICIT default port (origin equality compares normalized ports).
        // The pin reads the transport's own composed string: Guzzle's PSR-7
        // URI drops a default port from the recorded request, which is the
        // wire's business, not the origin law's.
        $this->resetSession();
        $this->endpointData = 'http://locked.example/messages';
        $second = $this->server(url: 'http://locked.example/sse');
        $second->start();

        $target = new \ReflectionProperty(SseMcpServer::class, 'postTarget');
        self::assertSame('http://locked.example:80/messages', $target->getValue($second));
        self::assertSame('http://locked.example/messages', (string) $this->postLog[0][0]->getUri(), 'the socket request itself is normalized by PSR-7');
    }

    public function testARelativeEndpointResolvesAgainstTheStreamDirectory(): void
    {
        $this->endpointData = 'messages';
        $sse = $this->server(url: 'http://locked.example:8777/a/b/sse');
        $sse->start();

        self::assertSame('http://locked.example:8777/a/b/messages', (string) $this->postLog[0][0]->getUri());
    }

    public function testTheFirstEndpointEventWinsAndForeignNamedEventsAreSkipped(): void
    {
        $this->rawSpec = function ($server): void {
            $this->feedChunked($server, "event: ping\ndata: noise\n\n");
            $this->feedChunked($server, "event: endpoint\ndata: /first\n\n");
            $this->feedChunked($server, "event: endpoint\ndata: http://elsewhere.test/second\n\n");
        };
        $this->endpointData = null;

        $sse = $this->server();
        $sse->start();

        self::assertSame('http://locked.example:8777/first', (string) $this->postLog[0][0]->getUri());
    }

    public function testACrossOriginEndpointRefusesAndNoPostIsEverSent(): void
    {
        $this->endpointData = 'http://evil.example:9000/post';
        $sse = $this->server();

        $caught = null;
        try {
            $sse->start();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertNotNull($caught, 'a cross-origin endpoint must not start a session');
        $message = $caught->getMessage();
        self::assertStringContainsString('Failed to start MCP server sse-demo', $message, 'start() wraps, so the refusal rides its prefix');
        self::assertStringContainsString('DIFFERENT ORIGIN', $message);
        self::assertStringContainsString('http://evil.example:9000/post', $message, 'the refusal names the published target it refused');
        self::assertStringContainsString(self::CONFIGURED_URL, $message, 'and the configured URL it was checked against');
        self::assertSame([], $this->postLog, 'THE ORIGIN LAW: a refused endpoint is never contacted — bearer and all');
        self::assertFalse($sse->isUp());
    }

    public function testAPortChangeIsADifferentOrigin(): void
    {
        $this->endpointData = 'http://locked.example:9999/messages';
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the origin gate to refuse a port change');
        self::assertStringContainsString('DIFFERENT ORIGIN', $failure);
        self::assertSame([], $this->postLog);
    }

    public function testAQueryOnlyEndpointRefusesToGuess(): void
    {
        $this->endpointData = '?' . 'only=query';
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the no-path refusal');
        self::assertStringContainsString('a reference without a path; refusing to guess where requests POST', $failure);
        self::assertSame([], $this->postLog);
    }

    public function testAnEmptyEndpointEventRefuses(): void
    {
        $this->endpointData = '';
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the empty-URI refusal');
        self::assertStringContainsString('the endpoint event carried no URI', $failure);
    }

    // ------------------------------------------------------------------
    // Head gates
    // ------------------------------------------------------------------

    public function testARedirectedGetRefusesInsteadOfFollowing(): void
    {
        $this->headStatusLine = 'HTTP/1.1 302 Found';
        $this->headHeaders = ['Content-Type' => 'text/event-stream', 'Location' => 'http://elsewhere.test/sse'];
        $this->endpointData = null;
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the redirect refusal');
        self::assertStringContainsString('does not follow redirects', $failure);
    }

    /**
     * MCP-4 (audit re-run F-1): the GET head gate has long refused to follow
     * redirects; this is the POST side of the same law. A 3xx naming an
     * off-origin Location is refused BY NAME, and no second request — bearer
     * or body — is ever sent. The fixture client rides a real HandlerStack,
     * so Guzzle's redirect middleware is in play exactly as in production:
     * delete `allow_redirects` from sendPost() and this pin records the evil
     * hop with the bearer on it.
     *
     * @return array<string, array{0: int}>
     */
    public static function redirectStatuses(): array
    {
        return ['301' => [301], '302' => [302], '307' => [307], '308' => [308]];
    }

    /**
     * @param int $status
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('redirectStatuses')]
    public function testARedirectedPostIsRefusedAndTheBearerNeverHops(int $status): void
    {
        $this->postRefuse = new Response($status, ['Location' => 'http://evil.example/stolen']);
        $sse = $this->server(headers: ['Authorization' => 'Bearer mcp-4-sse-canary']);

        $failure = null;
        try {
            $sse->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, "HTTP {$status} redirect was accepted");
        self::assertStringContainsString('does not follow redirects', $failure);
        self::assertStringContainsString("HTTP {$status}", $failure);
        self::assertCount(1, $this->postLog, 'the redirect produced a second, off-origin request');
        self::assertSame('locked.example', $this->postLog[0][0]->getUri()->getHost());
        self::assertSame(
            'Bearer mcp-4-sse-canary',
            $this->postLog[0][0]->getHeaderLine('Authorization'),
            'the pin must ride a request that actually carried the bearer',
        );
    }

    public function testAUnauthorizedGetRaisesTheTypedAuthExceptionBeforeAnyPost(): void
    {
        $this->headStatusLine = 'HTTP/1.1 401 Unauthorized';
        $this->endpointData = null;

        $this->expectException(HttpMcpAuthRequiredException::class);
        try {
            $this->server()->start();
        } finally {
            self::assertSame([], $this->postLog, 'the typed refusal happens before any POST');
        }
    }

    public function testAContentTypeThatIsNotTheStreamRefuses(): void
    {
        $this->headHeaders = ['Content-Type' => 'application/json', 'Transfer-Encoding' => 'chunked'];
        $this->endpointData = null;
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the content-type gate');
        self::assertStringContainsString('not text/event-stream', $failure);
    }

    public function testAStreamThatEndsWithoutAnEndpointEventRefuses(): void
    {
        $this->endpointData = null;
        $this->terminateStream = true;
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the closed-before-endpoint refusal');
        self::assertStringContainsString('the event stream closed before its endpoint event', $failure);
    }

    // ------------------------------------------------------------------
    // Chunked-decode robustness
    // ------------------------------------------------------------------

    public function testChunkExtensionsAndMidLineChunkSplitsStillFrame(): void
    {
        $this->endpointData = null;
        $this->rawSpec = function ($server): void {
            // One frame cut into three wire chunks, one size line wearing an extension.
            $this->feedChunked($server, 'event: endpo');
            $this->feedChunked($server, "int\ndata: /m", withExtension: true);
            $this->feedChunked($server, "essages\n\n");
        };

        $sse = $this->server();
        $sse->start();

        self::assertSame('http://locked.example:8777/messages', (string) $this->postLog[0][0]->getUri());
    }

    public function testAnUnchunkedStreamFramesToo(): void
    {
        $this->headHeaders = ['Content-Type' => 'text/event-stream'];
        $this->endpointData = null;
        $this->rawSpec = function ($server): void {
            fwrite($server, "event: endpoint\ndata: /messages\n\n");
        };

        $sse = $this->server();
        $sse->start();

        self::assertSame(['initialize', 'notifications/initialized', 'tools/list'], $this->postedMethods());
    }

    public function testMalformedChunkFramingRefusesLoudly(): void
    {
        $this->rawSpec = function ($server): void {
            // A size line that is not hex (written RAW — framing it would make
            // 'zz' the payload, not the size). The wait for `initialize`'s
            // reply hits it, and a malformed stream is declared malformed,
            // never reported as a clean close.
            fwrite($server, "zz\r\nleftover");
        };
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the malformed-chunk refusal');
        self::assertStringContainsString('malformed chunk size line', $failure);
    }

    public function testAStreamThatDiesMidChunkNamesTheLoss(): void
    {
        $this->endpointData = null;
        $this->rawSpec = function ($server): void {
            // Claims 26 bytes, delivers four, then the write side goes away —
            // SHUT_WR keeps the pair readable for the GET still to be written.
            fwrite($server, "1a\r\nabcd");
            stream_socket_shutdown($server, STREAM_SHUT_WR);
        };
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the mid-chunk refusal');
        self::assertStringContainsString('died mid-chunk (expected 22 more bytes)', $failure);
    }

    public function testAFramingLinePastTheBoundRefusesWithoutBufferingForever(): void
    {
        $this->endpointData = null;
        $this->rawSpec = function ($server): void {
            fwrite($server, str_repeat('a', 8192));
        };
        $server = $this->server();
        $failure = null;
        try {
            $server->start();
        } catch (\RuntimeException $e) {
            $failure = $e->getMessage();
        }
        self::assertNotNull($failure, 'expected the line-bound refusal');
        self::assertStringContainsString('framing line past 8192', $failure);
    }

    // ------------------------------------------------------------------
    // Tool roster + call shapes
    // ------------------------------------------------------------------

    public function testToolsListPaginationWalksTheCursorChain(): void
    {
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([['name' => 'one', 'description' => 'd', 'inputSchema' => []]], key: 'tools', nextCursor: 'c1'),
            $this->replyStream([['name' => 'two', 'description' => 'd', 'inputSchema' => []]], key: 'tools'),
        ];
        $sse = $this->server();
        $sse->start();

        self::assertSame(['one', 'two'], array_map(static fn ($t): string => $t->name, $sse->listTools()));
        self::assertSame('c1', $this->postBody('tools/list', 1)['params']['cursor']);
    }

    public function testACallCarriesTheArgumentsAndReadsItsReplyOffTheStream(): void
    {
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([['name' => 'echo', 'description' => 'd', 'inputSchema' => ['type' => 'object']]], key: 'tools'),
            $this->replyStream([['type' => 'text', 'text' => 'hi']], key: 'content'),
        ];
        $sse = $this->server();
        $sse->start();

        $result = $sse->callTool('echo', ['a' => 1]);

        self::assertSame(['content' => [['type' => 'text', 'text' => 'hi']]], $result);
        $call = $this->postBody('tools/call');
        self::assertSame('echo', $call['params']['name']);
        self::assertSame(['a' => 1], $call['params']['arguments']);
        $callRequest = $this->postLog[3][0];
        self::assertSame('application/json', $callRequest->getHeaderLine('Accept'), 'POST legs carry JSON Accept; the GET-only test proved the stream Accept');
        self::assertSame('application/json', $callRequest->getHeaderLine('Content-Type'));
    }

    public function testAnEmptyArgumentMapStillSendsAnObjectOnTheWire(): void
    {
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([], key: 'tools'),
            $this->replyStream([], key: 'content'),
        ];
        $sse = $this->server();
        $sse->start();
        $sse->callTool('anything', []);

        $raw = (string) $this->postLog[3][0]->getBody();
        self::assertStringContainsString('"arguments":{}', $raw, 'no-argument tools/call must serialize an OBJECT, not []');
    }

    public function testAJsonRpcErrorReplyKeepsTheServersOwnText(): void
    {
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([], key: 'tools'),
            $this->replyError(-32601, 'Bad thing'),
        ];
        $sse = $this->server();
        $sse->start();

        self::assertSame(['error' => 'JSON-RPC error (-32601): Bad thing'], $sse->callTool('x', []));
    }

    // ------------------------------------------------------------------
    // The per-call ceiling (E646: bounds ONE reply wait, nothing else)
    // ------------------------------------------------------------------

    public function testAQuietStreamAbandonsAtTheCallCeilingAndSendsTheCancellation(): void
    {
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([], key: 'tools'),
            null, // tools/call accepted; the answer never comes
        ];
        $sse = $this->server(toolTimeoutSeconds: 0.2);
        $sse->start();

        $startedAt = microtime(true);
        $result = $sse->callTool('slow', []);
        $waited = microtime(true) - $startedAt;

        self::assertGreaterThanOrEqual(0.19, $waited, 'the bound must actually bound');
        self::assertLessThan(5.0, $waited, 'and never overrun it');
        self::assertStringContainsString('MCP call to tools/call was abandoned', (string) $result['error']);
        self::assertStringContainsString('no reply within 0.2 seconds on the event stream', (string) $result['error']);

        $cancel = $this->postBody('notifications/cancelled');
        self::assertSame(2, $cancel['params']['requestId']);
        self::assertSame('the client abandoned the request at its configured timeout', $cancel['params']['reason']);
        self::assertTrue($sse->isUp(), 'a quiet tool is not a dead stream — the session stands');
    }

    // ------------------------------------------------------------------
    // Drop, burst, refusal
    // ------------------------------------------------------------------

    public function testADroppedStreamFailsTheInterruptedCallWithoutResending(): void
    {
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([], key: 'tools'),
            $this->closeStreamOn(),            // the stream dies instead of answering tools/call
            null,                              // notifications/cancelled, best-effort
            $this->replyStream(['capabilities' => []]),  // burst attempt 1 re-handshakes
            null,
        ];
        $sse = $this->server();
        $sse->start();
        $result = $sse->callTool('x', []);

        $error = (string) $result['error'];
        self::assertStringContainsString('the event stream closed before the reply', $error);
        self::assertStringContainsString('not resent', $error, 'the server may already be running the work — double side effects are the law');
        self::assertSame([0.5], $this->sleeps, 'the first attempt recovered, so one backoff slept');
        self::assertCount(2, $this->sessions, 'recovery opened exactly one new stream');
        self::assertTrue($sse->isUp(), 'a recovered burst restores the session without a fresh start');

        // The recovered session really works: the next call rides stream #2.
        $this->postHooks = [$this->replyStream([['type' => 'text', 'text' => 'again']], key: 'content')];
        self::assertSame(['content' => [['type' => 'text', 'text' => 'again']]], $sse->callTool('x', []));
        self::assertSame('http://locked.example:8777/messages', (string) $this->postLog[7][0]->getUri());
    }

    public function testAnExhaustedBurstMarksTheSessionLostUntilAFreshBurst(): void
    {
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([], key: 'tools'),
            $this->closeStreamOn(),
            null, // cancelled notice
            // from here every connect FAILS: the queue of hooks stays empty
        ];
        $sse = $this->server();
        $sse->start();

        // Arm the failing connector AFTER the healthy start.
        $this->connectFails = true;

        $result = $sse->callTool('x', []);

        self::assertStringContainsString('the event stream closed before the reply', (string) $result['error']);
        self::assertSame([0.5, 1.0, 2.0], $this->sleeps, 'three attempts, doubling, under the cap');
        self::assertFalse($sse->isUp(), 'an exhausted burst marks the session lost');

        $again = $sse->callTool('x', []);
        self::assertStringContainsString('all 3 reconnect attempts failed', (string) $again['error']);
        self::assertSame([0.5, 1.0, 2.0, 0.5, 1.0, 2.0], $this->sleeps, 'a later call tries one fresh burst');
        self::assertCount(5, $this->postLog, 'refused calls never POST — the burst precedes the request');
    }

    public function testAForkedChildOpensItsOwnSessionBeforeItsFirstPost(): void
    {
        $this->fakePid = 100;
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([], key: 'tools'),
        ];
        $sse = $this->server();
        $sse->start();

        // This process is no longer the one that opened the stream.
        $this->fakePid = 200;
        $this->postHooks = [
            $this->replyStream(['capabilities' => []]),
            null,
            $this->replyStream([['type' => 'text', 'text' => 'child']], key: 'content'),
        ];

        $result = $sse->callTool('x', []);

        self::assertSame(['content' => [['type' => 'text', 'text' => 'child']]], $result);
        self::assertCount(2, $this->sessions, 'the child opened a SECOND stream and never read the parent one');
        $childInit = $this->postBody('initialize', 1);
        self::assertIsString($childInit['id'], 'forked children mint pid-tagged string ids');
        self::assertStringStartsWith('200-', (string) $childInit['id']);
    }

    // ------------------------------------------------------------------
    // E695 precedence on this transport
    // ------------------------------------------------------------------

    public function testAStoredBearerAttachesToTheGetAndStaticAuthorizationWins(): void
    {
        $store = $this->sseStore();
        $store->oauth()->saveAuth(self::CONFIGURED_URL, new AuthEntry(
            clientId: 'c1',
            clientSecret: '',
            registrationAccessToken: 'r1',
            accessToken: 'stored-access-7',
            refreshToken: '',
            expiresAt: time() + 3600,
            tokenUrl: 'http://locked.example:8777/token',
        ));
        $sse = $this->server(store: $store);
        $sse->start();

        self::assertStringContainsString("Authorization: Bearer stored-access-7\r\n", $this->captureGet(0));

        // Static header short-circuits the store: an EXPIRED unrefreshable
        // entry must not even be consulted — the store's own double has an
        // EMPTY handler queue, so any consult would surface as a mock error.
        $this->resetSession();
        $locked = $this->sseStore();
        $locked->oauth()->saveAuth(self::CONFIGURED_URL, new AuthEntry(
            clientId: 'c2',
            clientSecret: '',
            registrationAccessToken: 'r2',
            accessToken: 'ambient-must-not-appear',
            refreshToken: 'rt-must-not-refresh',
            expiresAt: time() - 10,
            tokenUrl: 'http://locked.example:8777/token',
        ));
        $static = $this->server(headers: ['authorization' => 'Static operator-header'], store: $locked);
        $static->start();

        $getBytes = $this->captureGet(0);
        self::assertStringContainsString("authorization: Static operator-header\r\n", $getBytes);
        self::assertStringNotContainsString('ambient-must-not-appear', $getBytes);
    }

    // ------------------------------------------------------------------
    // Deadline plumbing (internal contract, pinned directly)
    // ------------------------------------------------------------------

    public function testTheWaitDeadlineIsWhatBoundsEveryStreamRead(): void
    {
        // A silent stream must raise SseStreamDeadlineException at the wait
        // bound — not hang, and not masquerade as a clean close. The public
        // surface (the call-ceiling test) already proves the wiring; this
        // pins the marker itself through the reader.
        $sse = $this->server();
        $sse->start();

        $begin = new \ReflectionMethod(SseMcpServer::class, 'beginWait');
        $read = new \ReflectionMethod(SseMcpServer::class, 'readStreamChunk');
        $end = new \ReflectionMethod(SseMcpServer::class, 'endWait');
        $begin->invoke($sse, 0.05, 'a unit probe');

        $caught = null;
        try {
            $read->invoke($sse);
        } catch (SseStreamDeadlineException $e) {
            $caught = $e;
        }
        $end->invoke($sse);

        self::assertNotNull($caught, 'the reader must raise the deadline marker, never return a fake EOF');
        self::assertStringContainsString('a unit probe', $caught->getMessage());
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * Reset recorded POST state between two sessions INSIDE one test.
     */
    private function resetSession(): void
    {
        foreach ($this->sessions as $socket) {
            if (\is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->postLog = [];
        $this->sessions = [];
        $this->connectArgs = [];
        $this->endpointData = '/messages';
        $this->headStatusLine = 'HTTP/1.1 200 OK';
        $this->headHeaders = ['Content-Type' => 'text/event-stream', 'Transfer-Encoding' => 'chunked'];
        $this->rawSpec = null;
        $this->terminateStream = false;
        $this->postHooks = [];
        $this->postRefuse = null;
    }

    /**
     * @param array<string,string> $headers
     */
    private function server(
        array $headers = [],
        ?McpAuthStore $store = null,
        float $toolTimeoutSeconds = 5.0,
        string $url = self::CONFIGURED_URL,
    ): SseMcpServer {
        if ($this->postHooks === []) {
            // The three POSTs every clean start makes, answered the way a
            // legacy server answers them. Tests needing custom POST choreography
            // set $this->postHooks BEFORE calling this method.
            $this->postHooks = [
                $this->replyStream(['capabilities' => []]),
                null,
                $this->replyStream([], key: 'tools'),
            ];
        }
        $pidProvider = null;
        if ($this->fakePid !== 0) {
            $pidProvider = fn (): int => $this->fakePid;
        }

        return new SseMcpServer(
            name: 'sse-demo',
            url: $url,
            headers: $headers,
            // MCP-4 (audit re-run F-1): the fixture handler rides a REAL
            // HandlerStack, so Guzzle's redirect middleware participates
            // exactly as it does in production — a raw-closure handler would
            // bypass every default middleware and could never catch a
            // bearer-carrying follow.
            httpClient: new Client(['handler' => HandlerStack::create($this->actorHandler())]),
            authStore: $store,
            pidProvider: $pidProvider,
            toolTimeoutSeconds: $toolTimeoutSeconds,
            connector: function (string $scheme, string $host, int $port, float $timeoutSeconds) {
                $this->connectArgs[] = [$scheme, $host, $port, $timeoutSeconds];
                if ($this->connectFails) {
                    throw new \RuntimeException('connection refused (fixture)');
                }

                return $this->openSession($scheme, $host, $port);
            },
            sleeper: function (float $seconds): void {
                $this->sleeps[] = $seconds;
            },
        );
    }

    /**
     * Create one fixture session: unix pair, head + endpoint frames written
     * eagerly (so the client always finds them buffered), client end handed
     * to the transport.
     *
     * @return resource the CLIENT end; $this->sessions holds the fixture side
     */
    private function openSession(string $scheme, string $host, int $port): mixed
    {
        unset($scheme, $host, $port);
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_SOCK_STREAM);
        self::assertIsArray($pair);
        [$client, $server] = $pair;
        stream_set_read_buffer($client, 0);
        $this->sessions[] = $server;

        $head = $this->headStatusLine . "\r\n";
        foreach ($this->headHeaders as $name => $value) {
            $head .= "{$name}: {$value}\r\n";
        }
        fwrite($server, $head . "\r\n");

        if ($this->rawSpec !== null) {
            ($this->rawSpec)($server);
        } elseif ($this->endpointData !== null) {
            $this->feedChunked($server, "event: endpoint\ndata: {$this->endpointData}\n\n");
        }
        if ($this->terminateStream) {
            fwrite($server, "0\r\n\r\n");
        }

        return $client;
    }

    /**
     * The legacy-server actor: every POST is logged, answered 202, and the
     * queued hook for that POST (if any) writes the reply onto the CURRENT
     * session's stream — exactly how real servers pair delivery with answer.
     */
    private function actorHandler(): \Closure
    {
        return function (RequestInterface $request, array $options) {
            $this->postLog[] = [$request, $options];
            if ($this->postRefuse !== null) {
                // MCP-4 (audit re-run F-1): logged FIRST, so a followed
                // redirect would log a SECOND entry — the hop itself.
                $refuse = $this->postRefuse;
                $this->postRefuse = null;

                return new FulfilledPromise($refuse);
            }
            if ($this->postHooks === []) {
                // \Error, deliberately: it must NOT be catchable by the
                // transport's catch(\Exception) wrappers — an unexpected POST
                // is a broken fixture, and the test should ERROR loudly.
                throw new \Error('unexpected POST: ' . (string) $request->getBody());
            }
            $hook = array_shift($this->postHooks);
            if ($hook !== null) {
                $hook($request);
            }

            return new FulfilledPromise(new Response(202));
        };
    }

    /** @param resource $server */
    private function feedChunked($server, string $text, bool $withExtension = false): void
    {
        $size = dechex(strlen($text));
        fwrite($server, $size . ($withExtension ? ';ext=1' : '') . "\r\n" . $text . "\r\n");
    }

    /**
     * A POST hook that answers on the stream with `result => {key: value}` —
     * or a bare result map when $key is null.
     *
     * @return \Closure(RequestInterface): void
     */
    private function replyStream(array $value, ?string $key = null, ?string $nextCursor = null): \Closure
    {
        return function (RequestInterface $request) use ($value, $key, $nextCursor): void {
            $body = json_decode((string) $request->getBody(), true);
            $result = $key === null ? $value : [$key => $value];
            if ($nextCursor !== null) {
                $result['nextCursor'] = $nextCursor;
            }
            $session = $this->sessions[count($this->sessions) - 1];
            if (!\is_resource($session)) {
                return; // the stream died; nothing to write on
            }
            $this->feedChunked($session, 'data: ' . json_encode([
                'jsonrpc' => '2.0', 'id' => $body['id'] ?? 0, 'result' => $result,
            ]) . "\n\n");
        };
    }

    /** @return \Closure(RequestInterface): void */
    private function replyError(int $code, string $message): \Closure
    {
        return function (RequestInterface $request) use ($code, $message): void {
            $body = json_decode((string) $request->getBody(), true);
            $session = $this->sessions[count($this->sessions) - 1];
            if (!\is_resource($session)) {
                return;
            }
            $this->feedChunked($session, 'data: ' . json_encode([
                'jsonrpc' => '2.0', 'id' => $body['id'] ?? 0,
                'error' => ['code' => $code, 'message' => $message],
            ]) . "\n\n");
        };
    }

    /** @return \Closure(RequestInterface): void */
    private function closeStreamOn(): \Closure
    {
        return function (RequestInterface $request): void {
            unset($request);
            $session = $this->sessions[count($this->sessions) - 1];
            if (\is_resource($session)) {
                fclose($session);
            }
        };
    }

    /** @return list<string> */
    private function postedMethods(): array
    {
        return array_map(
            static fn(array $entry): string => (string) (json_decode((string) $entry[0]->getBody(), true)['method'] ?? ''),
            $this->postLog,
        );
    }

    /** @return array<string, mixed> */
    private function postBody(string $method, int $nth = 0): array
    {
        $found = [];
        foreach ($this->postLog as [$request]) {
            $decoded = json_decode((string) $request->getBody(), true);
            if (is_array($decoded) && ($decoded['method'] ?? null) === $method) {
                $found[] = $decoded;
            }
        }
        self::assertArrayHasKey($nth, $found, "no {$method} POST number {$nth} — saw: " . implode(',', $this->postedMethods()));

        return $found[$nth];
    }

    /**
     * The request bytes the transport wrote on session $index's socket pair.
     *
     * @return non-empty-string
     */
    private function captureGet(int $index): string
    {
        $server = $this->sessions[$index];
        $bytes = '';
        while (!str_contains($bytes, "\r\n\r\n")) {
            $read = [$server];
            $write = null;
            $except = null;
            if (@stream_select($read, $write, $except, 2) < 1) {
                break;
            }
            $chunk = fread($server, 4096);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $bytes .= $chunk;
        }
        self::assertNotSame('', $bytes, 'the GET request bytes never arrived');

        return $bytes;
    }

    private function sseStore(): McpAuthStore
    {
        $dir = sys_get_temp_dir() . '/mcp4_sse_' . uniqid((string) getmypid(), true);
        mkdir($dir, 0700, true);
        $this->authDirs[] = $dir;

        return new McpAuthStore(new OAuthClientRegistration(
            new Client(['handler' => HandlerStack::create(new MockHandler([]))]),
            $dir . '/auth.json',
        ));
    }

    /** @var list<string> */
    private array $authDirs = [];
}
