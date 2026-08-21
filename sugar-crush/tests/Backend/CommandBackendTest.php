<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\CommandBackend;
use SugarCraft\Crush\Message;
use PHPUnit\Framework\TestCase;

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
     * which this suite already points `$SUGARCRUSH_BACKEND_CMD` at
     * ({@see \SugarCraft\Crush\Tests\Cli\NonInteractiveProviderFailureTest},
     * {@see \SugarCraft\Crush\Tests\Cli\BootstrapSpendAndSummaryTest}), so
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
    // the caller and a `$SUGARCRUSH_BACKEND_CMD` user's terminal was frozen for
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
        $this->assertSame(\SugarCraft\Crush\Role::Assistant, $resolved->role);
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
            . ' $b->completeAsync([\SugarCraft\Crush\Message::user(\'hi\')])'
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
        $probe = sys_get_temp_dir() . '/sugarcrush_loop_probe_' . uniqid('', true) . '.php';
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
