<?php

declare(strict_types=1);

namespace SugarCraft\Mcp\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mcp\ExchangeLock;
use SugarCraft\Mcp\StdioMcpServer;

/**
 * Failure paths that used to degrade SILENTLY — a blank frame, an "up, 0
 * tools" start, a read only a helper's exit could end, a dropped
 * notification, an unrecorded phase marker. Each one now either fails loudly
 * or fails fast, and each test pins the moment it does.
 *
 * Harness law (see StdioMcpServerRoundTripTest): never assert inside a
 * `catch (\RuntimeException)` — capture, leave the try, assert after.
 */
final class StdioMcpServerFailureGatesTest extends TestCase
{
    /** Upper bound any fail-fast path must complete under. */
    private const BOUND_SECONDS = 6.0;

    /** @var list<StdioMcpServer> */
    private array $live = [];

    /** @var list<int> helper pids a fixture forked, killed at tearDown */
    private array $helpers = [];

    protected function tearDown(): void
    {
        foreach ($this->live as $server) {
            $server->stop();
        }
        $this->live = [];

        foreach ($this->helpers as $pid) {
            if ($pid > 0 && function_exists('posix_kill')) {
                @posix_kill($pid, SIGKILL);
            }
        }
        $this->helpers = [];
    }

    /** @param list<string> $args */
    private function spawn(string $fixture, array $args = [], ?float $budget = null): StdioMcpServer
    {
        $server = new StdioMcpServer(
            'probe',
            PHP_BINARY,
            [__DIR__ . '/Fixtures/' . $fixture, ...$args],
            startTimeoutSeconds: $budget,
        );
        $this->live[] = $server;

        return $server;
    }

    private static function startFailure(StdioMcpServer $server): ?string
    {
        try {
            $server->start();
        } catch (\RuntimeException $failure) {
            return $failure->getMessage();
        }

        return null;
    }

    public function testAToolsListErrorReplyIsAStartFailureNamingTheServersRefusal(): void
    {
        $server = $this->spawn('tools_list_refusal_server.php');

        $message = self::startFailure($server);

        self::assertNotNull($message, 'a tools/list error reply was accepted as "up, 0 tools"');
        self::assertStringContainsString('tools/list refused (-32601)', $message);
        self::assertStringContainsString('tools capability not enabled for this session', $message);
        self::assertStringContainsString('refusal-fixture: booted', $message, 'the stderr tail was dropped from the refusal');
        self::assertFalse($server->isUp(), 'the refused server was left running');
        self::assertSame([], $server->listTools());
    }

    public function testAnUnansweredToolsListIsAStartFailureNotAnEmptyTable(): void
    {
        $server = $this->spawn('tools_list_refusal_server.php', ['--no-reply'], 1.0);

        $startedAt = microtime(true);
        $message = self::startFailure($server);
        $elapsed = microtime(true) - $startedAt;

        self::assertNotNull($message, 'a silent tools/list leg was accepted as "up, 0 tools"');
        self::assertStringContainsString('no tools/list reply', $message);
        self::assertLessThan(self::BOUND_SECONDS, $elapsed);
        self::assertFalse($server->isUp());
    }

    public function testADroppedInitializedNotificationFailsTheStartWhereItHappened(): void
    {
        $server = $this->spawn('tools_list_refusal_server.php', ['--close-stdin'], 3.0);

        $startedAt = microtime(true);
        $message = self::startFailure($server);
        $elapsed = microtime(true) - $startedAt;

        self::assertNotNull($message);
        self::assertStringContainsString('notifications/initialized could not be delivered', $message);
        self::assertLessThan(self::BOUND_SECONDS, $elapsed);
        self::assertFalse($server->isUp(), 'the server whose stdin is gone was left running');
    }

    public function testNotifyReportsWhetherTheLineWentOut(): void
    {
        $server = $this->spawn('echo_server.php');

        self::assertFalse($server->notify('notifications/cancelled', ['requestId' => '0']), 'a never-started server claimed delivery');

        $server->start();
        self::assertTrue($server->notify('notifications/cancelled', ['requestId' => '0']));

        $server->stop();
        self::assertFalse($server->notify('notifications/cancelled', ['requestId' => '0']), 'a stopped server claimed delivery');
    }

    public function testAnUnencodableToolCallIsAReportedFailureNotABlankFrameHang(): void
    {
        $server = $this->spawn('echo_server.php');
        $server->start();

        // What a model's `1e999` decodes to. The old encoder emitted "" and the
        // deadline-less read then waited for a reply to a line nobody saw.
        $startedAt = microtime(true);
        $result = $server->callTool('echo', ['raw' => INF]);
        $elapsed = microtime(true) - $startedAt;

        self::assertLessThan(self::BOUND_SECONDS, $elapsed, 'callTool hung on an unencodable argument');
        self::assertArrayHasKey('error', $result);
        self::assertStringContainsString('cannot be encoded', (string) $result['error']);

        // Nothing went on the wire, so the session is still in step.
        self::assertSame(['content' => [['type' => 'text', 'text' => 'echoed']]], $server->callTool('echo', ['other' => 1]));
    }

    public function testThePublicRequestApiThrowsOnAnUnencodablePayload(): void
    {
        $server = $this->spawn('echo_server.php');
        $server->start();

        $raised = null;
        try {
            // A deadline keeps the pre-fix shape (blank line, no reply) bounded.
            $server->request('tools/call', ['name' => 'echo', 'arguments' => ['raw' => "\xff\xfe"]], hrtime(true) / 1e9 + 2.0);
        } catch (\InvalidArgumentException $failure) {
            $raised = $failure->getMessage();
        }

        self::assertNotNull($raised, 'an invalid-UTF-8 payload was sent as a blank frame');
        self::assertStringContainsString('"tools/call"', $raised);
    }

    /** @return array<string, array{list<string>}> */
    public static function helperShapes(): array
    {
        return [
            'silent helper' => [[]],
            // Chatter every 0.2s keeps the select from ever going idle, so a
            // liveness check gated on an idle poll alone would never run.
            'helper logging to stderr' => [['--chatty']],
        ];
    }

    /**
     * @dataProvider helperShapes
     * @param list<string> $mode
     */
    public function testADeadDirectChildEndsADeadlineLessCallEvenWhileAHelperHoldsStdout(array $mode): void
    {
        $this->assertHelperOutlivedAFastFailure($mode, static fn (StdioMcpServer $server): array => $server->callTool('work', []));
    }

    /**
     * @dataProvider helperShapes
     * @param list<string> $mode
     */
    public function testADeadDirectChildEndsADeadlineLessWriteEvenWhileAHelperHoldsStdin(array $mode): void
    {
        // Far past the ~64KiB pipe: with the reader dead and the helper never
        // reading, stdin stays full and no broken pipe ever arrives.
        $blob = str_repeat('a', 1 << 20);

        $this->assertHelperOutlivedAFastFailure(
            [...$mode, '--die-mid-write'],
            static fn (StdioMcpServer $server): array => $server->callTool('work', ['blob' => $blob]),
        );
    }

    /**
     * @param list<string> $mode
     * @param \Closure(StdioMcpServer): array<string,mixed> $call
     */
    private function assertHelperOutlivedAFastFailure(array $mode, \Closure $call): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill')) {
            self::markTestSkipped('needs ext-pcntl (fixture) and ext-posix (cleanup)');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'sugar-mcp-helper-');
        self::assertIsString($pidFile);

        try {
            $server = $this->spawn('orphaned_pipe_server.php', [$pidFile, ...$mode]);
            $server->start();

            $startedAt = microtime(true);
            $result = $call($server);
            $elapsed = microtime(true) - $startedAt;

            $helper = (int) @file_get_contents($pidFile);
            $this->helpers[] = $helper;

            self::assertGreaterThan(0, $helper, 'the fixture never forked its helper');
            self::assertTrue(posix_kill($helper, 0), 'the helper died early — a pipe closing, not liveness, ended the exchange');
            self::assertSame(['error' => 'Tool call failed'], $result);
            self::assertLessThan(
                self::BOUND_SECONDS,
                $elapsed,
                'the exchange waited on the helper (15s) instead of noticing the server itself was gone',
            );
            self::assertFalse($server->isUp());
        } finally {
            @unlink($pidFile);
        }
    }

    /** @return array<string, array{list<string>}> */
    public static function forkedCallShapes(): array
    {
        return [
            'read wait, silent helper' => [[]],
            'read wait, helper logging to stderr' => [['--chatty']],
            'write wait, silent helper' => [['--die-mid-write']],
        ];
    }

    /**
     * The sugar-crush shape: the owner starts the server, a pcntl_fork()ed turn
     * makes the call, and the owner sits in waitpid() on that turn — never in
     * proc_get_status() — so the dead server stays an unreaped zombie, which
     * signal 0 still reports as alive. The forked caller must see it as dead.
     *
     * @dataProvider forkedCallShapes
     * @param list<string> $mode
     */
    public function testAForkedCallerTreatsAnUnreapedDeadServerAsDead(array $mode): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('posix_kill') || !is_dir('/proc/self')) {
            self::markTestSkipped('needs ext-pcntl, ext-posix and procfs (zombie detection reads /proc/<pid>/stat)');
        }

        $pidFile = tempnam(sys_get_temp_dir(), 'sugar-mcp-helper-');
        $report = tempnam(sys_get_temp_dir(), 'sugar-mcp-forked-');
        self::assertIsString($pidFile);
        self::assertIsString($report);

        try {
            $server = $this->spawn('orphaned_pipe_server.php', [$pidFile, ...$mode]);
            $server->start();

            $args = in_array('--die-mid-write', $mode, true) ? ['blob' => str_repeat('a', 1 << 20)] : [];

            $turn = pcntl_fork();
            self::assertNotSame(-1, $turn, 'fork failed');
            if ($turn === 0) {
                // The forked turn: report through the file, then SIGKILL itself
                // so PHPUnit's shutdown machinery never runs in the copy.
                try {
                    $startedAt = microtime(true);
                    $result = $server->callTool('work', $args);
                    $elapsed = microtime(true) - $startedAt;
                    file_put_contents($report, json_encode(['result' => $result, 'elapsed' => $elapsed, 'up' => $server->isUp()]));
                } catch (\Throwable $thrown) {
                    file_put_contents($report, json_encode(['thrown' => $thrown::class . ': ' . $thrown->getMessage()]));
                } finally {
                    posix_kill(posix_getpid(), SIGKILL);
                }
            }

            // The owner blocks on the turn only (a bounded WNOHANG poll on that
            // one pid), so the server is never reaped while the turn runs.
            $deadline = microtime(true) + 20.0;
            while (pcntl_waitpid($turn, $status, WNOHANG) === 0) {
                if (microtime(true) >= $deadline) {
                    posix_kill($turn, SIGKILL);
                    pcntl_waitpid($turn, $status);
                    self::fail('the forked turn never finished');
                }
                usleep(10_000);
            }

            $helper = (int) @file_get_contents($pidFile);
            $this->helpers[] = $helper;

            /** @var array<string, mixed>|null $outcome */
            $outcome = json_decode((string) @file_get_contents($report), true);
            self::assertIsArray($outcome, 'the forked turn reported nothing');
            self::assertArrayNotHasKey('thrown', $outcome, (string) ($outcome['thrown'] ?? ''));

            self::assertGreaterThan(0, $helper, 'the fixture never forked its helper');
            self::assertTrue(posix_kill($helper, 0), 'the helper died early — a pipe closing, not liveness, ended the exchange');
            self::assertSame(['error' => 'Tool call failed'], $outcome['result']);
            self::assertLessThan(
                self::BOUND_SECONDS,
                (float) $outcome['elapsed'],
                'the forked caller took the zombie server for alive and waited on the helper (15s)',
            );
            self::assertFalse($outcome['up'], 'isUp() from a forked process reported a zombie server as up');
        } finally {
            @unlink($pidFile);
            @unlink($report);
        }
    }

    public function testANonOwnerTreatsAPidWhoseStartTimeChangedAsAReusedPidNotTheServer(): void
    {
        if (!is_dir('/proc/self') || !function_exists('posix_kill')) {
            self::markTestSkipped('needs ext-posix and procfs');
        }

        $server = $this->spawn('echo_server.php');
        $server->start();

        // Run the non-owner liveness path in this very process: an owner pid
        // that is not ours is exactly what a pcntl_fork()ed caller sees.
        $owner = new \ReflectionProperty(StdioMcpServer::class, 'ownerPid');
        $ticks = new \ReflectionProperty(StdioMcpServer::class, 'serverStartTicks');
        $realOwner = $owner->getValue($server);
        $realTicks = $ticks->getValue($server);
        self::assertGreaterThan(0, $realTicks, 'start() did not record the server\'s start time');

        try {
            $owner->setValue($server, $realOwner + 1);
            self::assertTrue($server->isUp(), 'a live server with a matching start time read as dead');

            $ticks->setValue($server, $realTicks + 1);
            self::assertFalse($server->isUp(), 'a pid now held by a process started at another time read as the server');
        } finally {
            $owner->setValue($server, $realOwner);
            $ticks->setValue($server, $realTicks);
        }

        self::assertTrue($server->isUp());
    }

    public function testAPhaseMarkerThatCannotBeRecordedFailsTheExchange(): void
    {
        $server = $this->spawn('echo_server.php');
        $server->start();

        $lock = (new \ReflectionProperty(StdioMcpServer::class, 'lock'))->getValue($server);
        self::assertInstanceOf(ExchangeLock::class, $lock);

        // A read-only handle on the real lock file: flock still works, every
        // state write fails — the full/read-only temp filesystem, on demand.
        $readOnly = fopen($lock->path, 'r');
        self::assertIsResource($readOnly);
        (new \ReflectionProperty(ExchangeLock::class, 'handle'))->setValue($lock, $readOnly);
        (new \ReflectionProperty(ExchangeLock::class, 'handlePid'))->setValue($lock, (int) getmypid());

        $response = $server->request('tools/list', null, hrtime(true) / 1e9 + 2.0);

        self::assertNull($response, 'an exchange ran with a W/R marker it could not record');
        self::assertFalse($server->notify('notifications/cancelled', ['requestId' => '0']));
    }

    /**
     * FIX #4: the 64MiB frame-cap refusal threw straight out of callTool() on
     * a mid-session oversized reply, breaking the McpServer contract promise
     * (a failed call answers as an error payload). Now the refusal is caught
     * in callToolWaiting. Dead-connection ruling, pinned here: the child is
     * NOT stopped — dropping the oversized buffer is itself the framing
     * reset, and the bounded remainder resyncs under readResponse's skip law,
     * so the SAME connection goes on to take a fresh exchange and fail it on
     * its own deadline rather than hang or throw.
     */
    public function testAnOversizedMidSessionReplyRefusesAsPayloadAndTheConnectionSurvives(): void
    {
        $server = $this->spawn('oversized_reply_server.php');
        $server->start();

        $startedAt = microtime(true);
        $reply = $server->callTool('echo', ['raw' => 'first']);
        $elapsed = microtime(true) - $startedAt;

        self::assertArrayHasKey('error', $reply, 'the frame-cap throw escaped callTool()');
        self::assertStringStartsWith('Tool call failed: ', $reply['error']);
        self::assertStringContainsString('frame cap', $reply['error']);
        self::assertLessThan(self::BOUND_SECONDS, $elapsed, 'the cap trip was not fail-fast');
        self::assertTrue($server->isUp(), 'the refusal stopped a server that only sent one bad frame');

        $secondAt = microtime(true);
        $second = $server->callTool('echo', ['raw' => 'second'], timeoutSeconds: 1.0);
        $secondElapsed = microtime(true) - $secondAt;

        self::assertArrayHasKey('error', $second);
        self::assertStringContainsString('Tool call timed out', $second['error']);
        self::assertLessThan(self::BOUND_SECONDS, $secondElapsed);
    }

    /**
     * FIX #6: a non-conforming server that keeps the pipe busy with frames
     * attributable to no request (null/float ids on result/error pushes,
     * batches, garbage) used to leave a deadline-less callTool() skipping
     * them forever. The skip-strike tripwire now refuses the exchange after
     * the threshold — reported as a payload (FIX #4 rail), naming the server
     * — instead of hanging or throwing.
     */
    public function testAFloodOfUnattributableFramesRefusesTheOutstandingExchange(): void
    {
        $server = $this->spawn('unattributable_server.php');
        $server->start();

        $startedAt = microtime(true);
        $reply = $server->callTool('echo', ['raw' => 'first']);
        $elapsed = microtime(true) - $startedAt;

        self::assertArrayHasKey('error', $reply, 'the unattributable flood left callTool() hanging or throwing');
        self::assertStringStartsWith('Tool call failed: ', $reply['error']);
        self::assertStringContainsString('attributable to no request', $reply['error']);
        self::assertStringContainsString('probe', $reply['error'], 'the refusal did not name the server');
        self::assertLessThan(self::BOUND_SECONDS, $elapsed);
        self::assertTrue($server->isUp(), 'the tripwire stopped a server that merely answers badly');

        $secondAt = microtime(true);
        $second = $server->callTool('echo', ['raw' => 'second'], timeoutSeconds: 1.0);
        self::assertArrayHasKey('error', $second);
        self::assertStringContainsString('Tool call timed out', $second['error']);
        self::assertLessThan(self::BOUND_SECONDS, microtime(true) - $secondAt);
    }

    /**
     * The under-threshold half of FIX #6: fewer strike frames than the
     * threshold, then a genuine reply — the call must SUCCEED, proving the
     * tripwire bounds only hopeless waits and per-read counting lets any
     * answered exchange start from zero strikes.
     */
    public function testStrikeFramesUnderTheThresholdStillAnswer(): void
    {
        $server = $this->spawn('unattributable_server.php', ['--recovers']);
        $server->start();

        $reply = $server->callTool('echo', ['raw' => 'first']);

        self::assertArrayNotHasKey('error', $reply, 'a server that answered under the threshold was refused');
        self::assertSame([['type' => 'text', 'text' => 'recovered']], $reply['content'] ?? $reply);
    }
}
