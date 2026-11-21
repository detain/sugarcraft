<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Backend;

use SugarCraft\Async\ChildRun;
use SugarCraft\Code\Backend\CancellationToken;
use SugarCraft\Code\Backend\CommandBackend;
use SugarCraft\Code\Message;
use SugarCraft\Code\Support\ProcessContainment;
use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Proc\BoundedShutdown;

final class CommandBackendTest extends TestCase
{
    public function testCommandReceivesHistoryAsJsonOnStdin(): void
    {
        // `cat` echoes whatever it gets on stdin, which is the
        // JSON-encoded history. The reply will therefore be the
        // JSON itself — letting us assert the wire format.
        $backend = new CommandBackend(['cat']);
        $reply = $backend->complete([
            Message::user('hi'),
            Message::assistant('hello back'),
        ]);
        $decoded = json_decode($reply->content, true);
        $this->assertIsArray($decoded);
        $this->assertCount(2, $decoded);
        $this->assertSame('user',       $decoded[0]['role']);
        $this->assertSame('hi',         $decoded[0]['content']);
        $this->assertSame('assistant',  $decoded[1]['role']);
        $this->assertSame('hello back', $decoded[1]['content']);
    }

    public function testNonZeroExitReportedAsErrorMessage(): void
    {
        $backend = new CommandBackend(['false']);
        $reply = $backend->complete([Message::user('hi')]);
        $this->assertStringContainsString('exited 1', $reply->content);
    }

    public function testMissingCommandReportedGracefully(): void
    {
        $backend = new CommandBackend(['/nonexistent/command/path']);
        $reply = $backend->complete([Message::user('hi')]);
        $this->assertStringContainsString('error', strtolower($reply->content),
            'a non-existent command should produce an "[error: ...]" message, not crash');
    }

    /**
     * A reply that is the single character `0` is an answer, not an absence.
     *
     * `stream_get_contents()` returns `string|false`, and the read used to be
     * `stream_get_contents($pipes[1]) ?: ''` — under which `"0"` is falsy and
     * the whole reply became the empty string. `printf` rather than `echo`
     * deliberately: with a trailing newline the string is `"0\n"`, which is
     * truthy, and the bug hides. One character of data loss, on the path whose
     * docblock promises stdout back with one `trim()` and nothing else.
     */
    public function testAReplyOfExactlyZeroIsNotSwallowed(): void
    {
        $backend = new CommandBackend(['printf', '0']);

        $this->assertSame('0', $backend->complete([Message::user('hi')])->content);
    }

    /**
     * The same for stderr on the failure path: a stderr tail of `"0"` used to
     * be `?:`-flattened away, so the fenced hint the message promises was
     * silently omitted for it.
     */
    public function testAStderrTailOfExactlyZeroStillReachesTheErrorHint(): void
    {
        $backend = new CommandBackend('printf 0 >&2; exit 3');
        $content = $backend->complete([Message::user('hi')])->content;

        $this->assertStringContainsString('exited 3', $content);
        $this->assertStringContainsString("```\n0\n```", $content, 'the stderr tail was dropped for being "0"');
    }

    /**
     * The documented exception to "stdout comes back as-is": `trim()` at the
     * ends, which is deliberate (a wrapper's `echo` adds a newline nobody wants
     * rendered) but does reach INTO the reply's first line — a four-space
     * indent that made line one a code block is gone, while interior newlines,
     * blank lines and indents survive. Pinned so the docblock's claim and its
     * stated exception are both measured rather than asserted.
     */
    public function testStdoutSurvivesInsideAndIsTrimmedOnlyAtTheEnds(): void
    {
        $backend = new CommandBackend(['printf', "    indented\nline two\n\nline four\n"]);

        $this->assertSame("indented\nline two\n\nline four", $backend->complete([])->content);
    }

    /**
     * THE CLASSIC DOUBLE-DEADLOCK, ON THE BLOCKING PATH — where it was left
     * unfixed once and should not have been.
     *
     * `complete()` used to write the history with a single BLOCKING `fwrite()`
     * and only then `stream_get_contents()` the output. Hand that a history
     * bigger than the kernel's ~64K pipe buffer and a command that echoes its
     * input, and both sides park forever: the parent is blocked writing a full
     * stdin pipe, the child is blocked writing a full stdout pipe, and the only
     * process that could drain either is the one blocked on the other. Nothing
     * on this path ends it — there is no completion deadline here, deliberately.
     *
     * "Echoes its input" is the shape of every streaming wrapper AND of `cat`,
     * which this suite already points `$CANDY_CODE_BACKEND_CMD` at
     * ({@see \SugarCraft\Code\Tests\Cli\NonInteractiveProviderFailureTest},
     * {@see \SugarCraft\Code\Tests\Cli\BootstrapSpendAndSummaryTest}), so
     * this is not an exotic command. MEASURED against the code this replaced,
     * `cat` as the command: a 64 KB history returned, 130 KB returned, 200 KB
     * hung until an external `timeout` killed it at 10s. Reachable from the
     * `-p` one-shot path and from `BackgroundSessionRunner`.
     *
     * The fix is one blocking `stream_select()` over the write descriptor and
     * the two read descriptors together, so neither pipe can fill without the
     * other being emptied — and it keeps the property this path was chosen for,
     * which is that it parks in the kernel at 0% CPU rather than polling.
     * MEASURED with `getrusage()` against a wrapper that thinks for 2s:
     * 0.03% CPU before, 0.04% after (a 5ms poll loop over the same wrapper
     * costs 0.33%).
     *
     * Without the fix this test does not fail, it HANGS — a regression here
     * shows up as a wedged suite rather than a red one, which is worth knowing
     * before someone waits on it.
     */
    public function testCompleteDoesNotDeadlockAgainstACommandThatEchoesALargeHistory(): void
    {
        $huge = Message::user(str_repeat('x', 512 * 1024));

        // `cat`: every byte of the wire history comes straight back, so the
        // stdout pipe fills long before the parent has finished writing stdin.
        $echoed = (new CommandBackend(['cat']))->complete([$huge])->content;
        $this->assertSame(512 * 1024 + 30, strlen($echoed));

        // And the harder ordering: 4000 lines of output written BEFORE the
        // command reads a single byte of its stdin.
        $script = $this->writeScript(
            "#!/bin/bash\n"
            . "yes 0123456789012345678901234567890123456789012345678901234567890123 | head -n 4000\n"
            . "cat > /dev/null\n"
            . "printf 'done\\n'",
        );

        try {
            $message = (new CommandBackend($script))->complete([$huge]);

            $this->assertStringEndsWith("\ndone", $message->content);
            $this->assertSame(4000 * 65 + 4, strlen($message->content));
        } finally {
            unlink($script);
        }
    }

    // =========================================================================
    // completeAsync() Tests
    //
    // These used to settle SYNCHRONOUSLY — `then()` straight after the call and
    // read the value out of the closure, no loop run anywhere — and that was
    // not a testing shortcut, it was the defect. `completeAsync()` wrapped a
    // blocking `complete()` in a `React\Promise\Promise` whose executor runs
    // IMMEDIATELY, so the whole round-trip was over before the promise reached
    // the caller and a `$CANDY_CODE_BACKEND_CMD` user's terminal was frozen for
    // the duration of it. Every test below therefore has to drive the loop,
    // and `awaitPromise()` (critically) drains what it scheduled either way, so
    // nothing this class registers on the GLOBAL `Loop::get()` singleton can
    // dangle into an unrelated later test.
    // =========================================================================

    public function testCompleteAsyncReturnsPromise(): void
    {
        $backend = new CommandBackend(['cat']);
        $promise = $backend->completeAsync([Message::user('hello')]);

        $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);
        $this->awaitPromise($promise);
    }

    public function testCompleteAsyncResolvesToMessage(): void
    {
        $backend = new CommandBackend(['cat']);
        $resolved = $this->awaitPromise($backend->completeAsync([Message::user('test message')]));

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertSame(\SugarCraft\Code\Role::Assistant, $resolved->role);
        $this->assertStringContainsString('test message', $resolved->content);
    }

    public function testCompleteAsyncRejectsOnFailure(): void
    {
        $backend = new CommandBackend(['false']); // exits with code 1
        $resolved = $this->awaitPromise($backend->completeAsync([Message::user('hi')]));

        // completeAsync resolves, even on non-zero exit - the error is in the message content
        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertStringContainsString('backend exited 1', $resolved->content);
    }

    public function testCompleteAsyncWithArrayCommand(): void
    {
        $backend = new CommandBackend(['cat']);
        $resolved = $this->awaitPromise($backend->completeAsync([Message::user('array command test')]));

        $this->assertInstanceOf(Message::class, $resolved);
        $this->assertStringContainsString('array command test', $resolved->content);
    }

    /**
     * THE POINT OF THE WHOLE METHOD, and the one assertion that cannot be
     * satisfied by an implementation that merely finishes.
     *
     * A test that only awaits the promise passes whether the loop was free or
     * frozen — the work completes either way. So this one arms an independent
     * 20ms periodic timer standing in for the render tick, and counts how many
     * times it fires WHILE a 400ms command is in flight.
     *
     * MEASURED against the old implementation (`complete()` called straight
     * from the Promise executor): 0 ticks. Against this one: ~19. The floor is
     * deliberately far below that so the assertion is about the difference
     * between "the loop ran" and "the loop did not", not about scheduler
     * precision on a loaded box.
     *
     * The `$settledBeforeAnyoneRanTheLoop` half is the second signature of the
     * same defect: a synchronous executor resolves the promise before it is
     * even returned, so the flag was TRUE before this fix and must be FALSE
     * after it.
     */
    public function testCompleteAsyncLeavesTheEventLoopFreeWhileTheCommandRuns(): void
    {
        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 0.4\nprintf 'slow reply\\n'");

        try {
            $backend = new CommandBackend($script);
            $loop = \React\EventLoop\Loop::get();

            $ticks = 0;
            $ticker = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void { ++$ticks; });

            $promise = $backend->completeAsync([Message::user('hello')]);

            $settledBeforeAnyoneRanTheLoop = false;
            $promise->then(static function () use (&$settledBeforeAnyoneRanTheLoop): void {
                $settledBeforeAnyoneRanTheLoop = true;
            });
            $sawItSettleEarly = $settledBeforeAnyoneRanTheLoop;

            $message = $this->awaitPromise($promise);
            $loop->cancelTimer($ticker);

            $this->assertFalse($sawItSettleEarly, 'completeAsync() settled before the loop was ever run - it is still synchronous');
            $this->assertSame('slow reply', $message->content);
            $this->assertGreaterThan(5, $ticks, "the event loop ticked only {$ticks} times during a 400ms completion - it was blocked");
        } finally {
            unlink($script);
        }
    }

    /**
     * Cancellation has to be POLLED, not checked once before the spawn.
     * `Chat`'s double-Escape flips the shared token long after `completeAsync()`
     * has returned, and a check that only ran up front could never see it —
     * which is what the old implementation did, and it could not have done
     * otherwise: it never got back to the loop to look again.
     *
     * The fixture would take 5 seconds to answer. The assertion is that this
     * comes back in a fraction of that, so it cannot be satisfied by waiting
     * the child out and reporting a cancel afterwards.
     */
    public function testCompleteAsyncIsCancellableWhileTheCommandIsStillRunning(): void
    {
        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 5\nprintf 'too late\\n'");

        try {
            $backend = new CommandBackend($script);
            $cancellation = new CancellationToken();
            $loop = \React\EventLoop\Loop::get();

            $promise = $backend->completeAsync([Message::user('hello')], null, $cancellation);
            $flip = $loop->addTimer(0.1, static function () use ($cancellation): void { $cancellation->cancel(); });

            $started = microtime(true);
            $caught = null;

            try {
                $this->awaitPromise($promise);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                // RULE 39, NARROWED DELIBERATELY. awaitPromise() ends in
                // `$this->fail('Promise did not settle within the test
                // timeout')`, and that raises an AssertionFailedError - which a
                // bare `catch (\Throwable)` here captures into $caught and then
                // re-reports through the assertions below. MEASURED: the test
                // still goes RED either way (an AssertionFailedError is not a
                // RuntimeException), so this was never E546's silent-pass
                // shape; what it cost was the DIAGNOSTIC. A ten-second hang
                // came out as "failed asserting that ... is an instance of
                // RuntimeException", which names the wrong problem and sends
                // the reader to the wrong place. Rethrown so it arrives as
                // itself.
                throw $e;
            } catch (\Throwable $e) {
                $caught = $e;
            }

            $elapsed = microtime(true) - $started;
            $loop->cancelTimer($flip);

            $this->assertInstanceOf(\RuntimeException::class, $caught);
            $this->assertSame('Request cancelled', $caught->getMessage());
            $this->assertLessThan(2.0, $elapsed, "cancellation took {$elapsed}s - the child was waited out rather than aborted");
        } finally {
            unlink($script);
        }
    }

    /**
     * A cancel has to reach the grandchildren, not only the shell it started.
     *
     * The script backgrounds a `sleep` and writes that pid to a scratch file, so
     * the sleep is a grandchild of the PHP process. The kill ladder signals the
     * child's process group only when a containment prefix made the child a group
     * leader ({@see ProcessContainment::spawnSpec()}). The launch takes that group
     * from a snapshot at spawn, which can run before the setsid wrapper has become
     * a leader, so the snapshot can miss the group and only the shell dies while the
     * sleep is orphaned. The launch no longer depends on that one read: it latches
     * the group live while the child lives (ChildRunLaunch::latchLiveGroup()), and
     * once the shell has exited it falls back to the wrapper's own pid
     * (ChildRunLaunch::killGroup()). Whether a cancel lands before the group is
     * latched depends on timing, so this test is an end-to-end regression guard for
     * the cancel reaching the grandchild, not a deterministic pin of the missed-group
     * case. ChildRunLaunchTest pins that deterministically: it reaps the shell before
     * the launch is built, so only the wrapper-pid fallback can name the group.
     *
     * The cancel waits for the pid file rather than a fixed delay: a fixed delay
     * can fire before bash has started, and then no grandchild exists to check.
     */
    public function testCancelKillsTheGrandchildNotOnlyTheShell(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('The posix extension is needed to probe the grandchild pid.');
        }

        $pidFile = sys_get_temp_dir() . '/cmd_backend_grandchild_' . uniqid('', true) . '.pid';
        $body = <<<SH
        #!/bin/bash
        cat > /dev/null
        sleep 30 &
        echo \$! > '{$pidFile}.tmp' && mv '{$pidFile}.tmp' '{$pidFile}'
        wait
        printf 'too late\\n'
        SH;
        $script = $this->writeScript($body . "\n");
        $pid = 0;

        try {
            $backend = new CommandBackend($script);
            $cancellation = new CancellationToken();
            $loop = \React\EventLoop\Loop::get();

            $promise = $backend->completeAsync([Message::user('hello')], null, $cancellation);

            $armedAt = microtime(true);
            $flip = null;
            $flip = $loop->addPeriodicTimer(
                0.02,
                static function () use (&$flip, $loop, $cancellation, $pidFile, $armedAt): void {
                    // The 5 s ceiling only stops a script that never starts from hanging
                    // the test; the assertions below then report the missing pid.
                    if (is_file($pidFile) || microtime(true) - $armedAt > 5.0) {
                        $loop->cancelTimer($flip);
                        $cancellation->cancel();
                    }
                },
            );

            $caught = null;
            try {
                $this->awaitPromise($promise);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $caught = $e;
            } finally {
                // A failed await rethrows past the lines below; the flip must still stop.
                $loop->cancelTimer($flip);
            }

            $this->assertInstanceOf(\RuntimeException::class, $caught, 'the cancelled command did not reject');
            $this->assertSame('Request cancelled', $caught->getMessage());
            $this->assertFileExists($pidFile, 'the script never wrote the grandchild pid, so the cancel was not checked against a running grandchild');

            $pid = (int) trim((string) file_get_contents($pidFile));
            $this->assertGreaterThan(1, $pid, "the pid file held no usable pid: {$pid}");
            $this->assertTrue(
                self::waitUntilGone($pid, 2.0),
                "grandchild sleep (pid {$pid}) survived the cancel: the kill ladder reached the shell but not its process group",
            );
        } finally {
            if ($pid > 1 && self::isLive($pid)) {
                @posix_kill($pid, 9);
            }
            if (is_file($pidFile)) {
                unlink($pidFile);
            }
            @unlink($pidFile . '.tmp');
            unlink($script);
        }
    }

    /**
     * A cancel reaches a job the shell left behind after the shell itself exited.
     *
     * The script backgrounds a `sleep` (a grandchild that inherits the stdout pipe, so the
     * launch does not settle on the shell's exit), records its pid, then exits 0. The
     * cancel is issued only once the shell is gone, so the kill ladder has to find the
     * sleep through the containment group the wrapper led, not through the shell. The
     * launch path declares that wrapper to ChildRunLaunch via withSetsidWrapped(), and
     * the ladder reaches the group id of the exited wrapper from that declaration.
     *
     * Both pid files are waited for, and the cancel is held until the shell pid is no
     * longer live. The 5 s ceiling only stops a script that never starts from hanging the
     * test; the assertions below then report what was missing.
     */
    public function testCancelReachesTheJobOfAShellThatExitedBeforeTheCancel(): void
    {
        if (!\function_exists('posix_kill')) {
            $this->markTestSkipped('The posix extension is needed to probe the grandchild pid.');
        }

        $base = sys_get_temp_dir() . '/cmd_backend_exited_' . uniqid('', true);
        $grandFile = $base . '.grand';
        $shellFile = $base . '.shell';
        $body = <<<SH
        #!/bin/bash
        cat > /dev/null
        sleep 31 &
        echo \$! > '{$grandFile}.tmp' && mv '{$grandFile}.tmp' '{$grandFile}'
        echo \$\$ > '{$shellFile}.tmp' && mv '{$shellFile}.tmp' '{$shellFile}'
        exit 0
        SH;
        $script = $this->writeScript($body . "\n");
        $pid = 0;

        try {
            $backend = new CommandBackend($script);
            $cancellation = new CancellationToken();
            $loop = \React\EventLoop\Loop::get();

            $promise = $backend->completeAsync([Message::user('hello')], null, $cancellation);

            $armedAt = microtime(true);
            $shellGoneBeforeCancel = false;
            $flip = null;
            $flip = $loop->addPeriodicTimer(
                0.02,
                static function () use (&$flip, &$shellGoneBeforeCancel, $loop, $cancellation, $grandFile, $shellFile, $armedAt): void {
                    if (is_file($grandFile) && is_file($shellFile)) {
                        $shellPid = (int) trim((string) file_get_contents($shellFile));
                        $shellGoneBeforeCancel = $shellPid > 1 && !self::isLive($shellPid);
                    }
                    if ($shellGoneBeforeCancel || microtime(true) - $armedAt > 5.0) {
                        $loop->cancelTimer($flip);
                        $cancellation->cancel();
                    }
                },
            );

            $caught = null;
            try {
                $this->awaitPromise($promise);
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                throw $e;
            } catch (\Throwable $e) {
                $caught = $e;
            } finally {
                // A failed await rethrows past the lines below; the flip must still stop.
                $loop->cancelTimer($flip);
            }

            $this->assertTrue($shellGoneBeforeCancel, 'the shell was not seen to exit before the cancel, so the scenario did not run');
            $this->assertInstanceOf(\RuntimeException::class, $caught, 'the cancelled command did not reject');
            $this->assertSame('Request cancelled', $caught->getMessage());
            $this->assertFileExists($grandFile, 'the script never wrote the grandchild pid');

            $pid = (int) trim((string) file_get_contents($grandFile));
            $this->assertGreaterThan(1, $pid, "the pid file held no usable pid: {$pid}");
            $this->assertTrue(
                self::waitUntilGone($pid, 2.0),
                "grandchild sleep (pid {$pid}) survived the cancel after its shell exited: the kill ladder lost the group",
            );
        } finally {
            if ($pid > 1 && self::isLive($pid)) {
                @posix_kill($pid, 9);
            }
            foreach ([$grandFile, $shellFile] as $file) {
                @unlink($file);
                @unlink($file . '.tmp');
            }
            unlink($script);
        }
    }

    /**
     * Polls until $pid has exited, bounded by $seconds. True when it is gone.
     * A pid that has exited but not yet been reaped counts as gone: the test is
     * about where the kill landed, not about who reaps the orphan.
     */
    private static function waitUntilGone(int $pid, float $seconds): bool
    {
        return BoundedShutdown::waitBounded(static fn (): bool => !self::isLive($pid), $seconds);
    }

    /** Live means signalable and not a zombie. Reads /proc where it exists, `ps` elsewhere. */
    private static function isLive(int $pid): bool
    {
        if (!@posix_kill($pid, 0)) {
            return false;
        }

        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat !== false) {
            $after = strrpos($stat, ')');
            $state = $after === false ? '' : substr(trim(substr($stat, $after + 1)), 0, 1);

            return $state !== 'Z' && $state !== 'X';
        }

        // Stderr is read back with the output, not discarded: a missing ps then shows up as the
        // reply, which the first-character test below reads as live rather than hiding it.
        $state = trim((string) @shell_exec('ps -o stat= -p ' . $pid . ' 2>&1'));

        return $state !== '' && $state[0] !== 'Z';
    }

    /**
     * THE CLASSIC DOUBLE-DEADLOCK, and the reason stdin goes non-blocking here
     * and not just stdout.
     *
     * `complete()` writes the history with a single BLOCKING `fwrite()` before
     * it reads anything. Hand it a history bigger than the kernel's ~64K pipe
     * buffer, and a command that writes more than 64K of stdout BEFORE it reads
     * its stdin, and both sides park forever: we are blocked writing a full
     * stdin pipe, the child is blocked writing a full stdout pipe, and the only
     * process that could drain either one is the one blocked on the other. It
     * is not a slow case, it is unbounded — a child that exits would at least
     * hand the writer an EPIPE, and this one cannot exit.
     *
     * The async path writes a slice per tick from the same callback that drains
     * stdout, so neither pipe can fill without the other being emptied.
     *
     * Without that, this test does not fail — it HANGS, and `awaitPromise()`'s
     * 10s safety timer does NOT save it: that timer lives on the very loop the
     * `fwrite()` is blocking, so it never gets to fire. MEASURED by mutating
     * stdin back to blocking, this run had to be killed by an external
     * `timeout 60`. A regression here therefore shows up as a wedged suite
     * rather than a red one, which is worth knowing before someone waits on it.
     */
    public function testAHistoryLargerThanThePipeBufferDoesNotDeadlockAgainstTheCommandsOwnOutput(): void
    {
        $script = $this->writeScript(
            "#!/bin/bash\n"
            . "yes 0123456789012345678901234567890123456789012345678901234567890123 | head -n 4000\n"
            . "cat > /dev/null\n"
            . "printf 'done\\n'",
        );

        try {
            $backend = new CommandBackend($script);
            $huge = Message::user(str_repeat('x', 512 * 1024));

            $message = $this->awaitPromise($backend->completeAsync([$huge]));

            $this->assertStringEndsWith("\ndone", $message->content);
            // 4000 lines of 64 chars + \n, then "done\n", less the trailing
            // newline trim() takes: every byte the child wrote survived the
            // interleaved write, so nothing was lost to a full pipe either way.
            $this->assertSame(4000 * 65 + 4, strlen($message->content));
        } finally {
            unlink($script);
        }
    }

    /**
     * A string command on POSIX runs under `/bin/sh -c`, the shell proc_open() used
     * for a string before this backend ran on ChildRun.
     */
    public function testAStringCommandIsWrappedInBinShOnPosix(): void
    {
        $this->assertSame(
            ['/bin/sh', '-c', 'printf hi'],
            CommandBackend::shellSpec('printf hi', false),
        );
    }

    /**
     * The Windows arm hands a string command to proc_open() as the raw cmd.exe
     * line, unescaped. This is a deliberate change from the `COMSPEC /d /s /c`
     * argv CommandBackend used on master: PHP escapes an argv element by element
     * on Windows, and that escaping of the embedded double quotes is not what
     * cmd.exe reads. This runs on every platform: it pins the spawn-spec builder,
     * not the spawn.
     */
    public function testAStringCommandReachesProcOpenAsTheRawLineOnWindows(): void
    {
        $line = 'echo "a b" & exit /b 3';

        $this->assertSame($line, CommandBackend::shellSpec($line, true));
    }

    /**
     * shellSpec() returns the raw line, but the choice of ChildRun factory is made
     * one step later in childRun(). A string spec must reach ChildRun::commandLine()
     * and never ChildRun::new(), or PHP escapes the embedded quotes element by
     * element on Windows, which is the bug the raw line replaced.
     */
    public function testAStringSpecIsCarriedAsTheRawLineNotAnArgv(): void
    {
        $line = 'echo "a b" & exit /b 3';
        $run = CommandBackend::childRunForSpec($line, '');

        $this->assertSame([$line], $this->childRunProperty($run, 'argv'));
        $this->assertTrue($this->childRunProperty($run, 'commandLine'));
    }

    public function testAListSpecIsExecdAsAnArgvNotACommandLine(): void
    {
        $run = CommandBackend::childRunForSpec(['printf', 'a;b'], '');

        $this->assertSame(['printf', 'a;b'], $this->childRunProperty($run, 'argv'));
        $this->assertFalse($this->childRunProperty($run, 'commandLine'));
    }

    private function childRunProperty(ChildRun $run, string $name): mixed
    {
        return (new \ReflectionProperty(ChildRun::class, $name))->getValue($run);
    }

    /**
     * An empty string command is a no-op on both arms. The Windows arm used to
     * hand the empty line to ChildRun::commandLine(), which throws, so the
     * InvalidArgumentException escaped complete() and completeAsync() instead of
     * an assistant reply. Both now answer an empty reply without spawning. The
     * Windows arm cannot run on this host, so this pins the contract on every
     * platform rather than reproducing the throw.
     */
    public function testAnEmptyStringCommandAnswersAnEmptyReplyOnBothEntryPoints(): void
    {
        $backend = new CommandBackend('');

        $this->assertSame('', $backend->complete([Message::user('hi')])->content);
        $this->assertSame('', $this->awaitPromise($backend->completeAsync([Message::user('hi')]))->content);
    }

    public function testAListCommandIsExecdDirectlyOnEveryPlatform(): void
    {
        $this->assertSame(['printf', 'a;b'], CommandBackend::shellSpec(['printf', 'a;b'], false));
        $this->assertSame(['printf', 'a;b'], CommandBackend::shellSpec(['printf', 'a;b'], true));
    }

    /**
     * childRun() declares its child a setsid wrapper exactly when spawnSpec() put a
     * containment prefix in front of the plain spec. The launch path's cancel fallback
     * reads that flag, and only a shell that exits before the first status poll would
     * otherwise reach it, so this pins the wiring without spawning anything: no prefix
     * gives false, and a prefix gives true. On Windows detachedSpawnPrefix() is always
     * empty, so the same equality pins false there.
     */
    public function testChildRunDeclaresTheSetsidWrapperExactlyWhenAContainmentPrefixApplies(): void
    {
        ProcessContainment::setDetachedSpawnCandidatesForTesting([]);
        foreach (['printf hi', ['printf', 'a;b']] as $command) {
            $this->assertFalse(
                $this->childRunProperty(CommandBackend::childRun($command, ''), 'setsidWrapped'),
                'with no containment prefix the child is not a setsid wrapper',
            );
        }

        ProcessContainment::setDetachedSpawnCandidatesForTesting([[\PHP_BINARY, '-r', ProcessContainment::PHP_SETSID_STUB, '--']]);
        try {
            $expected = ProcessContainment::detachedSpawnPrefix() !== [];
            foreach (['printf hi', ['printf', 'a;b']] as $command) {
                $this->assertSame(
                    $expected,
                    $this->childRunProperty(CommandBackend::childRun($command, ''), 'setsidWrapped'),
                    'childRun() must declare the wrapper exactly when spawnSpec() prefixed the command',
                );
            }
        } finally {
            ProcessContainment::setDetachedSpawnCandidatesForTesting(null);
        }
    }

    private function writeScript(string $body): string
    {
        $script = sys_get_temp_dir() . '/cmd_backend_' . uniqid('', true) . '.sh';
        file_put_contents($script, $body);
        chmod($script, 0755);

        return $script;
    }

    /**
     * The drain timer must be CANCELLED when the promise settles, not merely
     * short-circuited by a `$settled` flag.
     *
     * A periodic timer left armed on the shared `Loop::get()` singleton is
     * almost invisible — every later tick returns immediately under the flag —
     * but it keeps the loop non-empty forever, so a `Loop::run()` that should
     * return the instant its work is done never returns at all, and every
     * completed turn adds another 200 wakeups a second for the rest of the
     * process's life.
     *
     * IN A SUBPROCESS, and deliberately. The only portable observable for "the
     * loop has nothing left" is `run()` returning on its own — and asserting
     * that in-process means either arming a guard timer, which is itself the
     * work being tested for, or hanging this suite when the assertion fails.
     * Reflecting into the loop's private timer collection was the third option
     * and was rejected: `ExtUvLoop` and `StreamSelectLoop` do not store timers
     * the same way, so the test would pass or fail on which extension is
     * installed. A child with its own `timeout` budget answers the question
     * exactly and cannot wedge anything.
     */
    public function testCompleteAsyncLeavesNoTimerBehindOnTheSharedLoop(): void
    {
        $probe = self::writeProbe(
            '<?php require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';'
            . ' $b = new \\' . CommandBackend::class . '([\'printf\', \'hi\']);'
            . ' $loop = \React\EventLoop\Loop::get();'
            . ' $b->completeAsync([\SugarCraft\Code\Message::user(\'hi\')])'
            . '   ->then(static function () { echo "SETTLED\n"; });'
            . ' $loop->run();'
            . ' echo "LOOP-RETURNED\n";',
        );

        try {
            $output = (string) shell_exec('timeout 10 ' . PHP_BINARY . ' ' . escapeshellarg($probe) . ' 2>&1');

            $this->assertStringContainsString('SETTLED', $output, "the probe never completed at all: {$output}");
            $this->assertStringContainsString(
                'LOOP-RETURNED',
                $output,
                'Loop::run() never returned after the promise settled - the drain timer was left armed on the shared loop',
            );
        } finally {
            unlink($probe);
        }
    }

    /** A PHP source file for the subprocess probe above to run. */
    private static function writeProbe(string $source): string
    {
        $probe = sys_get_temp_dir() . '/candy_code_loop_probe_' . uniqid('', true) . '.php';
        file_put_contents($probe, $source);

        return $probe;
    }

    /**
     * Single run()/stop() pair - see EngineBackendTest::awaitPromise()'s
     * docblock for why a repeated add-short-timer-then-run() polling dance is
     * fragile, and why leaving a scheduled callback un-drained on the shared
     * Loop::get() singleton corrupts unrelated later tests.
     */
    private function awaitPromise(\React\Promise\PromiseInterface $promise): mixed
    {
        $loop = \React\EventLoop\Loop::get();
        $settled = false;
        $value = null;
        $error = null;

        $promise->then(
            function ($v) use (&$settled, &$value, $loop): void { $settled = true; $value = $v; $loop->stop(); },
            function (\Throwable $e) use (&$settled, &$error, $loop): void { $settled = true; $error = $e; $loop->stop(); },
        );

        if (!$settled) {
            $safety = $loop->addTimer(10.0, static function () use ($loop): void { $loop->stop(); });
            $loop->run();
            $loop->cancelTimer($safety);
        }

        $this->assertTrue($settled, 'Promise did not settle within the test timeout');

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }
}
