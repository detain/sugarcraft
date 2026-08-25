<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\MCP;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\MCP\StdioMcpServer;
use SugarCraft\Crush\McpMessage;

/**
 * {@see StdioMcpServer::writeLine()} MUST ALWAYS BE ABLE TO GIVE UP — on a
 * deadline where its caller has one, and on the child's liveness where it does
 * not.
 *
 * Two findings, fixed together because they are the two halves of one hole:
 *
 *  - THE DEADLINE WAS NOT THREADED. {@see StdioMcpServer::start()} owns a
 *    handshake budget and passed it to {@see StdioMcpServer::readLine()} through
 *    {@see StdioMcpServer::readResponse()}, but never to the write. A server that
 *    spawns, holds its pipes open, never reads stdin and never writes stderr
 *    therefore had the READ half of every exchange bounded and the WRITE half
 *    unbounded.
 *  - THE EINTR BRANCH HAD NO EXIT OF ANY KIND. `@stream_select()` answers `false`
 *    for EINTR, and the branch did `usleep(1000); continue;` with no check of
 *    anything. A persistently failing select spun there at 1 ms forever — on
 *    {@see StdioMcpServer::callTool()}'s path, which passes no deadline at all
 *    and so had nothing else to stop it.
 *
 * ⚠️ THE FIRST FINDING'S REACHABILITY CLAIM IS FALSE, AND THE TRIPWIRE BELOW IS
 * WHY THIS FILE SAYS SO RATHER THAN REPEATING IT. It was filed as "will hold
 * `start()` inside `writeLine()` indefinitely once the message exceeds the stdin
 * pipe buffer". The premise never holds: MEASURED byte-exact against this class's
 * own {@see McpMessage} calls, the ENTIRE handshake — `initialize` 162 bytes,
 * `initialized` 40, `tools/list` 61, plus one newline each — is 266 bytes on the
 * wire, against a 65536-byte pipe capacity. `start()`'s messages are fixed and
 * tiny; nothing it sends can fill a pipe. So threading the deadline is DEFENSIVE,
 * not a live bug fix, and {@see testTheWholeHandshakeStillFitsInOnePipeBuffer()}
 * derives that claim rather than restating it — it reds on the day someone grows
 * the `capabilities` block, which is the day the finding becomes true.
 *
 * The second finding is real on the deadline-less path and is pinned below with a
 * signal storm, which is the only way to make `stream_select()` fail on demand:
 * MEASURED on this host, a CLOSED pipe resource does not make it return `false`,
 * it raises a `TypeError` that `@` does not suppress.
 */
final class StdioMcpServerWriteBoundsTest extends TestCase
{
    /**
     * Over the 65536-byte pipe capacity, so a child that never reads stdin
     * leaves the write loop genuinely stuck rather than completing trivially.
     */
    private const OVERSIZED_BYTES = 200000;

    /** The write budget handed to the reflection-driven rows. */
    private const WRITE_DEADLINE_SECONDS = 0.5;

    /**
     * Generous next to the 0.5s budget, tight enough that an unbounded write is
     * unambiguous. Three lanes share this box.
     */
    private const BOUND_SECONDS = 4.0;

    /**
     * The signal-storm rows are slower on purpose: the consecutive-failure
     * backstop is 10000 iterations, and walking it is the only way to prove it
     * terminates at all.
     */
    private const STORM_BOUND_SECONDS = 45.0;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sc_mcp_writebounds_' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);

        parent::tearDown();
    }

    // =========================================================================
    // The deadline
    // =========================================================================

    /**
     * A WRITE THAT CANNOT FINISH GIVES UP ON THE CALLER'S CLOCK.
     *
     * Driven through reflection rather than through {@see StdioMcpServer::start()},
     * and the class doc-block says why: `start()` cannot reach this state, because
     * its whole handshake is 266 bytes. Reflection is what lets the guard describe
     * the CONTRACT — "a deadline, once given, is honoured" — instead of a scenario
     * that does not exist.
     *
     * ⚠️ SCOPE: this row proves the loop honours a deadline it is handed. That the
     * deadline is handed to it on `start()`'s path is a separate claim, pinned by
     * {@see testTheHandshakeDeadlineReachesTheWriteAndNotOnlyTheRead()}.
     */
    public function testTheWriteLoopGivesUpOnItsDeadlineAgainstAServerThatNeverReads(): void
    {
        $server = $this->serverOver($this->deafServerScript());
        $this->startProcessWithoutHandshake($server);

        try {
            $started = microtime(true);
            $wrote = $this->writeLine($server, str_repeat('x', self::OVERSIZED_BYTES), microtime(true) + self::WRITE_DEADLINE_SECONDS);
            $elapsed = microtime(true) - $started;

            $this->assertFalse($wrote, 'a write that could not finish reported success');
            $this->assertLessThan(
                self::BOUND_SECONDS,
                $elapsed,
                sprintf(
                    'writeLine() took %.2fs against a %.1fs deadline and a server that never '
                    . 'reads its stdin — the deadline is not reaching the write loop',
                    $elapsed,
                    self::WRITE_DEADLINE_SECONDS,
                ),
            );
            $this->assertGreaterThan(
                self::WRITE_DEADLINE_SECONDS / 2,
                $elapsed,
                'writeLine() returned far too early to have tried — it is failing for some '
                . 'reason other than the deadline, so this row is not measuring the deadline',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * THE OTHER POLARITY: a write that CAN finish is not cut short by a deadline
     * that has plenty left. Without this, the row above is satisfied by a loop
     * that returns `false` unconditionally.
     */
    public function testAWriteThatFitsSucceedsWithTheSameDeadlineInPlace(): void
    {
        $server = $this->serverOver($this->deafServerScript());
        $this->startProcessWithoutHandshake($server);

        try {
            $this->assertTrue(
                $this->writeLine($server, str_repeat('x', 1000), microtime(true) + self::BOUND_SECONDS),
                'a 1000-byte write into an empty pipe was refused',
            );
        } finally {
            $server->stop();
        }
    }

    /**
     * THE DEADLINE IS ACTUALLY HANDED OVER, which the reflection rows above
     * cannot say. `start()` threads its handshake budget through
     * {@see StdioMcpServer::request()} and {@see StdioMcpServer::notify()} to the
     * write, and a null there would leave the write loop bounded only by the
     * child's liveness.
     *
     * Read off the private methods' signatures rather than off behaviour, because
     * the behaviour is unreachable — see the class doc-block. A signature check is
     * a weak instrument on its own; it is here because the alternative is no
     * instrument, and it is paired with the tripwire below that reds when the
     * behaviour BECOMES reachable.
     */
    public function testTheHandshakeDeadlineReachesTheWriteAndNotOnlyTheRead(): void
    {
        foreach (['request', 'notify', 'writeLine', 'readLine', 'readResponse'] as $method) {
            $reflected = new \ReflectionMethod(StdioMcpServer::class, $method);
            $names = array_map(static fn (\ReflectionParameter $p): string => $p->getName(), $reflected->getParameters());

            $this->assertContains(
                'deadline',
                $names,
                "$method() has no \$deadline parameter, so the handshake budget stops somewhere "
                . 'short of it',
            );
        }

        $source = (string) file_get_contents((new \ReflectionClass(StdioMcpServer::class))->getFileName());

        $this->assertStringContainsString(
            '$this->writeLine(McpMessage::request($id, $method, $params)->toJson(), $deadline)',
            $source,
            'request() is not passing its deadline to the write',
        );
        $this->assertStringContainsString(
            "\$this->notify('initialized', null, \$deadline)",
            $source,
            "start() is not passing its budget to the `initialized` notification, which then "
            . 'sits unbounded between two bounded exchanges',
        );
    }

    /**
     * THE TRIPWIRE, and the reason this file does not simply repeat the finding's
     * reachability claim as prose.
     *
     * The handshake is 266 bytes today. While that holds, the deadline threading
     * is defensive and no test can exercise it end to end. The day the
     * `capabilities` block grows past a pipe buffer, the finding becomes true and
     * this row reds — in the same change that made it true, which is the only
     * moment anyone can act on it.
     *
     * Derived from the class's own {@see McpMessage} calls rather than restated,
     * per the rule that a load-bearing number must come from a generator.
     */
    public function testTheWholeHandshakeStillFitsInOnePipeBuffer(): void
    {
        $onTheWire = 0;
        foreach ([
            McpMessage::request('0', 'initialize', [
                'protocolVersion' => '2024-11-05',
                'capabilities' => [],
                'clientInfo' => ['name' => 'sugar-crush', 'version' => '1.0.0'],
            ]),
            McpMessage::notification('initialized', null),
            McpMessage::request('1', 'tools/list', []),
        ] as $message) {
            $onTheWire += strlen($message->toJson()) + 1;
        }

        $this->assertSame(266, $onTheWire, 'the handshake wire size moved — re-read this file');
        $this->assertLessThan(
            self::MEASURED_PIPE_CAPACITY_BYTES,
            $onTheWire,
            'the handshake no longer fits in one pipe buffer, so a server that never reads its '
            . 'stdin CAN now hold start() inside writeLine() — the deadline threading has '
            . 'stopped being defensive and needs an end-to-end guard',
        );
    }

    /** Linux's default pipe capacity on this host — not a PHP constant. */
    private const MEASURED_PIPE_CAPACITY_BYTES = 65536;

    // =========================================================================
    // The EINTR branch, on the path that has no deadline
    // =========================================================================

    /**
     * A DEAD CHILD IS CAUGHT BY THE FAILED WRITE, NOT BY THE EINTR BRANCH — and
     * this row exists because the fix originally claimed the opposite.
     *
     * The liveness check added to the EINTR branch was written as its primary
     * exit. It is not, and it CANNOT BE: see
     * {@see testOnlyAFullPipeWithALiveChildCanInterruptTheWriteSelect()} for the
     * measured table. A dead child makes the write fd instantly ready in every
     * pipe state, so `stream_select()` never blocks, so it can never be
     * interrupted, so the EINTR branch is unreachable the moment the child is
     * gone. The exit that actually fires is `$written === false`.
     *
     * MEASURED BY MUTATION: with the liveness check deleted, this row stayed
     * green and the file stayed at its baseline runtime. That is what this
     * doc-block records rather than the claim the fix shipped with.
     *
     * NO DEADLINE IS PASSED, deliberately — that is {@see StdioMcpServer::callTool()}'s
     * shape, the one with nothing else to stop it.
     */
    public function testADeadChildIsCaughtByTheFailedWriteAndNotByTheEintrBranch(): void
    {
        [$rc, $out, $elapsed] = $this->runStormProbe('dead');

        $this->assertSame(0, $rc, sprintf('the storm probe did not finish after %.2fs: %s', $elapsed, trim($out)));
        $this->assertStringContainsString(
            'RESULT:false',
            $out,
            'writeLine() did not report failure against a child that is gone. Output: ' . trim($out),
        );
        $this->assertLessThan(
            2.0,
            $this->reportedFloat($out, 'ELAPSED'),
            'writeLine() took seconds to notice a child that had already exited — the failed '
            . 'write is no longer the dead-child detector, and nothing else on this path is '
            . 'fast. Output: ' . trim($out),
        );
    }

    /**
     * ...AND IT TERMINATES EVEN WITH THE CHILD STILL ALIVE, which is the backstop
     * rather than the liveness check: a structurally unusable fd would otherwise
     * be an infinite loop with a perfectly healthy process on the other end.
     *
     * Slower on purpose. The bound is 10000 consecutive failures and this row
     * walks all of them; the assertion is that it ENDS, not that it ends quickly.
     * The row above is what says the ordinary case does not pay this.
     */
    public function testTheEintrBranchTerminatesEvenWhileTheChildIsStillAlive(): void
    {
        [$rc, $out, $elapsed] = $this->runStormProbe('alive');

        $this->assertSame(
            0,
            $rc,
            sprintf(
                'the storm probe did not finish (rc=%d) after %.2fs — a persistently failing '
                . 'stream_select() spins in writeLine() with no exit. Output: %s',
                $rc,
                $elapsed,
                trim($out) === '' ? '(none)' : trim($out),
            ),
        );
        $this->assertStringContainsString('RESULT:false', $out, 'Output: ' . trim($out));
        $this->assertGreaterThan(
            1.0,
            $this->reportedFloat($out, 'ELAPSED'),
            'writeLine() returned too fast to have walked the backstop, so this row exited some '
            . 'other way and is not measuring the backstop. Output: ' . trim($out),
        );
    }

    /**
     * THE CONTROL FOR BOTH STORM ROWS: the same probe with NO storm running must
     * report that `stream_select()` succeeded. Without it, a probe whose fork
     * silently failed would report a prompt `false` for the ordinary reason — the
     * child is deaf and the deadline-less loop found the pipe full — and both rows
     * above would pass having exercised nothing.
     */
    public function testTheStormProbeIsMeasuringAStormAndNotAnOrdinaryFullPipe(): void
    {
        [$rc, $out, ] = $this->runStormProbe('control');

        $this->assertSame(0, $rc, 'the control probe is broken: ' . trim($out));
        $this->assertGreaterThan(
            0,
            $this->reportedInt($out, 'SELECTOK'),
            'with no storm running, stream_select() still never succeeded — the probe is not '
            . 'measuring what it claims. Output: ' . trim($out),
        );
        $this->assertSame(
            0,
            $this->reportedInt($out, 'SELECTFAIL'),
            'stream_select() failed with no storm running, so the storm rows cannot attribute '
            . 'their failures to the storm. Output: ' . trim($out),
        );
    }

    /**
     * THE MECHANISM BEHIND BOTH ROWS ABOVE, AND THE TRIPWIRE UNDER THE LIVENESS
     * CHECK'S DORMANCY.
     *
     * A write-set `stream_select()` can only be interrupted if it BLOCKS, and it
     * only blocks when the pipe is full AND the child is alive. Every other state
     * makes the fd instantly ready, so the storm has no window to land in.
     * MEASURED on this host (PHP 8.3.6, Linux 6.8), 1s of a 300 µs SIGUSR1 storm
     * per state, three consecutive takes, identical every time:
     *
     *     pipe   child   select false   select ok
     *     empty  live            0        ~695000
     *     FULL   LIVE        ~2815              0     <- the only interruptible state
     *     empty  dead            0        ~660000
     *     full   dead            0        ~692000
     *
     * TWO CONSEQUENCES, and the second is a correction to the fix this file
     * guards:
     *
     *  1. The `alive` storm row is exercising the backstop for real, because its
     *     oversized payload fills the pipe against a child that never reads.
     *  2. {@see StdioMcpServer}'s EINTR liveness check IS DORMANT BY CONSTRUCTION.
     *     It was added as that branch's primary exit; the table says a dead child
     *     can never reach the branch at all, because its fd never blocks. It is
     *     kept rather than deleted — it costs one `proc_get_status()` per EINTR,
     *     it is correct, and it becomes live the moment the loop's shape changes
     *     (an `except` set, a poll on an empty pipe, a child that dies between the
     *     select and the check). THIS ROW IS THE PIN ON THAT DORMANCY: if a
     *     platform ever stops making a dead child's fd instantly ready, the
     *     `full/dead` figure moves, this reds, and the next reader is told the
     *     check has become load-bearing.
     */
    public function testOnlyAFullPipeWithALiveChildCanInterruptTheWriteSelect(): void
    {
        $probe = $this->tempDir . '/reach.php';
        file_put_contents($probe, self::REACHABILITY_PROBE);

        $interruptible = [];
        foreach (['empty-live', 'full-live', 'empty-dead', 'full-dead'] as $state) {
            [$rc, $out, ] = $this->runBounded([PHP_BINARY, $probe, $state], 20.0);
            $this->assertSame(0, $rc, "the reachability probe failed for $state: " . trim($out));

            $failures = $this->reportedInt($out, 'SELECTFAIL');
            $successes = $this->reportedInt($out, 'SELECTOK');
            $this->assertGreaterThan(
                0,
                $failures + $successes,
                "the probe made no select() calls at all for $state, so its verdict is empty",
            );
            $interruptible[$state] = $failures > 0;
        }

        $this->assertSame(
            ['empty-live' => false, 'full-live' => true, 'empty-dead' => false, 'full-dead' => false],
            $interruptible,
            'the set of interruptible pipe states has moved. If full/dead is now true, the '
            . 'EINTR liveness check in writeLine() has stopped being dormant and needs a real '
            . 'behavioural guard; if full/live is now false, the backstop row above is no longer '
            . 'exercising the backstop and is passing vacuously',
        );
    }

    /**
     * argv[1] is one of `empty-live`, `full-live`, `empty-dead`, `full-dead`.
     * Reports the two select() outcome counts for that state under a storm.
     */
    private const REACHABILITY_PROBE = <<<'PHP'
        <?php
        $state = $argv[1];
        $script = tempnam(sys_get_temp_dir(), 'screach') . '.php';
        file_put_contents($script, '<?php sleep(60);');   // never reads its stdin
        $p = proc_open([PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_set_blocking($pipes[0], false);
        usleep(200000);
        if (str_starts_with($state, 'full')) {
            $n = 0;
            while (($w = @fwrite($pipes[0], str_repeat('x', 8192))) > 0) {
                $n += $w;
                if ($n > 300000) { break; }
            }
        }
        if (str_ends_with($state, 'dead')) {
            proc_terminate($p, 9);
            for ($i = 0; $i < 200 && proc_get_status($p)['running']; $i++) { usleep(5000); }
        }
        pcntl_async_signals(false);
        pcntl_signal(SIGUSR1, static function (): void {});
        $parent = getmypid();
        $kid = pcntl_fork();
        if ($kid === 0) {
            $t = microtime(true);
            while (microtime(true) - $t < 3.0) { posix_kill($parent, SIGUSR1); usleep(300); }
            exit(0);
        }
        usleep(50000);
        $fail = 0; $ok = 0; $t0 = microtime(true);
        while (microtime(true) - $t0 < 1.0) {
            $w = [$pipes[0]]; $r = []; $e = [];
            if (@stream_select($r, $w, $e, 1, 0) === false) { $fail++; } else { $ok++; }
        }
        echo 'SELECTFAIL:', $fail, PHP_EOL, 'SELECTOK:', $ok, PHP_EOL;
        posix_kill($kid, 9); pcntl_waitpid($kid, $st);
        if (proc_get_status($p)['running']) { proc_terminate($p, 9); }
        foreach ($pipes as $q) { if (is_resource($q)) fclose($q); }
        proc_close($p); unlink($script);
        PHP;

    // =========================================================================
    // stop() closes the pipes before the escalation ladder
    // =========================================================================

    /**
     * CLOSING THE PIPES BEFORE THE LADDER IS WHAT LETS A SIGTERM-TRAPPING SERVER
     * EXIT CLEANLY, AND THE COST OF GETTING IT WRONG IS A SIGKILL.
     *
     * `stop()` used to set `$this->pipes = null` and leave the resources to the
     * destructor. That reads as ordering hygiene and it is not: `proc_close()`
     * WAITS for the child, and a server whose stdin is still open has been given
     * no reason to leave.
     *
     * MEASURED on this host (PHP 8.3.6, Linux 6.8), three consecutive takes,
     * identical every time, against a child that traps SIGTERM to a no-op and
     * exits on stdin EOF, driven through this class's own TERM / 1.0s poll /
     * signal-9 ladder:
     *
     *     pipes left open    -> 1.05s, escalated to signal 9, exit status 9
     *     pipes closed first -> 0.010s, exited on its own, exit status 0
     *
     * A hundredfold, and the difference between a clean exit and a SIGKILL. THIS
     * FALSIFIES THE SEVERITY THE FINDING WAS FILED UNDER, which called the
     * ordering minor because "the child is gone by then in practice". An MCP
     * server that traps SIGTERM to flush state is ordinary, not exotic.
     */
    public function testStopClosesThePipesBeforeTheLadderSoASigtermTrappingServerCanExit(): void
    {
        $server = $this->serverOver($this->eofExitingServerScript());
        $this->startProcessWithoutHandshake($server);
        $pid = $this->pidOf($server);

        $started = microtime(true);
        $server->stop();
        $elapsed = microtime(true) - $started;

        $this->assertLessThan(
            self::EOF_EXIT_BOUND_SECONDS,
            $elapsed,
            sprintf(
                'stop() took %.2fs against a server that exits on stdin EOF — the pipes are not '
                . 'being closed before the ladder, so the server never saw the EOF and paid the '
                . 'whole SIGTERM grace before being killed with signal 9',
                $elapsed,
            ),
        );
        $this->assertFalse($this->isAlive($pid), 'the server is still running after stop()');
    }

    /**
     * THE KNOWN-POSITIVE CONTROL for the row above, without which it is satisfied
     * by a fixture that simply dies on SIGTERM like any other process.
     *
     * The SAME fixture is driven through the SAME ladder by a raw `proc_open()`
     * here in the test, with its pipes deliberately LEFT OPEN, and must pay the
     * full grace. If ext-pcntl were missing, or the trap ineffective, or the
     * fixture exited for some reason other than the EOF, this row reds.
     */
    public function testThatFixtureReallyDoesIgnoreSigtermWhileItsStdinStaysOpen(): void
    {
        $script = $this->eofExitingServerScript();
        $process = proc_open(
            [PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'could not spawn the control fixture');

        // Wait for the trap to be installed, not merely for the process to exist.
        usleep(300000);

        $started = microtime(true);
        proc_terminate($process);
        $graceDeadline = microtime(true) + $this->terminateGraceSeconds();
        $exitedPolitely = false;
        do {
            if (!proc_get_status($process)['running']) {
                $exitedPolitely = true;
                break;
            }
            usleep(5000);
        } while (microtime(true) < $graceDeadline);

        if (!$exitedPolitely) {
            proc_terminate($process, 9);
        }
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        proc_close($process);
        $elapsed = microtime(true) - $started;

        $this->assertFalse(
            $exitedPolitely,
            'the control fixture exited on SIGTERM with its stdin still open, so it does not '
            . 'trap the signal and the row above proves nothing about the EOF',
        );
        $this->assertGreaterThanOrEqual(
            $this->terminateGraceSeconds(),
            $elapsed,
            sprintf('the control paid only %.2fs of the grace', $elapsed),
        );
    }

    /**
     * Sits between the 0.010s a pipe-closing teardown measures and the 1.0s grace
     * a non-closing one pays in full.
     */
    private const EOF_EXIT_BOUND_SECONDS = 0.5;

    // =========================================================================
    // Fixtures and helpers
    // =========================================================================

    private function serverOver(string $script): StdioMcpServer
    {
        return new StdioMcpServer(
            name: 'bounds',
            command: PHP_BINARY,
            args: [$script],
            env: [],
            startTimeoutSeconds: 5.0,
        );
    }

    /**
     * Spawn the child and set the pipe modes WITHOUT the handshake.
     *
     * Every fixture here is deliberately unable to complete one — that is the
     * state under test — so `start()` would throw before the rows below could run.
     * The private helper is invoked instead, which is the same code path
     * `start()`'s first half is.
     */
    private function startProcessWithoutHandshake(StdioMcpServer $server): void
    {
        $open = new \ReflectionMethod($server, 'start');
        // `start()` itself would block on the handshake; the spawn is inlined here
        // instead, mirroring it line for line so a divergence is visible.
        $processProp = new \ReflectionProperty($server, 'process');
        $processProp->setAccessible(true);
        $pipesProp = new \ReflectionProperty($server, 'pipes');
        $pipesProp->setAccessible(true);
        $stderrOpenProp = new \ReflectionProperty($server, 'stderrOpen');
        $stderrOpenProp->setAccessible(true);

        $command = new \ReflectionProperty($server, 'command');
        $command->setAccessible(true);
        $args = new \ReflectionProperty($server, 'args');
        $args->setAccessible(true);

        $process = proc_open(
            [$command->getValue($server), ...array_values($args->getValue($server))],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process, 'could not spawn the fixture server');
        $this->assertSame(
            0,
            $open->getNumberOfParameters(),
            'start() grew a parameter — re-check that this helper still mirrors its spawn',
        );

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        stream_set_blocking($pipes[0], false);

        $processProp->setValue($server, $process);
        $pipesProp->setValue($server, $pipes);
        $stderrOpenProp->setValue($server, true);

        // Let the fixture reach its own read/sleep before anything is written.
        usleep(200000);
    }

    private function writeLine(StdioMcpServer $server, string $json, ?float $deadline): bool
    {
        $method = new \ReflectionMethod($server, 'writeLine');
        $method->setAccessible(true);

        return (bool) $method->invoke($server, $json, $deadline);
    }

    private function pidOf(StdioMcpServer $server): int
    {
        $property = new \ReflectionProperty($server, 'process');
        $property->setAccessible(true);

        return (int) proc_get_status($property->getValue($server))['pid'];
    }

    private function isAlive(int $pid): bool
    {
        // `/proc` rather than `posix_kill(0)`: the child is this process's own, so
        // a reaped zombie would still answer signal 0 until it is waited for.
        $status = @file_get_contents("/proc/$pid/stat");
        if (!is_string($status) || $status === '') {
            return false;
        }

        return !str_contains($status, ') Z ');
    }

    private function terminateGraceSeconds(): float
    {
        return (float) (new \ReflectionClass(StdioMcpServer::class))->getConstant('TERMINATE_GRACE_SECONDS');
    }

    /**
     * Reads nothing and says nothing, so the parent's stdin pipe fills and stays
     * full.
     *
     * ⚠️ IT MUST OUTLIVE {@see STORM_BOUND_SECONDS}, AND IT DID NOT. The first
     * version slept 30s against a 45s bound, so the `alive` storm row was
     * terminated by the FIXTURE'S OWN EXIT rather than by anything in the loop
     * under test — MEASURED by mutation: deleting the consecutive-failure
     * backstop left the row green and merely slowed the file from 10.6s to
     * 33.4s, which is the child's death arriving instead of the guard. The sleep
     * is now comfortably past the bound, so a loop with no exit is reported as a
     * timeout rather than rescued.
     */
    private function deafServerScript(): string
    {
        $path = $this->tempDir . '/deaf.php';
        file_put_contents($path, "<?php\nsleep(" . self::DEAF_SERVER_LIFETIME_SECONDS . ");\n");

        return $path;
    }

    /**
     * Twice {@see STORM_BOUND_SECONDS}, so the fixture cannot be the thing that
     * ends a storm row, and short enough that a probe killed by the bound leaves
     * an orphan for a bounded time rather than for the session.
     */
    private const DEAF_SERVER_LIFETIME_SECONDS = 90;

    /** Traps SIGTERM and leaves only on stdin EOF — the shape a real server has. */
    private function eofExitingServerScript(): string
    {
        $path = $this->tempDir . '/eof-exit.php';
        file_put_contents($path, self::EOF_EXITING_SERVER);

        return $path;
    }

    /** @return array{0: int, 1: string, 2: float} rc, stdout+stderr, elapsed */
    private function runStormProbe(string $mode): array
    {
        $probe = $this->tempDir . '/storm-' . $mode . '.php';
        file_put_contents($probe, sprintf(
            self::STORM_PROBE_TEMPLATE,
            var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true),
            var_export($this->deafServerScript(), true),
            var_export($mode, true),
            self::OVERSIZED_BYTES,
        ));

        return $this->runBounded([PHP_BINARY, $probe], self::STORM_BOUND_SECONDS);
    }

    /** @param array<int, string> $argv @return array{0: int, 1: string, 2: float} */
    private function runBounded(array $argv, float $budgetSeconds): array
    {
        $process = proc_open($argv, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        $this->assertIsResource($process, 'could not spawn the probe');

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $start = microtime(true);
        $deadline = $start + $budgetSeconds;
        $out = '';
        $timedOut = false;

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $out .= (string) stream_get_contents($pipes[2]);

            if (!proc_get_status($process)['running']) {
                break;
            }
            if (microtime(true) >= $deadline) {
                $timedOut = true;
                break;
            }
            usleep(5000);
        }

        $elapsed = microtime(true) - $start;

        if ($timedOut) {
            proc_terminate($process, 9);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $out .= (string) stream_get_contents($pipes[2]);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $rc = proc_close($process);

        return [$timedOut ? -1 : $rc, $out, $elapsed];
    }

    /**
     * Pull one `LABEL:<int>` line out of a probe's stdout, FAILING rather than
     * returning a sentinel when it is absent — a reader that answered 0 for "the
     * probe never said" turns a broken probe into a passing proof.
     */
    private function reportedInt(string $out, string $label): int
    {
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote($label, '/') . ':(\d+)$/m', $out, $m),
            "the probe did not report a $label:<int> line. Output: " . trim($out),
        );

        return (int) $m[1];
    }

    private function reportedFloat(string $out, string $label): float
    {
        $this->assertSame(
            1,
            preg_match('/^' . preg_quote($label, '/') . ':([0-9.]+)$/m', $out, $m),
            "the probe did not report a $label:<float> line. Output: " . trim($out),
        );

        return (float) $m[1];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeTree($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private const EOF_EXITING_SERVER = <<<'PHP'
        <?php
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void {});
        stream_set_blocking(STDIN, false);
        // The 20s valve is a leak guard, not part of any test: every row using
        // this fixture finishes in well under a second. It exits NON-ZERO there
        // so a row that accidentally depends on the valve cannot read as clean.
        $deadline = microtime(true) + 20.0;
        while (microtime(true) < $deadline) {
            $chunk = fread(STDIN, 8192);
            if ($chunk === false || ($chunk === '' && feof(STDIN))) {
                exit(0);
            }
            usleep(5000);
        }
        exit(1);
        PHP;

    /**
     * %s autoloader · %s deaf server script · %s mode · %d payload bytes.
     *
     * MODES. `alive` storms a live deaf child, so the loop walks the
     * consecutive-failure backstop. `dead` kills the child first, so the liveness
     * check is the exit. `control` runs NO storm, and exists to prove the other
     * two are measuring a storm and not an ordinary full pipe — it counts
     * `stream_select()` outcomes directly rather than trusting the fork.
     */
    private const STORM_PROBE_TEMPLATE = <<<'PHP'
        <?php
        require %s;
        $script = %s;
        $mode = %s;

        $server = new SugarCraft\Crush\MCP\StdioMcpServer(
            name: 'storm', command: PHP_BINARY, args: [$script], env: [], startTimeoutSeconds: 5.0,
        );
        $r = new ReflectionClass($server);
        $process = proc_open([PHP_BINARY, $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $pProp = $r->getProperty('process'); $pProp->setAccessible(true); $pProp->setValue($server, $process);
        $qProp = $r->getProperty('pipes');   $qProp->setAccessible(true); $qProp->setValue($server, $pipes);
        $sProp = $r->getProperty('stderrOpen'); $sProp->setAccessible(true); $sProp->setValue($server, true);
        usleep(200000);

        if ($mode === 'dead') {
            proc_terminate($process, 9);
            // Wait for the reap, so the liveness check has something to see.
            for ($i = 0; $i < 200 && proc_get_status($process)['running']; $i++) { usleep(5000); }
        }

        $storm = -1;
        if ($mode !== 'control') {
            pcntl_async_signals(false);
            pcntl_signal(SIGUSR1, static function (): void {});
            $parent = getmypid();
            $storm = pcntl_fork();
            if ($storm === 0) {
                $t = microtime(true);
                while (microtime(true) - $t < 120.0) { posix_kill($parent, SIGUSR1); usleep(300); }
                exit(0);
            }
        }

        if ($mode === 'control') {
            // Count select() outcomes on the same fd, with no storm running.
            $ok = 0; $fail = 0; $t = microtime(true);
            while (microtime(true) - $t < 0.3) {
                $w = [$pipes[0]]; $rd = []; $ex = [];
                if (@stream_select($rd, $w, $ex, 0, 20000) === false) { $fail++; } else { $ok++; }
            }
            echo 'SELECTOK:', $ok, "\n", 'SELECTFAIL:', $fail, "\n", 'RESULT:false', "\n";
            proc_terminate($process, 9);
            foreach ($pipes as $q) { if (is_resource($q)) fclose($q); }
            proc_close($process);
            exit(0);
        }

        $write = $r->getMethod('writeLine'); $write->setAccessible(true);
        $t0 = microtime(true);
        $result = $write->invoke($server, str_repeat('x', %d), null);
        printf("ELAPSED:%%.3f\n", microtime(true) - $t0);
        echo 'RESULT:', var_export($result, true), "\n";

        posix_kill($storm, 9); pcntl_waitpid($storm, $st);
        if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
        foreach ($pipes as $q) { if (is_resource($q)) fclose($q); }
        proc_close($process);
        PHP;
}
