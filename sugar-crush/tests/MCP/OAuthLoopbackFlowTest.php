<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\OAuthClientRegistration;
use SugarCraft\Crush\MCP\OAuthLoopbackFlow;

// OAuthClientRegistration carries AuthEntry in the same file (project law).
require_once __DIR__ . '/../../src/MCP/OAuthClientRegistration.php';

/**
 * E701: the loopback listener — bind discipline and every callback verdict.
 *
 * Hermetic by construction: the only socket touched is a 127.0.0.1 listener
 * this test itself binds, and the only client is an in-process fsockopen
 * whose request rides the TCP backlog until awaitCallback accepts it. No
 * thread, no port guessing, no network egress. The browser hook the design
 * asks for is exactly this shape — production passes null and a human
 * substitutes for the hook.
 *
 * @see OAuthLoopbackFlow::awaitCallback()
 */
final class OAuthLoopbackFlowTest extends TestCase
{
    /** @var list<resource> */
    private array $listeners = [];

    /** @var list<resource> */
    private array $clients = [];

    protected function tearDown(): void
    {
        foreach ([...$this->clients, ...$this->listeners] as $socket) {
            if (\is_resource($socket)) {
                fclose($socket);
            }
        }
        $this->clients = [];
        $this->listeners = [];
        parent::tearDown();
    }

    // =========================================================================
    // Bind discipline
    // =========================================================================

    public function testTheListenerIsBoundToTheLoopbackLiteralInSource(): void
    {
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/src/MCP/OAuthLoopbackFlow.php');

        self::assertSame(
            1,
            substr_count($source, "stream_socket_server('tcp://127.0.0.1:0'"),
            'the ONLY listener bind is the loopback literal on an ephemeral port',
        );
        self::assertStringNotContainsString("tcp://0.0.0.0", $source, 'RFC 8252 loopback redirect URIs forbid a wildcard bind');
        self::assertStringNotContainsString("tcp://[::]", $source, 'and likewise the IPv6 wildcard');
        self::assertStringContainsString('hash_equals(', $source, 'the state comparison must be timing-safe — loose equality is the exact footgun magic-hash-shaped values exist for');
    }

    public function testRedirectUriForBuildsTheBoundLoopbackAddress(): void
    {
        $server = $this->bindListener();

        $uri = OAuthLoopbackFlow::redirectUriFor($server);

        self::assertSame(1, preg_match('#^http://127\.0\.0\.1:\d+/callback$#', $uri), "redirect URI is loopback-shaped, got {$uri}");
    }

    public function testRedirectUriForRefusesAListenerBoundAnywhereElse(): void
    {
        // A wildcard bind reports 0.0.0.0:<port> — the guard must not bless it.
        $server = @stream_socket_server('tcp://0.0.0.0:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("wildcard bind unavailable here: {$errstr}");
        }
        $this->listeners[] = $server;

        $caught = null;
        try {
            OAuthLoopbackFlow::redirectUriFor($server);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the flow must throw');
        self::assertStringContainsString('not bound to 127.0.0.1', $caught->getMessage());
    }

    // =========================================================================
    // awaitCallback verdicts
    // =========================================================================

    public function testAGoodCallbackReturnsTheCodeAndAnswersWithAStaticPage(): void
    {
        $server = $this->bindListener();
        $this->fire($server, 'GET /callback?code=good-code-7&state=state-abc HTTP/1.1');

        $code = $this->flow()->awaitCallback($server, 'state-abc', 5.0);

        self::assertSame('good-code-7', $code);
        $response = $this->readResponse();
        self::assertStringContainsString('HTTP/1.1 200', $response);
        self::assertStringNotContainsString('good-code-7', $response, 'the page never echoes the code back');
        self::assertStringNotContainsString('state-abc', $response, 'nor the state');
    }

    public function testAMismatchedStateIsDiscardedAsPossibleCsrf(): void
    {
        $server = $this->bindListener();
        $this->fire($server, 'GET /callback?code=stolen-code-9&state=WRONG-state HTTP/1.1');

        $caught = null;
        try {
            $this->flow()->awaitCallback($server, 'expected-state-1', 5.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the flow must throw');
        self::assertStringContainsString('state did not match', $caught->getMessage());
        self::assertStringNotContainsString('stolen-code-9', $caught->getMessage(), 'the message never quotes the request');

        $response = $this->readResponse();
        self::assertStringContainsString('HTTP/1.1 400', $response);
        self::assertStringNotContainsString('stolen-code-9', $response, 'the rejection page is static too');
    }

    public function testAnyOtherPathIsRefused(): void
    {
        $server = $this->bindListener();
        $this->fire($server, 'GET /not-the-callback?code=x&state=expected-state-1 HTTP/1.1');

        $caught = null;
        try {
            $this->flow()->awaitCallback($server, 'expected-state-1', 5.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the flow must throw');
        self::assertStringContainsString('callback path was not', $caught->getMessage());

        self::assertStringContainsString('HTTP/1.1 404', $this->readResponse());
    }

    public function testANonGetRequestLineIsRefused(): void
    {
        $server = $this->bindListener();
        $this->fire($server, 'POST /callback?code=x&state=expected-state-1 HTTP/1.1');

        $caught = null;
        try {
            $this->flow()->awaitCallback($server, 'expected-state-1', 5.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the flow must throw');
        self::assertStringContainsString('must arrive as a GET', $caught->getMessage());

        self::assertStringContainsString('HTTP/1.1 405', $this->readResponse());
    }

    public function testACallbackWithoutACodeIsRefused(): void
    {
        $server = $this->bindListener();
        $this->fire($server, 'GET /callback?state=expected-state-1 HTTP/1.1');

        $caught = null;
        try {
            $this->flow()->awaitCallback($server, 'expected-state-1', 5.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the flow must throw');
        self::assertStringContainsString('carried no authorization code', $caught->getMessage());

        self::assertStringContainsString('HTTP/1.1 400', $this->readResponse());
    }

    public function testAnOversizedRequestLineIsRefusedBeforeAnyParsing(): void
    {
        $server = $this->bindListener();
        $line = 'GET /callback?' . str_repeat('a', 8500) . ' HTTP/1.1';
        $this->fire($server, $line);

        $caught = null;
        try {
            $this->flow()->awaitCallback($server, 'expected-state-1', 5.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the flow must throw');
        self::assertStringContainsString('not a readable GET', $caught->getMessage());

        self::assertStringContainsString('HTTP/1.1 400', $this->readResponse());
    }

    public function testTheWaitExpiresQuietlyWhenNoBrowserEverComes(): void
    {
        $server = $this->bindListener();

        // Design promise: the deadline arithmetic runs against the INJECTED
        // clock. Each tick jumps 0.95 s, so a 1.0 s budget lives exactly one
        // surviving slice (stream_select waits the computed 0.05 s remnant,
        // not a real second) and dies on the next check — deterministic,
        // sub-tenth-of-a-second, no ~0.3 s real sleep.
        $ticks = 0;
        $clock = static function () use (&$ticks): float {
            $now = 1000.0 + 0.95 * $ticks;
            $ticks++;

            return $now;
        };

        $caught = null;
        try {
            $this->flow($clock)->awaitCallback($server, 'state-abc', 1.0);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the flow must throw');
        self::assertStringContainsString('wait expired', $caught->getMessage());
        // 3 consults: arm the deadline, survive the first check (the budget
        // is honoured, not skipped), cross it on the second (it stops at the
        // deadline, not after the slice table).
        self::assertSame(3, $ticks, 'the injected budget was consulted exactly once per deadline check');

        // Tiny real-clock smoke (50 ms): the DEFAULT clock must actually
        // advance, or the seam above proves nothing about production.
        $start = microtime(true);
        $caught = null;
        try {
            $this->flow()->awaitCallback($server, 'state-abc', 0.05);
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        self::assertNotNull($caught, 'the real-clock wait must expire too');
        self::assertStringContainsString('wait expired', $caught->getMessage());
        self::assertGreaterThan(0.04, microtime(true) - $start, 'the default clock advances — the real wait spent its slice');
    }

    // =========================================================================
    // Harness
    // =========================================================================

    /**
     * @return resource
     */
    private function bindListener()
    {
        $server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($server === false) {
            $this->markTestSkipped("loopback bind unavailable here: {$errstr}");
        }
        $this->listeners[] = $server;

        return $server;
    }

    /**
     * Connect and hand over one request line; the connection then sits in the
     * listener backlog (and its bytes in the socket buffers) until
     * awaitCallback accepts — this is the design's in-process browser riding
     * the TCP backlog, no thread involved. The socket is kept open so the
     * server's response can be read after the verdict.
     *
     * @param resource $server
     */
    private function fire($server, string $requestLine): void
    {
        $name = stream_socket_get_name($server, false);
        self::assertIsString($name);
        $port = (int) substr($name, (int) strrpos($name, ':') + 1);

        $client = @fsockopen('127.0.0.1', $port, $errno, $errstr, 2.0);
        if ($client === false) {
            self::fail("loopback connect failed: {$errstr}");
        }
        $this->clients[] = $client;

        self::assertNotFalse(fwrite($client, $requestLine . "\r\n\r\n"));
    }

    /**
     * Drain the server's answer after awaitCallback returned or threw — the
     * server already closed its side, so this reads buffered bytes and EOF.
     */
    private function readResponse(): string
    {
        $client = $this->clients[array_key_last($this->clients)];
        self::assertIsResource($client, 'the client socket vanished before the answer was read');

        return (string) stream_get_contents($client);
    }

    private function flow(?callable $clock = null): OAuthLoopbackFlow
    {
        $authFile = sys_get_temp_dir() . '/loopback_flow_test_' . uniqid((string) getmypid(), true) . '.json';

        return new OAuthLoopbackFlow(
            new OAuthClientRegistration(new Client(['handler' => HandlerStack::create(new MockHandler([]))]), $authFile),
            null,
            $clock,
        );
    }
}
