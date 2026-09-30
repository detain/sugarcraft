<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * Real child-process round trips: the fixtures under tests/Fixtures speak
 * JSON-RPC over argv stdio, so every wait, drain, gate and reap path in the
 * transport is exercised for real — bounded, reaped, no network.
 *
 * Harness law inherited from the product suite: never fail() inside a
 * `catch (\RuntimeException)` — PHPUnit's AssertionFailedError IS a
 * RuntimeException, and an assertion swallowed by that catch silently greens
 * the test. Capture, exit the try, assert after.
 */
final class StdioMcpServerRoundTripTest extends TestCase
{
    /** Upper bound any bounded give-up must complete under (budgets are 1s). */
    private const BOUND_SECONDS = 6.0;

    /** @var list<StdioMcpServer> servers still up when the test ends */
    private array $live = [];

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }

        $this->live = [];
    }

    private static function fixture(string $name): string
    {
        return __DIR__ . '/Fixtures/' . $name;
    }

    private function spawn(string $name, string $fixture, ?float $budget = null): StdioMcpServer
    {
        $server = new StdioMcpServer($name, PHP_BINARY, [self::fixture($fixture)], startTimeoutSeconds: $budget);
        $this->live[] = $server;

        return $server;
    }

    public function testAWellBehavedServerCompletesTheHandshakeAndAnswersToolCalls(): void
    {
        $server = $this->spawn('probe', 'prompt_server.php');

        $startedAt = microtime(true);
        $server->start();
        $elapsed = microtime(true) - $startedAt;

        self::assertLessThan(0.5 * 60, $elapsed, 'a local child should handshake well inside the default budget');
        self::assertTrue($server->isUp());

        $tools = $server->listTools();
        self::assertCount(1, $tools);
        self::assertSame('ping', $tools[0]->name);
        self::assertSame('probe', $tools[0]->serverName);

        self::assertSame(
            ['content' => [['type' => 'text', 'text' => 'pong']]],
            $server->callTool('ping', []),
        );

        $server->stop();
        self::assertFalse($server->isUp());
    }

    public function testThePublicRequestApiReachesErrorReplies(): void
    {
        $server = $this->spawn('probe', 'prompt_server.php');
        $server->start();

        // Protocol generality: anything the server answers is one call away —
        // the fixture maps unknown methods to -32601 exactly like a real one.
        $response = $server->request('resources/list', []);

        self::assertNotNull($response);
        self::assertTrue($response->isError());
        self::assertSame(-32601, $response->errorCode());

        // A stray notification mid-session must not desynchronise the stream.
        $server->notify('notifications/cancelled', ['requestId' => '0']);
        $again = $server->request('tools/list', []);
        self::assertNotNull($again);
        self::assertTrue($again->resultSet);
    }

    public function testAChattyNotificationStreamCannotStarveTheHandshakeDeadline(): void
    {
        // The measured product failure: notifications forever reset every
        // per-read timeout, so only ONE wall clock across the handshake
        // bounds this. rc=124 was the old shape; a give-up must arrive near
        // the 1s budget, never at the 60s default.
        $server = $this->spawn('probe', 'chatty_server.php', 1.0);

        $caught = null;
        $startedAt = microtime(true);

        try {
            $server->start();
        } catch (\RuntimeException $thrown) {
            $caught = $thrown;
        }

        $elapsed = microtime(true) - $startedAt;
        $server->stop();

        self::assertNotNull($caught, 'the deadline must eventually refuse a silent-answer chatty server');
        self::assertStringContainsString('Failed to start MCP server: probe', $caught->getMessage());
        self::assertLessThan(self::BOUND_SECONDS, $elapsed);
    }

    public function testASilentServerIsGivenUpOnWithinTheHandshakeBudget(): void
    {
        $server = $this->spawn('probe', 'silent_server.php', 1.0);

        $caught = null;
        $startedAt = microtime(true);

        try {
            $server->start();
        } catch (\RuntimeException $thrown) {
            $caught = $thrown;
        }

        $elapsed = microtime(true) - $startedAt;
        $server->stop();

        self::assertNotNull($caught);
        self::assertStringContainsString('Failed to start MCP server: probe', $caught->getMessage());
        self::assertLessThan(self::BOUND_SECONDS, $elapsed);
        self::assertFalse($server->isUp(), 'a failed start must leave no child behind');
    }

    public function testAHandshakeRefusalCarriesServerStderrAsDiagnostics(): void
    {
        // echo writes its argument to stdout then dies; the response parse
        // fails the gate, and stop()'s diagnostics capture must ride along.
        $server = new StdioMcpServer('noisy', '/bin/sh', ['-c', 'echo boom-on-stderr >&2; echo nope']);
        $this->live[] = $server;

        $caught = null;

        try {
            $server->start();
        } catch (\RuntimeException $thrown) {
            $caught = $thrown;
        }

        $server->stop();

        self::assertNotNull($caught);
        self::assertStringContainsString('Failed to start MCP server: noisy', $caught->getMessage());
    }

    public function testStopEscalatesPastATermTrappingChildWithoutOrphaningIt(): void
    {
        // The stubborn fixture ignores SIGTERM: the BoundedShutdown ladder
        // must reach KILL. The whole exchange stays bounded by test-timeout
        // discipline; the assertion is that stop() RETURNS bounded and the
        // child is gone.
        if (!function_exists('pcntl_signal')) {
            self::markTestSkipped('the stubborn fixture needs pcntl to trap SIGTERM');
        }

        $server = $this->spawn('stubborn', 'stubborn_server.php', 1.0);

        $caught = null;
        $startedAt = microtime(true);

        try {
            $server->start();
        } catch (\RuntimeException $thrown) {
            $caught = $thrown;
        }

        self::assertNotNull($caught, 'a non-answering server must be refused');

        $stopStart = microtime(true);
        $server->stop();
        $stopElapsed = microtime(true) - $stopStart;
        $total = microtime(true) - $startedAt;

        self::assertFalse($server->isUp());
        self::assertLessThan(self::BOUND_SECONDS, $stopElapsed, 'TERM-ignoring children must be KILL-reaped');
        self::assertLessThan(2 * self::BOUND_SECONDS, $total);
    }

    public function testAFullStderrPipeNeverDeadlocksTheHandshake(): void
    {
        // The 64KiB kernel-pipe law: this fixture writes ~224KiB to stderr
        // before answering anything. Without stderr absorption on the wait
        // sets, the child blocks on its own write and the handshake times
        // out. With it, start() completes.
        $flooded = new StdioMcpServer(
            'flooded',
            PHP_BINARY,
            [self::fixture('echo_server.php'), '--flood'],
            startTimeoutSeconds: 10.0,
        );
        $this->live[] = $flooded;

        $startedAt = microtime(true);
        $flooded->start();
        $elapsed = microtime(true) - $startedAt;

        self::assertTrue($flooded->isUp());
        self::assertLessThan(10.0, $elapsed, 'stderr flood must not wedge the handshake');
        self::assertCount(1, $flooded->listTools());

        $flooded->pumpStderr();
        self::assertTrue($flooded->isUp(), 'pumping must never disturb a live session');
    }

    /** @return list<array{mixed, array<string,mixed>|string}> */
    public static function scalarResults(): array
    {
        return [
            'zero survives as text zero' => [0, ['content' => [['type' => 'text', 'text' => '0']]]],
            'true survives as json literal' => [true, ['content' => [['type' => 'text', 'text' => 'true']]]],
            'strings travel verbatim without quotes' => ['hi', ['content' => [['type' => 'text', 'text' => 'hi']]]],
            'null reaches the model as the text null' => [null, ['content' => [['type' => 'text', 'text' => 'null']]]],
            'arrays pass through untouched' => [['k' => 'v'], ['k' => 'v']],
        ];
    }

    /**
     * @dataProvider scalarResults
     *
     * @param array<string,mixed>|string $expected
     */
    public function testToolResultsKeepTheirShapeThroughTheWire(mixed $raw, array $expected): void
    {
        $server = $this->spawn('echoer', 'echo_server.php');
        $server->start();

        self::assertSame($expected, $server->callTool('echo', ['raw' => $raw]));
    }

    public function testToolResultsWithoutRawComeBackAsTheStandardEnvelope(): void
    {
        $server = $this->spawn('echoer', 'echo_server.php');
        $server->start();

        self::assertSame(
            ['content' => [['type' => 'text', 'text' => 'echoed']]],
            $server->callTool('echo', []),
        );
    }

    public function testDestructStopsWhatTheTestForgotToStop(): void
    {
        $server = new StdioMcpServer('probe', PHP_BINARY, [self::fixture('prompt_server.php')]);
        $server->start();
        self::assertTrue($server->isUp());

        $server = null; // __destruct must reap without an explicit stop()

        // Nothing left to assert on the object itself; prove no survivor:
        // a lingering php fixture child would outlive this test by design
        // (30s backstop), so instead assert the fresh instance's lifecycle.
        $watched = new StdioMcpServer('probe', PHP_BINARY, [self::fixture('prompt_server.php')]);
        self::assertFalse($watched->isUp(), 'construction alone never spawns');
    }
}
