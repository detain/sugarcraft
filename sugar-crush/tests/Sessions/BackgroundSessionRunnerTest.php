<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Sessions;

use PHPUnit\Framework\TestCase;
use React\Promise\PromiseInterface;
use SugarCraft\Crush\Backend;
use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Chat;
use SugarCraft\Crush\Events\ToolFinished;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Permissions\DenialKind;
use SugarCraft\Crush\Sessions\BackgroundSessionRunner;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Tools\ToolResult;

/**
 * Covers the agent loop a `/bg` session actually runs.
 *
 * The pre-W3 daemon only answered HEARTBEAT/RESUME/STOP, so
 * `Backgrounded as <id>` described work that never happened; every
 * executeTask() assertion here fails against that daemon because it wrote
 * nothing but heartbeat records to the buffer.
 */
final class BackgroundSessionRunnerTest extends TestCase
{
    /** What {@see markerRun()} counts in a child's output; see E229 below. */
    private const MARKER = 'MARKER-FROM-PARENT';

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            @unlink($path);
        }
        $this->paths = [];
    }

    private function bufferPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'crush_bg_test_');
        $this->paths[] = $path;

        return $path;
    }

    private function runner(string $bufferPath, string $task = 'ship the thing'): BackgroundSessionRunner
    {
        return new BackgroundSessionRunner(
            sessionId: 'sess_test_1',
            socketPath: $bufferPath . '.sock',
            bufferPath: $bufferPath,
            task: $task,
        );
    }

    // =========================================================================
    // Stopping the worker. supervise() used to send SIGTERM and then call a
    // bare pcntl_waitpid(), which a worker that traps TERM never returns from.
    // =========================================================================

    /**
     * A worker that IGNORES SIGTERM is escalated to signal 9 and reaped.
     *
     * MEASURED against the bare wait this replaced: a forked child that
     * installs an empty SIGTERM handler and loops leaves
     * `pcntl_waitpid($pid, $status)` unreturned — `timeout 5 php` exits 124.
     *
     * The consequence was not confined to the daemon. The supervisor's only
     * completion signal is a DEAD PID
     * ({@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::reapFinishedDaemon()}),
     * so a `supervise()` parked in that wait means the session reports
     * "running" for the life of the process, the unix socket is never unlinked,
     * and both processes leak.
     *
     * Run in a CHILD PHP PROCESS under an external clock, for the reason
     * {@see \SugarCraft\Crush\Tests\Hooks\ScriptHookTest} runs its drain
     * cases there: the failure being pinned is a HANG, and an in-process
     * assertion after a hang is never reached — it takes the whole suite out on
     * whatever external timeout CI happens to have, naming no test.
     */
    public function testAWorkerThatIgnoresSigtermIsEscalatedAndReaped(): void
    {
        $report = $this->stopWorkerBounded(trapsTerm: true);

        $this->assertTrue($report['reaped'], 'the worker was left running after stopWorker() returned');
        $this->assertLessThan(20.0, $report['elapsed'], 'stopWorker() did not return in bounded time');
        $this->assertStringContainsString('[session:task:escalate]', $report['log']);
        $this->assertStringNotContainsString('[session:task:unreaped]', $report['log']);
    }

    /**
     * The ordinary worker — no handler, dies on the default disposition — is
     * reaped on SIGTERM alone, without paying the escalation grace.
     *
     * This is the case that actually runs in production, and it is the one an
     * escalation can most easily make worse: a `stopWorker()` that always slept
     * out its grace before checking would satisfy the test above and add a
     * two-second stall to every `/stop`.
     */
    public function testAnOrdinaryWorkerIsReapedOnSigtermWithoutTheEscalationGrace(): void
    {
        $report = $this->stopWorkerBounded(trapsTerm: false);

        $this->assertTrue($report['reaped']);
        $this->assertLessThan(
            BackgroundSessionRunner::TERMINATE_GRACE_SECONDS,
            $report['elapsed'],
            'a well-behaved worker waited out the grace meant for one that ignores TERM',
        );
        $this->assertStringNotContainsString('[session:task:escalate]', $report['log']);
    }

    /**
     * Fork a worker, ask {@see BackgroundSessionRunner::stopWorker()} to stop
     * it, and report how that went — all inside a child PHP process bounded by
     * an external clock.
     *
     * @return array{elapsed: float, reaped: bool, log: string}
     */
    private function stopWorkerBounded(bool $trapsTerm): array
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $buffer = $this->bufferPath();
        $ready = $buffer . '.ready';
        $this->paths[] = $ready;
        $trap = $trapsTerm ? 'true' : 'false';

        $script = <<<PHP
            <?php
            declare(strict_types=1);
            require {$this->export($autoload)};

            \$runner = new SugarCraft\Crush\Sessions\BackgroundSessionRunner(
                sessionId: 'sess_stop_1',
                socketPath: {$this->export($buffer . '.sock')},
                bufferPath: {$this->export($buffer)},
                task: 'ship the thing',
            );

            \$worker = pcntl_fork();
            if (\$worker === 0) {
                if ({$trap}) {
                    pcntl_async_signals(true);
                    pcntl_signal(SIGTERM, static function (): void {});
                }
                // Announced only AFTER the handler is installed: signalled any
                // earlier and the default disposition kills the worker, which
                // is the well-behaved case wearing the other case's name.
                file_put_contents({$this->export($ready)}, 'y');
                while (true) {
                    usleep(50000);
                }
            }

            // `!== 'y'` and not `=== ''`: the file does not exist yet, and
            // `file_get_contents()` answers FALSE for that — a test that read
            // false as "ready" signalled the worker before its handler was
            // installed and so measured the well-behaved case twice.
            while (@file_get_contents({$this->export($ready)}) !== 'y') {
                usleep(10000);
            }

            \$method = new ReflectionMethod(\$runner, 'stopWorker');
            \$started = microtime(true);
            \$method->invoke(\$runner, \$worker);
            \$elapsed = microtime(true) - \$started;

            \$status = 0;
            // -1 is "not ours any more", i.e. already reaped by stopWorker();
            // 0 would mean it is still running.
            \$reaped = pcntl_waitpid(\$worker, \$status, WNOHANG) !== 0;
            if (!\$reaped) {
                posix_kill(\$worker, 9);
                pcntl_waitpid(\$worker, \$status);
            }

            fwrite(STDOUT, json_encode([
                'elapsed' => \$elapsed,
                'reaped' => \$reaped,
                'log' => (string) @file_get_contents({$this->export($buffer)}),
            ]));
            PHP;

        $file = tempnam(sys_get_temp_dir(), 'bg_stop_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, $script);

        $decoded = json_decode($this->runBounded([PHP_BINARY, $file], 20.0), true);

        self::assertIsArray($decoded, 'the bounded child did not report a stopWorker() outcome');
        self::assertIsFloat($decoded['elapsed'] ?? null);
        self::assertIsBool($decoded['reaped'] ?? null);
        self::assertIsString($decoded['log'] ?? null);

        return $decoded;
    }

    /**
     * @param list<string> $argv
     */
    private function runBounded(array $argv, float $seconds): string
    {
        $process = proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $deadline = microtime(true) + $seconds;
        $out = '';
        $err = '';

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);

            if (proc_get_status($process)['running'] === false) {
                break;
            }

            if (microtime(true) >= $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                self::fail("stopWorker() did not finish within {$seconds}s — it wedged");
            }

            usleep(10_000);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        self::assertSame('', trim($err), 'the bounded child wrote to stderr');

        return $out;
    }

    private function export(string $value): string
    {
        return var_export($value, true);
    }

    public function testExecuteTaskWritesTheAssistantAnswerIntoTheBuffer(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $exit = $runner->executeTask(new FakeRunnerBackend('the answer'));

        $contents = (string) file_get_contents($buffer);
        $this->assertSame(0, $exit);
        $this->assertStringContainsString('[session:task:start]', $contents);
        $this->assertStringContainsString("the answer\n", $contents);
        $this->assertStringContainsString('[session:task:complete]', $contents);
    }

    public function testExecuteTaskSendsTheSpawnedTaskAsTheUserTurn(): void
    {
        $buffer = $this->bufferPath();
        $backend = new FakeRunnerBackend('ok');

        $this->runner($buffer, 'audit the CI matrix')->executeTask($backend);

        $this->assertCount(1, $backend->history);
        $this->assertSame('audit the CI matrix', $backend->history[0]->content);
    }

    public function testExecuteTaskFlushesStreamedOutputLineByLine(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $exit = $runner->executeTask(new FakeRunnerBackend("first\nsecond", streaming: true));

        $contents = (string) file_get_contents($buffer);
        $this->assertSame(0, $exit);
        // Streamed text is written once, not duplicated by a final write.
        $this->assertSame(1, substr_count($contents, 'first'));
        $this->assertSame(1, substr_count($contents, 'second'));
    }

    public function testExecuteTaskReportsFailureWhenTheBackendThrows(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $exit = $runner->executeTask(new FakeRunnerBackend('', throw: new \RuntimeException("boom\nsecond line")));

        $contents = (string) file_get_contents($buffer);
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[session:task:failed] boom second line', $contents);
        $this->assertStringNotContainsString('[session:task:complete]', $contents);
    }

    // =========================================================================
    // E229 — a forked child's plain exit() republishes the parent's OUTPUT
    // BUFFER. It is the one consequence of a plain exit that nothing in this
    // tree defuses, and run()'s worker was the last plain exit in src/ that
    // had never been argued for.
    // =========================================================================

    /**
     * The worker does not print the parent's buffered output a second time,
     * AND the session still settles as completed.
     *
     * BOTH HALVES, because the obvious fix breaks the second one.
     * {@see \SugarCraft\Crush\Support\ForkedChild::exitNow()} is this
     * codebase's answer for every other forked child, and it leaves through
     * `posix_kill(getmypid(), SIGKILL)` — so the worker is signalled rather
     * than exited and `pcntl_wifexited()` in `supervise()` is false. MEASURED
     * on PHP 8.3.6 by driving this very harness against a tree with that
     * conversion applied: the marker count drops to 1 and `run()` returns 1,
     * i.e. every background session that succeeded would report as failed. An
     * assertion on the marker alone would have accepted that.
     *
     * THE CONTROL RUNS THROUGH THE SAME HARNESS, in the same test, because a
     * count of 1 is also what a harness that lost its child, mis-spelled the
     * marker or never opened a buffer reports (rule 15/E228). The control is
     * the plain-exit shape this fix exists to avoid; it must report 2.
     *
     * Both run in a subprocess, so the demonstration's duplicate lands on a
     * pipe rather than on this suite's own stdout.
     */
    public function testTheWorkerNeitherRepublishesTheParentsBufferNorLosesItsExitCode(): void
    {
        $control = $this->markerRun($this->plainExitControlScript());
        $this->assertSame(
            2,
            $control['markers'],
            'the control did not reproduce the republish, so this harness cannot see one',
        );

        $site = $this->markerRun($this->runnerWorkerScript());
        $this->assertSame(1, $site['markers'], "the worker republished the parent's output buffer");
        $this->assertSame(0, $site['rc'], "the worker's exit code no longer reaches supervise()");
    }

    /**
     * The plain-exit shape, with no runner involved: a fork whose child leaves
     * through `exit()` while an output buffer holding the marker is open.
     */
    private function plainExitControlScript(): string
    {
        return <<<'PHP'
            <?php
            declare(strict_types=1);
            ob_start();
            echo MARKER_LINE;
            $pid = pcntl_fork();
            if ($pid === 0) {
                exit(0);
            }
            $status = 0;
            pcntl_waitpid($pid, $status);
            ob_end_flush();
            echo RC_ZERO_LINE;
            PHP;
    }

    /** The real {@see BackgroundSessionRunner::run()}, against a real socket. */
    private function runnerWorkerScript(): string
    {
        $autoload = \dirname(__DIR__, 2) . '/vendor/autoload.php';
        $buffer = $this->bufferPath();
        $socket = $buffer . '.sock';
        $this->paths[] = $socket;

        return <<<PHP
            <?php
            declare(strict_types=1);
            require {$this->export($autoload)};

            final class MarkerBackend implements SugarCraft\Crush\Backend
            {
                public function complete(array \$history, callable \$onToken = null, ?callable \$onEvent = null): SugarCraft\Crush\Message
                {
                    return SugarCraft\Crush\Message::assistant('answer');
                }

                public function completeAsync(array \$history, callable \$onToken = null, ?SugarCraft\Crush\Backend\CancellationToken \$cancellation = null, ?callable \$onEvent = null): React\Promise\PromiseInterface
                {
                    throw new LogicException('not used');
                }
            }

            // run() connects here, sends its HELLO and closes; supervise() then
            // unlinks this path and re-binds it as a server of its own.
            \$server = stream_socket_server('unix://' . {$this->export($socket)}, \$errno, \$errstr);
            if (\$server === false) {
                echo MARKER_LINE, 'RC=-1', PHP_EOL;
                exit(0);
            }

            \$runner = new SugarCraft\Crush\Sessions\BackgroundSessionRunner(
                sessionId: 'sess_e229',
                socketPath: {$this->export($socket)},
                bufferPath: {$this->export($buffer)},
                task: 'ship the thing',
                timeoutSeconds: 20,
            );

            ob_start();
            echo MARKER_LINE;
            \$rc = \$runner->run(new MarkerBackend());
            fclose(\$server);
            ob_end_flush();

            echo 'RC=', \$rc, PHP_EOL;
            PHP;
    }

    /**
     * Run one marker script in a bounded subprocess.
     *
     * The two constants are injected rather than written into each script:
     * the marker is what {@see markerRun()} counts, so a script and its
     * counter disagreeing about the spelling is a silent 0.
     *
     * @return array{markers: int, rc: int}
     */
    private function markerRun(string $script): array
    {
        $defines = "define('MARKER_LINE', " . $this->export(self::MARKER . "\n") . ");\n"
            . "define('RC_ZERO_LINE', 'RC=0' . PHP_EOL);\n";

        // AFTER the strict_types line, not before it: a declare() must be the
        // very first statement or PHP fatals before the script runs at all.
        $withDefines = preg_replace(
            '/^declare\(strict_types=1\);\R/m',
            "declare(strict_types=1);\n" . $defines,
            $script,
            1,
            $count,
        );
        self::assertSame(1, $count, 'the script carries no strict_types line to inject after');

        $file = tempnam(sys_get_temp_dir(), 'bg_e229_' . getmypid() . '_');
        self::assertIsString($file);
        $this->paths[] = $file;
        file_put_contents($file, (string) $withDefines);

        $out = $this->runBounded([PHP_BINARY, $file], 30.0);

        self::assertSame(1, preg_match('/^RC=(-?\d+)$/m', $out, $m), "no RC line in child output:\n" . $out);

        return ['markers' => substr_count($out, self::MARKER), 'rc' => (int) $m[1]];
    }

    // =========================================================================
    // E241 — a refused tool call reaches the operator. Round 48 closed this for
    // the `-p` one-shot path (E219) and left the daemon out, which is the
    // surface where "nobody saw it" is literally true: there is no operator in
    // front of a background session, and the buffer file is the whole record.
    // =========================================================================

    /**
     * The turn's observer is HANDED OVER, which is the half a buffer assertion
     * cannot prove on its own.
     *
     * `executeTask()` passed `complete()` two arguments and the third — the
     * tool-lifecycle observer — defaulted to null. A backend double that emits
     * nothing produces an identical (empty) buffer whether the argument is
     * there or not, so this asserts the argument itself.
     */
    public function testTheTurnIsGivenAToolLifecycleObserver(): void
    {
        $backend = new FakeRunnerBackend('done');

        $this->runner($this->bufferPath())->executeTask($backend);

        $this->assertTrue(
            $backend->sawEventObserver,
            'complete() was called without an $onEvent observer, so a refusal reaches nothing',
        );
    }

    public function testARefusedToolCallIsRecordedInTheSessionBuffer(): void
    {
        $buffer = $this->bufferPath();
        $backend = new FakeRunnerBackend('I could not remove it.', events: [
            new ToolFinished('call_1', 'Bash', new ToolResult('call_1', 'Hook denied: rm -rf is blocked', true)),
        ]);

        $exit = $this->runner($buffer)->executeTask($backend);
        $contents = (string) file_get_contents($buffer);

        $this->assertSame(0, $exit, 'a refusal is an event inside the turn, not a failed turn');
        $this->assertStringContainsString(
            BackgroundSessionRunner::REFUSAL_RECORD . ' [hook] Bash was not run - Hook denied: rm -rf is blocked',
            $contents,
        );
        // The answer is still the answer.
        $this->assertStringContainsString("I could not remove it.\n", $contents);
    }

    /**
     * KNOWN-POSITIVE, PER ROSTER ENTRY. The roster is
     * {@see \SugarCraft\Crush\Chat::DENIED_ERROR_PREFIXES}, which the daemon
     * READS rather than copies — so this fails the day an entry is added and
     * the daemon stops recognising it, which is the only way the daemon and
     * the `-p` path can drift apart on what a refusal is.
     */
    public function testEveryPrefixInTheSharedDenialRosterIsRecordedAsARefusal(): void
    {
        $this->assertNotSame([], Chat::DENIED_ERROR_PREFIXES, 'the shared roster is empty - the guard is dead');

        $tokensSeen = [];

        foreach (Chat::DENIED_ERROR_PREFIXES as $prefix) {
            $buffer = $this->bufferPath();
            $backend = new FakeRunnerBackend('ok', events: [
                new ToolFinished('c', 'Edit', new ToolResult('c', $prefix . ' because reasons', true)),
            ]);

            $this->runner($buffer)->executeTask($backend);

            // The kind is derived from the roster entry rather than spelled
            // beside it: a hard-coded expectation here would be a second copy
            // of DenialKind's own mapping, and would agree with itself rather
            // than with the enum.
            $kind = DenialKind::classify($prefix . ' because reasons');
            $this->assertInstanceOf(DenialKind::class, $kind, 'roster entry "' . $prefix . '" classifies as nothing');
            $tokensSeen[$kind->token()] = true;

            $this->assertStringContainsString(
                BackgroundSessionRunner::REFUSAL_RECORD . ' [' . $kind->token() . '] Edit was not run - '
                . $prefix . ' because reasons',
                (string) file_get_contents($buffer),
                'roster entry "' . $prefix . '" was not recognised by the daemon, or was recorded under the wrong kind',
            );
        }

        // WITHOUT THIS THE LOOP ABOVE IS SATISFIED BY ONE TOKEN (rule 25/E228).
        // Every assertion in it is derived from `$kind`, so a `noticeRefusal()`
        // that emitted a CONSTANT token and a `classify()` that answered a
        // constant kind would agree with each other all the way through. The
        // roster spans more than one kind, so the set of tokens the daemon
        // actually wrote must too.
        $this->assertGreaterThan(
            1,
            count($tokensSeen),
            'every roster prefix produced the same kind, so the loop above cannot tell a right token from a constant one',
        );
    }

    /**
     * KNOWN-NEGATIVE, WITH A POSITIVE IN THE SAME FIXTURE. A tool that RAN AND
     * FAILED is not a refusal, and neither is a tool that succeeded. Without
     * this the roster loop above is satisfied by a classifier that records
     * everything.
     *
     * THE ONE GENUINE REFUSAL IN THE LIST IS NOT DECORATION (rule 25/E228).
     * MEASURED, round 49: with `noticeRefusal()` mutated to `return;` on its
     * first line, the version of this test that asserted only the ABSENCE
     * passed — "no refusal record" is exactly what a dead classifier writes,
     * so the assertion could not tell a working guard from no guard at all.
     * The count below is what closes that: it dies when the classifier stops
     * recording (0 records) AND when it starts over-recording (2 or more), and
     * the tool name pins WHICH of the five events it picked.
     */
    public function testAToolThatRanAndFailedIsNotRecordedAsARefusal(): void
    {
        $buffer = $this->bufferPath();
        $backend = new FakeRunnerBackend('ok', events: [
            new ToolFinished('c1', 'Read', new ToolResult('c1', 'No such file or directory', true)),
            new ToolFinished('c2', 'Bash', new ToolResult('c2', 'exit status 0', false)),
            // A SUCCESSFUL call whose OUTPUT happens to open with a roster
            // phrase - a grep or a cat over a log full of them. Without the
            // isError() guard the classifier would report the tool that ran
            // as the tool that was stopped.
            new ToolFinished('c4', 'Grep', new ToolResult('c4', 'Permission denied: 3 matches in auth.log', false)),
            new ToolFinished('c3', 'Grep', new ToolResult('c3', 'the hook denied: lower case is not the prefix', true)),
            // Not a ToolFinished at all: the observer sees ToolStarted too.
            new \stdClass(),
            // THE POSITIVE COMPONENT: one real refusal, last, so it is also
            // the event a classifier that only ever looks at the first one
            // would miss.
            new ToolFinished('c5', 'Write', new ToolResult('c5', Chat::DENIED_ERROR_PREFIXES[0] . ' nope', true)),
        ]);

        $this->runner($buffer)->executeTask($backend);
        $contents = (string) file_get_contents($buffer);

        $records = array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => str_starts_with($line, BackgroundSessionRunner::REFUSAL_RECORD),
        ));

        $this->assertCount(
            1,
            $records,
            "exactly one of these six events is a refusal, and the buffer records " . count($records) . ":\n" . $contents,
        );
        $this->assertStringContainsString(
            ' Write was not run - ',
            $records[0],
            'the classifier recorded a refusal, but not the event that was one',
        );
    }

    /**
     * A refusal reason carrying a newline CANNOT inject text into the
     * transcript, and this is why the record is collapsed to one line.
     *
     * The buffer is a line protocol:
     * {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::restoreOutput()}
     * drops a line only when THAT LINE starts with `[session:`, so a two-line
     * record would have its first line dropped and its second restored as
     * model output — a hook author's error text quoted back to the user as if
     * the assistant had written it. Asserted through the supervisor's own
     * method rather than against a hand-rolled copy of the rule.
     */
    public function testAMultiLineRefusalReasonCannotReachTheRestoredTranscript(): void
    {
        $buffer = $this->bufferPath();
        $backend = new FakeRunnerBackend('the answer', events: [
            new ToolFinished('c', 'Bash', new ToolResult(
                'c',
                "Permission denied: rm\nINJECTED SECOND LINE\nand a third",
                true,
            )),
        ]);

        $this->runner($buffer)->executeTask($backend);
        $contents = (string) file_get_contents($buffer);

        $recordLines = array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => str_contains($line, 'INJECTED SECOND LINE'),
        );
        $this->assertCount(1, $recordLines, 'the reason was not collapsed onto one line');
        $this->assertStringStartsWith(BackgroundSessionRunner::REFUSAL_RECORD, (string) reset($recordLines));

        $restored = self::restoreOutputOf($contents);
        $this->assertSame("the answer\n", $restored);
    }

    /**
     * The record is BOOKKEEPING, not the turn's outcome — pinned against both
     * of the supervisor's own buffer readers.
     *
     * `restoreOutput()` must drop it (it opens `[session:`) so it is never
     * quoted back as model output, and a turn that refuses a call and then
     * answers must still settle as Completed.
     */
    public function testTheRefusalRecordIsBookkeepingAndDoesNotSettleTheSession(): void
    {
        $buffer = $this->bufferPath();
        $backend = new FakeRunnerBackend('the answer', events: [
            new ToolFinished('c', 'Bash', new ToolResult('c', 'Hook denied: no', true)),
        ]);

        $this->runner($buffer)->executeTask($backend);
        $contents = (string) file_get_contents($buffer);

        $this->assertStringContainsString(BackgroundSessionRunner::REFUSAL_RECORD, $contents);
        $this->assertSame("the answer\n", self::restoreOutputOf($contents));
        $this->assertFalse(
            self::bufferReportsFailureOf($contents),
            'a turn that refused a call and then answered was settled as a failure',
        );
    }

    /**
     * The record stays OUT of the namespace the outcome parser reads, and this
     * is the assertion that actually catches a rename — the one above does not.
     *
     * MEASURED (round 49, mutation M3): renaming the constant to
     * `[session:task:refused]` left the whole suite green, because every
     * assertion elsewhere is written against the constant and moves with it,
     * and because {@see \SugarCraft\Crush\Sessions\BackgroundSessionRunner::executeTask()}
     * writes its outcome line last, so the refusal never IS the last
     * `[session:task:` line today. So the danger is latent rather than live —
     * and it is exactly the kind that an ordering change makes live without
     * touching this file. The second half below shows what the parser does the
     * moment the ordering stops protecting it.
     */
    public function testTheRefusalRecordIsNotInTheOutcomeNamespace(): void
    {
        $this->assertStringStartsWith('[session:', BackgroundSessionRunner::REFUSAL_RECORD);
        $this->assertStringStartsNotWith(
            '[session:task:',
            BackgroundSessionRunner::REFUSAL_RECORD,
            'the refusal record is in the namespace bufferReportsFailure() settles sessions from',
        );

        // What the parser does with a refusal that arrives after the outcome,
        // under each spelling. This is the consequence the assertion above
        // buys, demonstrated rather than asserted about.
        $this->assertFalse(self::bufferReportsFailureOf(
            "[session:task:complete]\n" . BackgroundSessionRunner::REFUSAL_RECORD . " Bash was not run - Hook denied: no\n",
        ));
        $this->assertTrue(
            self::bufferReportsFailureOf("[session:task:complete]\n[session:task:refused] Bash was not run\n"),
            'the demonstration is vacuous: the task: spelling did not flip the outcome either',
        );
    }

    /**
     * KNOWN-ANSWER CONTROL for the two reflection probes above (rule 15): both
     * are private statics on another class, and a probe that silently stopped
     * reaching them would make every assertion built on it vacuous.
     */
    public function testTheSupervisorProbesUsedAboveStillReadWhatTheyClaimTo(): void
    {
        $this->assertSame(
            "plain\n",
            self::restoreOutputOf("[session:task:start]\nplain\n[session:heartbeat] pid=1\n"),
        );
        $this->assertFalse(self::bufferReportsFailureOf("[session:task:complete]\n"));
        $this->assertTrue(self::bufferReportsFailureOf("[session:task:failed] boom\n"));
        $this->assertTrue(self::bufferReportsFailureOf("plain output only\n"));
    }

    private static function restoreOutputOf(string $buffer): string
    {
        $m = new \ReflectionMethod(BackgroundSupervisor::class, 'restoreOutput');

        return (string) $m->invoke(null, $buffer);
    }

    private static function bufferReportsFailureOf(string $buffer): bool
    {
        $m = new \ReflectionMethod(BackgroundSupervisor::class, 'bufferReportsFailure');

        return (bool) $m->invoke(null, $buffer);
    }

    public function testServeClientAnswersHeartbeatAndReportsStatusOnResume(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);
        [$client, $daemon] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        fwrite($client, "HEARTBEAT\nRESUME\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);

        $stopped = $runner->serveClient($daemon, null);
        fclose($daemon);
        $reply = (string) stream_get_contents($client);
        fclose($client);

        $this->assertFalse($stopped);
        $this->assertStringContainsString('OK:session=sess_test_1', $reply);
        $this->assertStringContainsString('STATUS:running', $reply);
        $this->assertStringContainsString('[session:heartbeat]', (string) file_get_contents($buffer));
    }

    public function testServeClientReportsSettledResultOnResume(): void
    {
        $runner = $this->runner($this->bufferPath());
        [$client, $daemon] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        fwrite($client, "RESUME\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $runner->serveClient($daemon, 'completed');
        fclose($daemon);

        $this->assertStringContainsString('STATUS:completed', (string) stream_get_contents($client));
        fclose($client);
    }

    public function testServeClientReportsStopRequest(): void
    {
        $runner = $this->runner($this->bufferPath());
        [$client, $daemon] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);

        fwrite($client, "STOP\n");
        stream_socket_shutdown($client, STREAM_SHUT_WR);
        $stopped = $runner->serveClient($daemon, null);
        fclose($daemon);

        $this->assertTrue($stopped);
        $this->assertStringContainsString('OK:stopping', (string) stream_get_contents($client));
        fclose($client);
    }

    public function testRunFailsFastWhenTheSupervisorSocketIsGone(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $exit = $runner->run(new FakeRunnerBackend('unused'));

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('[session:connect:error]', (string) file_get_contents($buffer));
    }

    public function testAppendIgnoresEmptyWritesAndLogAddsALine(): void
    {
        $buffer = $this->bufferPath();
        $runner = $this->runner($buffer);

        $runner->append('');
        $this->assertSame('', (string) file_get_contents($buffer));

        $runner->log('[session:probe]');
        $this->assertSame("[session:probe]\n", (string) file_get_contents($buffer));
    }

    public function testFromConfigRoundTripsEveryField(): void
    {
        $runner = BackgroundSessionRunner::fromConfig([
            'sessionId' => 's1',
            'socketPath' => '/tmp/s1.sock',
            'bufferPath' => '/tmp/s1.buffer',
            'task' => 'do it',
            'workingDirectory' => '/tmp',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-6',
            'timeoutSeconds' => 42,
        ]);

        $this->assertSame('s1', $runner->sessionId);
        $this->assertSame('/tmp/s1.sock', $runner->socketPath);
        $this->assertSame('/tmp/s1.buffer', $runner->bufferPath);
        $this->assertSame('do it', $runner->task);
        $this->assertSame('/tmp', $runner->workingDirectory);
        $this->assertSame('anthropic', $runner->provider);
        $this->assertSame('claude-sonnet-4-6', $runner->model);
        $this->assertSame(42, $runner->timeoutSeconds);
    }

    public function testMainRejectsAnIncompleteConfig(): void
    {
        $this->assertSame(1, BackgroundSessionRunner::main(null));
        $this->assertSame(1, BackgroundSessionRunner::main(['sessionId' => 's1']));
    }

    public function testHeartbeatIntervalStaysUnderTheSupervisorTimeout(): void
    {
        $this->assertLessThan(
            \SugarCraft\Crush\Sessions\BackgroundSupervisor::HEARTBEAT_TIMEOUT_SECS,
            BackgroundSessionRunner::HEARTBEAT_INTERVAL_SECS,
        );
    }

    public function testBackendFallsBackWhenTheAgentProviderCannotBeBuilt(): void
    {
        $buffer = $this->bufferPath();
        $runner = new BackgroundSessionRunner(
            sessionId: 's1',
            socketPath: $buffer . '.sock',
            bufferPath: $buffer,
            task: 'x',
            provider: 'definitely-not-a-provider',
        );

        $previousCmd = getenv('SUGARCRUSH_BACKEND_CMD');
        $previousProvider = getenv('SUGARCRUSH_PROVIDER');
        putenv('SUGARCRUSH_PROVIDER=');
        putenv('SUGARCRUSH_BACKEND_CMD=printf hi');

        try {
            $backend = $runner->backend();
        } finally {
            putenv($previousCmd === false ? 'SUGARCRUSH_BACKEND_CMD' : 'SUGARCRUSH_BACKEND_CMD=' . $previousCmd);
            putenv($previousProvider === false ? 'SUGARCRUSH_PROVIDER' : 'SUGARCRUSH_PROVIDER=' . $previousProvider);
        }

        $this->assertInstanceOf(Backend::class, $backend);
        $this->assertStringContainsString('[session:provider:fallback]', (string) file_get_contents($buffer));
    }

    /**
     * An unusable permission policy is the one failure the provider fallback
     * cannot help with, and the catch-all used to blame the provider for it.
     *
     * The old `catch (\Throwable)` logged `[session:provider:fallback] <the
     * permission message>` and then called `Bootstrap::backend()`, which
     * builds the very same gate from the very same config and throws the very
     * same exception one line later — so the session died anyway, having first
     * written a log line that sends the reader to the wrong file. Rethrowing
     * matches the arm `NonInteractive::run()` and `Bootstrap::backend()`
     * already carry: the caller reports it as a task failure with the real
     * reason.
     */
    public function testAnUnusablePermissionPolicyIsNotReportedAsAProviderFallback(): void
    {
        $home = sys_get_temp_dir() . '/crush_bg_home_' . uniqid('', true);
        mkdir($home . '/.sugar-crush', 0700, true);
        file_put_contents($home . '/.sugar-crush/config.json', '{"permissionMode":"plan",}');

        $buffer = $this->bufferPath();
        $runner = new BackgroundSessionRunner(
            sessionId: 's1',
            socketPath: $buffer . '.sock',
            bufferPath: $buffer,
            task: 'x',
            provider: 'custom',
        );

        $previousHome = getenv('HOME');
        $previousKey = getenv('CUSTOM_API_KEY');
        putenv('HOME=' . $home);
        putenv('CUSTOM_API_KEY=k');
        // BOTH forms are redirected, because half a sandbox is not a sandbox:
        // {@see \SugarCraft\Crush\Support\HomeDirectory} reads `getenv()`,
        // and anything still holding a `$_SERVER['HOME']` copy (a nested
        // process, a library) must not be left pointing at the real home.
        $previousServerHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $home;

        try {
            $status = $runner->executeTask();
        } finally {
            putenv($previousHome === false ? 'HOME' : 'HOME=' . $previousHome);
            putenv($previousKey === false ? 'CUSTOM_API_KEY' : 'CUSTOM_API_KEY=' . $previousKey);
            if ($previousServerHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $previousServerHome;
            }
            @unlink($home . '/.sugar-crush/config.json');
            @rmdir($home . '/.sugar-crush');
            @rmdir($home);
        }

        $log = (string) file_get_contents($buffer);

        $this->assertSame(1, $status);
        $this->assertStringNotContainsString('[session:provider:fallback]', $log);
        $this->assertStringContainsString('[session:task:failed]', $log);
        $this->assertStringContainsString('not usable JSON', $log);
    }
}

/**
 * Backend double: records the history it was asked to complete, optionally
 * streams the reply, optionally throws.
 */
final class FakeRunnerBackend implements Backend
{
    /** @var list<Message> */
    public array $history = [];

    /** Whether complete() was handed a tool-lifecycle observer at all. */
    public bool $sawEventObserver = false;

    /**
     * @param list<object> $events emitted through $onEvent before the reply
     */
    public function __construct(
        private readonly string $reply,
        private readonly bool $streaming = false,
        private readonly ?\Throwable $throw = null,
        private readonly array $events = [],
    ) {}

    public function complete(array $history, callable $onToken = null, ?callable $onEvent = null): Message
    {
        $this->history = $history;
        $this->sawEventObserver = $onEvent !== null;

        if ($onEvent !== null) {
            foreach ($this->events as $event) {
                $onEvent($event);
            }
        }

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->streaming && $onToken !== null) {
            foreach (str_split($this->reply, 3) as $chunk) {
                $onToken($chunk);
            }
        }

        return Message::assistant($this->reply);
    }

    public function completeAsync(array $history, callable $onToken = null, ?CancellationToken $cancellation = null, ?callable $onEvent = null): PromiseInterface
    {
        throw new \LogicException('not used by the background runner');
    }
}
