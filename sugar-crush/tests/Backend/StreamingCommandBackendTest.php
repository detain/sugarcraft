<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use SugarCraft\Crush\Backend\StreamingCommandBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use PHPUnit\Framework\TestCase;

final class StreamingCommandBackendTest extends TestCase
{
    public function testStreamingBackendCallsOnTokenForEachLine(): void
    {
        // Create a script that outputs tokens line by line
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'Hello'\necho ' '\necho 'World!'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $tokens = [];
            $onToken = function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            };

            $result = $backend->complete([], $onToken);

            $this->assertSame(['Hello', ' ', 'World!'], $tokens);
            $this->assertSame(Role::Assistant, $result->role);
            $this->assertSame('Hello World!', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendWithoutCallback(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'No callback test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $result = $backend->complete([], null);

            $this->assertSame(Role::Assistant, $result->role);
            $this->assertSame('No callback test', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendReportsErrorOnNonZeroExit(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'partial output'\nexit 1");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $result = $backend->complete([], null);

            $this->assertSame(Role::Assistant, $result->role);
            $this->assertStringContainsString('error', $result->content);
            $this->assertStringContainsString('1', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendReportsErrorOnMissingCommand(): void
    {
        $backend = new StreamingCommandBackend(['/nonexistent/command/path']);
        $result = $backend->complete([], null);

        $this->assertSame(Role::Assistant, $result->role);
        $this->assertStringContainsString('error', $result->content);
    }

    public function testStreamingBackendPassesHistoryToStdin(): void
    {
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        // Script reads stdin and includes it in output
        file_put_contents($script, "#!/bin/bash\ncat > /dev/null && echo 'received history'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $history = [
                Message::user('Hello'),
                Message::assistant('Hi there!'),
            ];
            $result = $backend->complete($history, null);

            $this->assertSame('received history', $result->content);
        } finally {
            unlink($script);
        }
    }

    public function testStreamingBackendHandlesMultipleRapidTokens(): void
    {
        // Generate tokens quickly to test buffering - use fewer tokens for stability
        $tokens = [];
        for ($i = 0; $i < 50; $i++) {
            $tokens[] = "token{$i}";
        }
        // Build script: each echo on its own line, properly terminated
        $lines = ["#!/bin/bash"];
        foreach ($tokens as $token) {
            $lines[] = "echo {$token}";
        }
        $lines[] = "true";
        $scriptContent = implode("\n", $lines);

        $script = sys_get_temp_dir() . '/stream_test_' . uniqid() . '.sh';
        file_put_contents($script, $scriptContent);
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $receivedTokens = [];
            $onToken = function (string $token) use (&$receivedTokens): void {
                $receivedTokens[] = $token;
            };

            $result = $backend->complete([], $onToken);

            $this->assertCount(50, $receivedTokens);
            $this->assertSame(implode('', $tokens), $result->content);
        } finally {
            unlink($script);
        }
    }

    // =========================================================================
    // The deadline (crush_code.md Phase 2 item 8)
    //
    // This class shipped with `int $timeout = 120`, armed ONCE as
    // `$deadline = time() + $this->timeout`, so a completion that ran past two
    // minutes was SIGTERMed mid-answer — on a path whose whole job is to
    // delegate to a model that may legitimately think for tens of minutes, and
    // whose sibling CommandBackend caps nothing at all. The cap is gone; what
    // replaced it is an OPT-IN idle deadline, and the tests below pin the
    // difference rather than the presence of a parameter.
    // =========================================================================

    /**
     * The default is no deadline, pinned in two halves because neither half is
     * the claim on its own: the DEFAULT VALUE is 0 (reflection — a 120 restored
     * here would red this immediately), and 0 MEANS "no deadline" (behaviour —
     * a wrapper silent for longer than any plausible small cap still answers).
     *
     * Deliberately not "sleep past 120 seconds and assert it still returns":
     * that test would take 120 seconds, and a two-minute test is a test that
     * gets marked slow and then skipped.
     */
    public function testTheIdleDeadlineDefaultsToNoneAndZeroMeansNone(): void
    {
        $default = (new \ReflectionMethod(StreamingCommandBackend::class, '__construct'))
            ->getParameters()[1];

        $this->assertSame('idleTimeout', $default->getName());
        $this->assertTrue($default->isDefaultValueAvailable());
        $this->assertSame(0, $default->getDefaultValue(), 'the shell-out tier caps nothing by default');

        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 2\necho 'answer after a long silence'");

        try {
            $result = (new StreamingCommandBackend($script))->complete([], null);

            $this->assertSame('answer after a long silence', $result->content);
        } finally {
            unlink($script);
        }
    }

    /**
     * IDLE, NOT TOTAL — the assertion the whole change turns on. The command
     * runs for ~2 seconds, twice the 1-second deadline, but never goes quiet
     * for a whole second, so it must finish and return everything.
     *
     * Restore `$deadline = time() + $this->timeout` and this test reports the
     * timeout message instead of the six tokens. That is the mutation it
     * exists to kill, and the reason the deadline is a constructor parameter
     * at all: with a 120-second constant the same assertion needs a
     * four-minute test.
     */
    public function testALongRunningCommandThatKeepsEmittingIsNotKilledByTheIdleDeadline(): void
    {
        // Single-quoted: `$i` belongs to bash, not to PHP.
        $script = $this->writeScript(
            "#!/bin/bash\ncat > /dev/null\n" . 'for i in 1 2 3 4 5 6; do echo "tok$i"; sleep 0.3; done' . "\n",
        );

        try {
            $started = microtime(true);
            $result = (new StreamingCommandBackend($script, idleTimeout: 1))->complete([], null);
            $elapsed = microtime(true) - $started;

            $this->assertSame('tok1tok2tok3tok4tok5tok6', $result->content);
            // 1.5, not 1.0. The clock is `microtime(true)`, so a total deadline
            // of 1 can only fire just after 1.0s — `> 1.0` would therefore be
            // satisfied by a run the mutation had already cut short. The fixture
            // sleeps 6 × 0.3s, so 1.5 is a floor it clears by ~0.3s while still
            // leaving 0.5s of margin over the deadline for scheduling jitter.
            // (Under the old `time()` clock the deadline could fire anywhere in
            // (1.0, 2.0], which is why this guard could not be tightened before.)
            $this->assertGreaterThan(
                1.5,
                $elapsed,
                'the fixture has to outlive the 1s deadline by a clear margin or it proves nothing',
            );
        } finally {
            unlink($script);
        }
    }

    /**
     * And the deadline still bites when it is SILENCE rather than elapsed
     * time, so the test above is not passing because the mechanism is dead.
     *
     * The message names SECONDS. The one this replaced said "timed out after
     * {$iterations} iterations" — a loop counter reported to someone who had
     * configured seconds. It also names THE PIPES rather than the command: the
     * silent party may be a descendant that inherited them, and "the command
     * produced no output" is false in that case
     * ({@see testAnIdleExpiryReturnsTheTokensItAlreadyStreamed()}).
     */
    public function testASilentCommandTripsTheOptedInIdleDeadlineAndTheMessageNamesSeconds(): void
    {
        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 30\necho 'never reached'");

        try {
            $result = (new StreamingCommandBackend($script, idleTimeout: 1))->complete([], null);

            $this->assertSame(
                "_[error: no output on the streaming backend's pipes for more than 1s]_",
                $result->content,
            );
            $this->assertStringNotContainsString('iteration', $result->content);
        } finally {
            unlink($script);
        }
    }

    /**
     * A string command still reaches the shell, which is what makes a pipeline
     * wrapper (`curl … | jq -r …`) usable here at all.
     *
     * The spawn passes NO `proc_open` options, for either shape — same as
     * {@see \SugarCraft\Crush\Backend\CommandBackend}. It briefly passed
     * `['bypass_shell' => true]` for a LIST only, and that branch is gone
     * because it was pinned by nothing and could not be: MEASURED on PHP
     * 8.3/Linux the option is inert for both shapes, and a list needs no such
     * option in the first place — PHP opens an array command directly, without
     * a shell, and escapes the arguments itself. This test and the one below
     * assert the shell/no-shell property itself on the platform the suite runs
     * on, which is the only thing either of them can honestly claim.
     */
    public function testAStringCommandIsStillInterpretedByTheShell(): void
    {
        $backend = new StreamingCommandBackend('cat > /dev/null; echo piped');

        $this->assertSame('piped', $backend->complete([], null)->content);
    }

    /**
     * And a LIST command is exec'd directly rather than parsed by a shell —
     * the "avoid shell escaping concerns" promise the constructor makes.
     */
    public function testAListCommandIsNotParsedByAShell(): void
    {
        $backend = new StreamingCommandBackend(['printf', '%s\n', 'a;b']);

        $this->assertSame('a;b', $backend->complete([], null)->content);
    }

    /**
     * The stdout contract itself, pinned as bytes at the class boundary rather
     * than only at the Bootstrap tier
     * ({@see \SugarCraft\Crush\Tests\Cli\BootstrapShellOutTierTest}): one
     * TERMINATED line is one token, AN EMPTY LINE IS A LITERAL NEWLINE, and the
     * tokens are joined with NOTHING. It is stated here so that anyone tempted
     * to make this class "also handle prose" sees what they are changing.
     *
     * The empty line used to be dropped, and that is the mutation this test
     * exists to kill: with it dropped the body is `onetwothree` and the class
     * cannot return a `\n` for ANY input whatsoever, so the protocol cannot
     * express a list, a paragraph break or a code fence. The assertion is on
     * the newline's PRESENCE in the body, not merely on a token count.
     */
    public function testTheStdoutContractIsOneTokenPerLineWithAnEmptyLineMeaningANewline(): void
    {
        $script = $this->writeScript(
            "#!/bin/bash\ncat > /dev/null\nprintf 'one\\ntwo\\n\\nthree\\n'",
        );

        try {
            $tokens = [];
            $result = (new StreamingCommandBackend($script))->complete(
                [],
                function (string $token) use (&$tokens): void { $tokens[] = $token; },
            );

            $this->assertSame(
                ['one', 'two', "\n"],
                array_slice($tokens, 0, 3),
                'the blank line is delivered as a newline token, not dropped',
            );
            $this->assertSame(['one', 'two', "\n", 'three'], $tokens);
            $this->assertSame("onetwo\nthree", $result->content);
            $this->assertStringContainsString(
                "\n",
                $result->content,
                'a body with no newline in it means the protocol cannot express one at all',
            );
        } finally {
            unlink($script);
        }
    }

    /**
     * The expiry path REAPS the child. It used to `proc_terminate()` and
     * `return`, which left the process unwaited.
     *
     * WHAT WAS ACTUALLY LEAKING, measured rather than assumed — the first
     * version of this test counted `/proc/self/fd` and PASSED with the fix
     * removed, because freeing the local `$pipes` array on return closes the
     * pipes for you and the descriptor count never moved. The PROCESS is the
     * leak: own-zombie count went 0 → 1 → 2 → 3 across three expiries without
     * the fix and did not move with it. So this counts zombies whose parent is
     * this pid, which is the census that actually distinguishes the two
     * versions.
     *
     * A DELTA, not an absolute zero: this suite forks elsewhere
     * ({@see \SugarCraft\Crush\Tests\Backend\EngineBackendReapTest}), so a
     * zombie left by an unrelated test would make `assertSame(0, …)` red here
     * and name the wrong file for it.
     */
    public function testAnExpiredCommandIsReapedRatherThanLeftAsAZombie(): void
    {
        if (!is_dir('/proc/self')) {
            $this->markTestSkipped('the zombie census needs a Linux-shaped /proc');
        }

        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 30\necho 'never reached'");
        $backend = new StreamingCommandBackend($script, idleTimeout: 1);

        try {
            $before = self::ownZombieCount();

            $backend->complete([], null);
            $backend->complete([], null);

            $this->assertSame(
                $before,
                self::ownZombieCount(),
                'two expired streaming commands were signalled but never waited for',
            );
        } finally {
            unlink($script);
        }
    }

    /**
     * Processes in state `Z` whose ppid is this process — i.e. children this
     * process spawned and never reaped. Read from `/proc/<pid>/stat`, whose
     * third field is the state and fourth the ppid, counted from AFTER the
     * `comm` field so a command name containing `) ` cannot shift the offsets.
     */
    private static function ownZombieCount(): int
    {
        $count = 0;

        foreach ((array) glob('/proc/[0-9]*/stat') as $file) {
            $stat = @file_get_contents((string) $file);
            if ($stat === false) {
                continue;
            }
            $tail = explode(') ', $stat, 2);
            if (count($tail) < 2) {
                continue;
            }
            $fields = explode(' ', $tail[1]);
            if (($fields[0] ?? '') === 'Z' && (int) ($fields[1] ?? 0) === getmypid()) {
                ++$count;
            }
        }

        return $count;
    }

    // =========================================================================
    // One token per LINE, not per read
    //
    // The reads are non-blocking, so `fgets()`/`fread()` hand back whatever
    // bytes have arrived — which for one line of a slow wrapper was measured as
    // ten separate `$onToken` calls, and (the correctness half) as a DIFFERENT
    // BODY for identical stdout bytes depending on where the boundary landed.
    // =========================================================================

    /**
     * A line is not a token until its newline has arrived. The fixture writes
     * `par`, sleeps long enough that the read loop is guaranteed to have seen
     * it, then writes `tial\n`.
     *
     * Buffer the partial away and this is ONE token, `partial`. Emit per read
     * and it is two, `par` and `tial` — which is what the class did, and what
     * made "one token per line" a claim the code did not keep.
     */
    public function testAPartialLineIsNotEmittedUntilItsNewlineArrives(): void
    {
        $script = $this->writeScript(
            "#!/bin/bash\ncat > /dev/null\nprintf 'par'\nsleep 0.3\nprintf 'tial\\n'\n",
        );

        try {
            $tokens = [];
            $result = (new StreamingCommandBackend($script))->complete(
                [],
                function (string $token) use (&$tokens): void { $tokens[] = $token; },
            );

            $this->assertSame(['partial'], $tokens, 'a line without its newline is not a token yet');
            $this->assertSame('partial', $result->content);
        } finally {
            unlink($script);
        }
    }

    /**
     * THE CORRECTNESS HALF, and the claim the implementer's own report got
     * wrong ("display-granularity only; the joined body is unaffected").
     *
     * `a\rb\n` is one line containing an INTERIOR carriage return. Read whole,
     * `rtrim($line, "\r\n")` leaves `a\rb`; read as `a\r` then `b\n` — which is
     * what a wrapper writing in two syscalls produces — the same `rtrim` eats
     * the `\r` as if it were a line terminator and the body becomes `ab`. The
     * sleep forces the boundary onto the `\r`, so identical stdout bytes are
     * driven down the worse path deliberately.
     */
    public function testAnInteriorCarriageReturnSurvivesAReadBoundaryLandingOnIt(): void
    {
        $script = $this->writeScript(
            "#!/bin/bash\ncat > /dev/null\nprintf 'a\\r'\nsleep 0.3\nprintf 'b\\n'\n",
        );

        try {
            $tokens = [];
            $result = (new StreamingCommandBackend($script))->complete(
                [],
                function (string $token) use (&$tokens): void { $tokens[] = $token; },
            );

            $this->assertSame(["a\rb"], $tokens);
            $this->assertSame("a\rb", $result->content, 'the read boundary must not decide the bytes');
        } finally {
            unlink($script);
        }
    }

    // =========================================================================
    // Bounds after the child is gone
    // =========================================================================

    /**
     * A DESCENDANT holding the inherited stdout open after the command itself
     * exits is the state the drain loop had no exit from: `proc_get_status()`
     * says not running, `feof()` stays false, so the `break` can never fire —
     * and with the sleep guarded on `&& $running` there was not even a yield,
     * so the loop span at 100% CPU with the ReactPHP loop blocked. At HEAD a
     * 120-second total cap bounded it; the opt-in idle deadline defaults to 0
     * and Bootstrap passes nothing, so nothing bounded it at all.
     *
     * Both halves are asserted, because each has its own mutation:
     *
     *   - restore `&& $running` on the sleep and the CPU assertion reds (the
     *     work is done in THIS process, so `getrusage()` sees it);
     *   - drop the post-exit grace and the elapsed assertion reds — the loop
     *     then runs until the descendant exits on its own, at ~5s.
     *
     * The tokens the command did produce come back either way: a grandchild
     * that will not let go of a pipe is not a reason to discard an answer.
     */
    public function testADescendantHoldingThePipeOpenIsAbandonedWithoutSpinning(): void
    {
        $script = $this->writeScript(
            "#!/bin/bash\ncat > /dev/null\n( sleep 5 ) &\nprintf 'tok\\n'\nexit 0\n",
        );

        try {
            $cpuBefore = self::cpuSeconds();
            $started = microtime(true);
            $result = (new StreamingCommandBackend($script))->complete([], null);
            $elapsed = microtime(true) - $started;
            $cpu = self::cpuSeconds() - $cpuBefore;

            $this->assertStringStartsWith('tok', $result->content, 'what was read is returned');
            $this->assertStringContainsString('notice', $result->content, 'and the truncation is stated');
            $this->assertLessThan(
                4.0,
                $elapsed,
                'the post-exit grace has to end the drain, or the loop waits out the grandchild',
            );
            $this->assertLessThan(
                0.5,
                $cpu,
                'the no-progress iteration must yield: ' . sprintf('%.2fs CPU over %.2fs wall', $cpu, $elapsed),
            );
        } finally {
            unlink($script);
        }
    }

    /**
     * The expiry path returns in bounded time even when the child ignores
     * SIGTERM. `proc_terminate()` then `proc_close()` — and `proc_close()`
     * WAITS — measured 8.00s for a ONE-second idle deadline against this exact
     * command; with the escalation to signal 9 it is ~2.0s (the deadline, then
     * the terminate grace).
     *
     * The trap must be in the DIRECT child, so the command is a string with the
     * trap in it rather than a script file: `proc_open`'s child for a script
     * path is the `sh -c` that runs it, and `sh` does not ignore the signal.
     */
    public function testATerminateIgnoringCommandStillReturnsBoundedFromTheIdleDeadline(): void
    {
        $started = microtime(true);
        $result = (new StreamingCommandBackend("trap '' TERM; cat > /dev/null; sleep 8", idleTimeout: 1))
            ->complete([], null);
        $elapsed = microtime(true) - $started;

        $this->assertStringContainsString('error', $result->content);
        $this->assertLessThan(
            5.0,
            $elapsed,
            'a child that ignores SIGTERM must not hold the expiry path open: '
            . sprintf('%.2fs elapsed for a 1s idle deadline', $elapsed),
        );
    }

    /**
     * The expiry KEEPS what already streamed. The wrapper here prints `tok`,
     * exits 0, and leaves a backgrounded descendant holding stdout — measured
     * as `_[error: streaming backend produced no output for 1s]_` in 1.03s,
     * which threw away the token the user had already watched arrive AND
     * blamed the command for a silence that was its grandchild's.
     *
     * So: the token is in the message, the word "error" is not, and the notice
     * says pipes.
     */
    public function testAnIdleExpiryReturnsTheTokensItAlreadyStreamed(): void
    {
        $script = $this->writeScript(
            "#!/bin/bash\ncat > /dev/null\n( sleep 5 ) &\nprintf 'tok\\n'\nexit 0\n",
        );

        try {
            $streamed = [];
            $result = (new StreamingCommandBackend($script, idleTimeout: 1))->complete(
                [],
                function (string $token) use (&$streamed): void { $streamed[] = $token; },
            );

            $this->assertSame(['tok'], $streamed);
            $this->assertStringStartsWith('tok', $result->content, 'the answer the user already saw');
            $this->assertStringNotContainsString('error', $result->content);
            $this->assertStringContainsString('pipes', $result->content, 'the silent party may be a descendant');
        } finally {
            unlink($script);
        }
    }

    /**
     * The idle clock is sub-second, so a configured 1 means "silence longer
     * than 1.0s" and not "somewhere in (1.0, 2.0]".
     *
     * `time()` returns whole seconds, so `time() - $lastOutputAt > 1` needs TWO
     * integer ticks: arm just after a boundary and the deadline fires ~2.0s
     * later, arm just before one and it fires ~1.1s later — the reviewer
     * measured 1.148s to 1.833s across six phase offsets for the same
     * configured 1. This test removes the phase from the measurement by
     * starting deliberately at the WORST one for a whole-second clock: just
     * after a boundary, where `time()` would need the whole 2.0s. Under
     * `microtime(true)` the phase cannot matter at all.
     */
    public function testTheIdleDeadlineIsMeasuredOnASubSecondClock(): void
    {
        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 30\necho 'never reached'");

        try {
            // Align to just past a whole-second boundary.
            $boundary = floor(microtime(true)) + 1.0;
            do {
                usleep(2000);
            } while (microtime(true) < $boundary);

            $started = microtime(true);
            $result = (new StreamingCommandBackend($script, idleTimeout: 1))->complete([], null);
            $elapsed = microtime(true) - $started;

            $this->assertStringContainsString('error', $result->content);
            $this->assertGreaterThan(1.0, $elapsed, 'it must still wait the second it was configured for');
            $this->assertLessThan(
                1.25,
                $elapsed,
                'a whole-second clock quantises the arming instant: '
                . sprintf('%.3fs elapsed for a configured 1s', $elapsed),
            );
        } finally {
            unlink($script);
        }
    }

    /** User + system CPU seconds consumed by THIS process so far. */
    private static function cpuSeconds(): float
    {
        $usage = getrusage();

        return ($usage['ru_utime.tv_sec'] ?? 0) + (($usage['ru_utime.tv_usec'] ?? 0) / 1e6)
            + ($usage['ru_stime.tv_sec'] ?? 0) + (($usage['ru_stime.tv_usec'] ?? 0) / 1e6);
    }

    private function writeScript(string $body): string
    {
        $script = sys_get_temp_dir() . '/stream_deadline_' . uniqid('', true) . '.sh';
        file_put_contents($script, $body);
        chmod($script, 0755);

        return $script;
    }

    // =========================================================================
    // completeAsync() Tests
    // completeAsync() schedules its real work via Loop::futureTick(), which
    // only runs once something actually drives the loop - awaitPromise()
    // below does that with a bounded run(), and (critically) drains the
    // futureTick queue before returning either way so a test that doesn't
    // itself await resolution can't leave a callback dangling on the
    // GLOBAL Loop::get() singleton for some unrelated LATER test to trip
    // over - which is exactly what the old version of this test did, and
    // why it's no longer just "verify it returns a PromiseInterface".
    // =========================================================================

    public function testCompleteAsyncReturnsPromise(): void
    {
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'async test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $promise = $backend->completeAsync([Message::user('hello')]);

            $this->assertInstanceOf(\React\Promise\PromiseInterface::class, $promise);
            $this->awaitPromise($promise);
        } finally {
            unlink($script);
        }
    }

    public function testCompleteAsyncResolvesWithTheCommandsOutput(): void
    {
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'async test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $message = $this->awaitPromise($backend->completeAsync([Message::user('hello')]));

            $this->assertInstanceOf(Message::class, $message);
            $this->assertStringContainsString('async test', $message->content);
        } finally {
            unlink($script);
        }
    }

    /**
     * Regression: completeAsync() used to call Loop::stop() unconditionally
     * in a finally block - since this backend is driven by Program's own
     * long-lived Loop::run() (see the class docblock's "Usage" example),
     * that killed the WHOLE program's render/input loop the instant the
     * first reply arrived, not just this one async call. Proven here by
     * running a second, independent timer alongside the completion and
     * confirming it still gets to fire.
     */
    public function testCompleteAsyncDoesNotStopTheSharedEventLoop(): void
    {
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid() . '.sh';
        file_put_contents($script, "#!/bin/bash\necho 'async test'");
        chmod($script, 0755);

        try {
            $backend = new StreamingCommandBackend($script);
            $loop = \React\EventLoop\Loop::get();

            $otherTimerFired = false;
            $loop->addTimer(0.02, static function () use (&$otherTimerFired): void {
                $otherTimerFired = true;
            });

            $this->awaitPromise($backend->completeAsync([Message::user('hello')]));

            // Give the independent timer a chance to fire too, proving the
            // loop is still alive after completeAsync() settled.
            $this->awaitPromise($this->timerPromise(0.05));

            $this->assertTrue($otherTimerFired, 'an unrelated timer never fired - completeAsync() stopped the shared event loop');
        } finally {
            unlink($script);
        }
    }

    private function timerPromise(float $seconds): \React\Promise\PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();
        \React\EventLoop\Loop::get()->addTimer($seconds, static function () use ($deferred): void {
            $deferred->resolve(null);
        });

        return $deferred->promise();
    }

    /**
     * Single run()/stop() pair - see EngineBackendTest::awaitPromise()'s
     * docblock for why a repeated add-short-timer-then-run() polling dance
     * is fragile, and why leaving a scheduled callback un-drained on the
     * shared Loop::get() singleton corrupts unrelated later tests.
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
