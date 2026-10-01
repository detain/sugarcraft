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

    /** The OS pid of the wrapper's live child, captured while it is still ours. */
    private static function childPid(StdioMcpServer $server): ?int
    {
        $property = new \ReflectionProperty(StdioMcpServer::class, 'process');
        $property->setAccessible(true);
        $process = $property->getValue($server);

        return is_resource($process) ? (int) proc_get_status($process)['pid'] : null;
    }

    private static function stderrTailOf(StdioMcpServer $server): string
    {
        $property = new \ReflectionProperty(StdioMcpServer::class, 'stderrTail');
        $property->setAccessible(true);

        return (string) $property->getValue($server);
    }

    /**
     * Pids of STILL-RUNNING children of this test process whose cmdline contains
     * $needle. Zombies do not count: proc_close reaps them, and a zombie is a
     * reaped child, not an orphan. A TERM-ignoring survivor (cmdline readable,
     * ppid ours) is precisely the orphan the ladder must prevent.
     *
     * @return list<int>
     */
    private static function liveChildrenRunning(string $needle): array
    {
        $pids = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $line = @file_get_contents($file);
            if ($line === false || !str_contains($line, $needle)) {
                continue;
            }

            $pid = (int) basename(\dirname($file));
            $stat = @file_get_contents('/proc/' . $pid . '/stat');
            if ($stat === false) {
                continue;
            }

            // comm (field 2) may contain spaces and parens; fields after it
            // start one space past the LAST ')'.
            $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
            if ((int) ($fields[1] ?? 0) === getmypid()) {
                $pids[] = $pid;
            }
        }

        return $pids;
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

    public function testSpontaneousIdLessBroadcastsAreSkippedAndNeverAnswerARequest(): void
    {
        // Review probe P7: the strict-match fix. The rogue fixture pushes a
        // `result` frame with NO id before every genuine answer; only the
        // exact-id response may answer a request. Under the old leniency the
        // broadcast was accepted as initialize's reply and the real answers
        // drifted one exchange behind — tools/list then matched a broadcast.
        $server = $this->spawn('rogue', 'rogue_server.php');
        $server->start();

        $tools = $server->listTools();
        self::assertSame(['real'], array_map(static fn ($tool): string => $tool->name, $tools));
        self::assertSame(
            'echoed',
            $server->callTool('real', [])['content'][0]['text'],
            'later exchanges must still match their own id through the broadcast noise',
        );

        $server->stop();
    }

    public function testADoubleStartIsRefusedInsteadOfOrphaningTheLiveChild(): void
    {
        // Review probe P6: a second start() used to overwrite the proc handle,
        // leaving the first child running with no owner. Fail-fast now.
        $server = $this->spawn('twice', 'prompt_server.php');
        $server->start();
        $pid = self::childPid($server);
        self::assertNotNull($pid);

        $caught = null;
        try {
            $server->start();
        } catch (\RuntimeException $thrown) {
            $caught = $thrown;
        }

        self::assertNotNull($caught, 'a live server must refuse a second start()');
        self::assertStringContainsString('double start()', $caught->getMessage());
        self::assertSame($pid, self::childPid($server), 'the refusal must not swap the handle');

        $server->stop();
        self::assertSame(
            [],
            self::liveChildrenRunning('prompt_server.php'),
            'exactly one child was ever owned, and stop() reaped it',
        );
    }

    public function testAFrameCapThrowMidHandshakeReapsTheChildItSpawned(): void
    {
        // Review fix F6 pin: the oversized-frame refusal throws from INSIDE
        // the request leg, previously escaping start() with the child alive
        // on the pipes until __destruct. The catch leg must reap it now.
        // (This fixture also discriminates the readLine offset-scan fix: an
        // O(n²) rescan of 64MiB would blow the 5s budget into the deadline
        // path and the message assertion below would go red.)
        $hog = $this->spawn('hog', 'oversized_frame_server.php', 5.0);

        $caught = null;
        $startedAt = microtime(true);
        try {
            $hog->start();
        } catch (\RuntimeException $thrown) {
            $caught = $thrown;
        }
        $elapsed = microtime(true) - $startedAt;

        self::assertNotNull($caught, 'an oversized frame must refuse the handshake');
        self::assertStringContainsString('no newline', $caught->getMessage());
        self::assertFalse($hog->isUp());
        self::assertSame(
            [],
            self::liveChildrenRunning('oversized_frame_server.php'),
            'the mid-handshake throw must have stopped the child it spawned',
        );
        self::assertLessThan(
            self::BOUND_SECONDS,
            $elapsed,
            'accumulating to the cap must stay linear — a re-scan-per-chunk server is a CPU DoS',
        );
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
        // The capture must actually ride along — a prefix-only assertion would
        // green even if the diagnostics half of the message were dropped.
        self::assertStringContainsString('boom-on-stderr', $caught->getMessage());
    }

    public function testStopEscalatesPastATermTrappingChildWithoutOrphaningIt(): void
    {
        // The stubborn fixture ignores SIGTERM: the BoundedShutdown ladder
        // must reach KILL. The ladder runs inside start()'s failure gate (the
        // measured stop() below is a no-op second pass), so the load-bearing
        // assertions are the /proc census — the direct proof the child is
        // DEAD, not merely detached from the wrapper — plus the wall bound.
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
        self::assertSame(
            [],
            self::liveChildrenRunning('stubborn_server.php'),
            'a TERM-ignoring child must be KILL-reaped, never left running',
        );
        self::assertLessThan(self::BOUND_SECONDS, $stopElapsed, 'TERM-ignoring children must be KILL-reaped');
        self::assertLessThan(2 * self::BOUND_SECONDS, $total);
    }

    public function testAnExplicitStopReapsAHealthyChildAtTheProcessLevel(): void
    {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('this pin asserts process-level death via posix_kill');
        }

        $server = $this->spawn('probe', 'prompt_server.php');
        $server->start();
        $pid = self::childPid($server);
        self::assertNotNull($pid, 'a started server owns a live child');

        $startedAt = microtime(true);
        $server->stop();

        self::assertLessThan(
            2.0,
            microtime(true) - $startedAt,
            'close-pipes-first lets a well-behaved child exit on stdin EOF without paying the signal ladder',
        );
        self::assertFalse(posix_kill($pid, 0), 'isUp() going false is bookkeeping; the pid going dead is the promise');
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

        // The 64KiB law on OUR side too: the capture is tail-bounded, so a
        // server that shouts cannot grow our memory without bound AND cannot
        // push its recent (most diagnostic) output out of the buffer. The
        // fixture stamps a one-off head marker before the flood; after full
        // drain it must be gone while the cap is exactly reached.
        $cap = (int) (new \ReflectionClass(StdioMcpServer::class))
            ->getReflectionConstant('MAX_STDERR_BYTES')
            ->getValue();
        $tail = self::stderrTailOf($flooded);
        for ($pass = 0; strlen($tail) < $cap && $pass < 64; $pass++) {
            $flooded->pumpStderr();
            $tail = self::stderrTailOf($flooded);
        }

        self::assertSame(65536, $cap, 'the cap itself is the 64KiB law');
        self::assertSame($cap, strlen($tail), 'stderr capture is capped at 64KiB');
        self::assertStringNotContainsString('HEAD-OF-FLOOD-MARKER', $tail, 'the TAIL is kept, not the head');
        self::assertStringContainsString('diagnostic noise', $tail);
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
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('this pin asserts process-level death via posix_kill');
        }

        $server = new StdioMcpServer('probe', PHP_BINARY, [self::fixture('prompt_server.php')]);
        $server->start();
        $pid = self::childPid($server);
        self::assertNotNull($pid);
        self::assertTrue($server->isUp());

        $server = null; // __destruct must reap without an explicit stop()
        gc_collect_cycles();

        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline && posix_kill($pid, 0)) {
            usleep(50000);
        }

        self::assertFalse(posix_kill($pid, 0), 'dropping the last reference must reap the child, not orphan it');

        $watched = new StdioMcpServer('probe', PHP_BINARY, [self::fixture('prompt_server.php')]);
        self::assertFalse($watched->isUp(), 'construction alone never spawns');
    }
}
