<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Backend;

use SugarCraft\Crush\Backend\CancellationToken;
use SugarCraft\Crush\Backend\StreamingCommandBackend;
use SugarCraft\Crush\Message;
use SugarCraft\Crush\Role;
use PHPUnit\Framework\TestCase;

final class StreamingCommandBackendTest extends TestCase
{
    public function testStreamingBackendCallsOnTokenForEachLine(): void
    {
        // Create a script that outputs tokens line by line
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid((string) getmypid(), true) . '.sh';
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
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid((string) getmypid(), true) . '.sh';
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
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid((string) getmypid(), true) . '.sh';
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
        $script = sys_get_temp_dir() . '/stream_test_' . uniqid((string) getmypid(), true) . '.sh';
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

        $script = sys_get_temp_dir() . '/stream_test_' . uniqid((string) getmypid(), true) . '.sh';
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
     * REGRESSION. A child that is still SWALLOWING a large history is making
     * progress, and the idle deadline must not kill it.
     *
     * Every other deadline test in this file passes `complete([], …)`, so the
     * payload is the two bytes `[]` and the stdin transfer is instantaneous —
     * which makes "silence since spawn" and "silence since the history was
     * delivered" indistinguishable, and they are not the same clock. The
     * blocking `fwrite()` that {@see StreamingCommandBackend::pump()} replaced
     * returned only once the WHOLE payload had been handed over, and the old
     * code armed the deadline after it; arming it at spawn and re-arming it on
     * stdout/stderr alone silently redefined it, and a healthy wrapper reading
     * a long conversation in 64K bites died mid-prompt. MEASURED with the
     * fixture below scaled to 512 KB and `idleTimeout: 2`: 2.01s and
     * `_[error: no output …]_` against 4.51s and `ok` from the code this
     * replaced.
     *
     * The fixture reads in `read`-sized bites with a sleep between them, so
     * every iteration that moves bytes moves them ON STDIN ONLY — there is no
     * output at all until the transfer is over, which is exactly the state the
     * regression mistook for a wedge.
     */
    public function testAChildStillSwallowingALargeHistoryIsNotKilledByTheIdleDeadline(): void
    {
        // 256 KB of history against a child that takes 64K every 0.4s: four
        // bites, ~1.6s of transfer, against a 1s deadline it must not trip.
        $history = [Message::user(str_repeat('x', 256 * 1024))];
        $script = $this->writeScript(
            "#!/bin/bash
" . 'while IFS= read -r -n 65536 chunk; do sleep 0.4; done' . "
printf 'ok\n'
",
        );

        try {
            $started = microtime(true);
            $result = (new StreamingCommandBackend($script, idleTimeout: 1))->complete($history, null);
            $elapsed = microtime(true) - $started;

            $this->assertSame('ok', $result->content);
            // The transfer alone has to outlive the deadline, or the fixture
            // proves nothing: 1.5s is a floor the four 0.4s bites clear while
            // leaving margin over the 1s deadline for scheduling jitter.
            $this->assertGreaterThan(
                1.5,
                $elapsed,
                'the stdin transfer has to outlive the 1s deadline by a clear margin or this proves nothing',
            );
        } finally {
            unlink($script);
        }
    }

    /**
     * And the deadline still bites once the large history HAS been delivered,
     * so the test above is not passing because stdin progress disabled the
     * mechanism for anything with a payload.
     *
     * `cat` drains the whole 256 KB as fast as the pipe will give it, so the
     * last stdin byte moves within milliseconds of the spawn and the silence
     * that follows is the child's own.
     */
    public function testTheDeadlineStillBitesAfterALargeHistoryHasBeenDelivered(): void
    {
        $history = [Message::user(str_repeat('x', 256 * 1024))];
        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 30\necho 'never reached'");

        try {
            $started = microtime(true);
            $result = (new StreamingCommandBackend($script, idleTimeout: 1))->complete($history, null);
            $elapsed = microtime(true) - $started;

            $this->assertSame(
                "_[error: no output on the streaming backend's pipes for more than 1s]_",
                $result->content,
            );
            $this->assertLessThan(
                5.0,
                $elapsed,
                'the deadline must fire on the silence after delivery, not wait out the 30s sleep',
            );
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
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid((string) getmypid(), true) . '.sh';
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
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid((string) getmypid(), true) . '.sh';
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
        $script = sys_get_temp_dir() . '/stream_async_' . uniqid((string) getmypid(), true) . '.sh';
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

    /**
     * THE POINT OF THE WHOLE METHOD, and the assertion no implementation that
     * merely finishes can satisfy.
     *
     * Awaiting the promise passes whether the loop was free or frozen — the
     * work completes either way, which is exactly why
     * {@see testCompleteAsyncDoesNotStopTheSharedEventLoop()} above stayed green
     * throughout the years this method blocked. So this one arms an independent
     * 20ms periodic timer standing in for the render tick and counts how many
     * times it fires WHILE the command is in flight.
     *
     * MEASURED against the old implementation (the whole synchronous
     * `complete()` inside one `Loop::futureTick`): 0 ticks across 1.81s.
     * Against this one: 36. The floor here is far below that so the assertion
     * is about the difference between "the loop ran" and "it did not", not
     * about scheduler precision on a loaded box.
     */
    public function testCompleteAsyncLeavesTheEventLoopFreeWhileTheCommandRuns(): void
    {
        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 0.4\nprintf 'slow reply\\n'");

        try {
            $backend = new StreamingCommandBackend($script);
            $loop = \React\EventLoop\Loop::get();

            $ticks = 0;
            $ticker = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void { ++$ticks; });

            $message = $this->awaitPromise($backend->completeAsync([Message::user('hello')]));
            $loop->cancelTimer($ticker);

            $this->assertSame('slow reply', $message->content);
            $this->assertGreaterThan(5, $ticks, "the event loop ticked only {$ticks} times during a 400ms completion - it was blocked");
        } finally {
            unlink($script);
        }
    }

    /**
     * The streaming half of the same claim: tokens must reach `$onToken` AS
     * THEY ARRIVE, interleaved with the loop's other work — not batched up and
     * flushed when the completion settles.
     *
     * Counting the ticks alone cannot tell those two apart, and neither can
     * counting the tokens: a `pump()` that buffered every line and emitted them
     * all from `finish()` would still deliver six callbacks under a loop that
     * ticked throughout. What separates them is WHEN each callback fired
     * relative to the ticker, so every token records the tick count at its own
     * instant. Six tokens 150ms apart against a 20ms ticker must land at six
     * DISTINCT and increasing tick counts; a batched implementation records six
     * identical ones.
     */
    public function testCompleteAsyncDeliversEachTokenWhileTheLoopIsStillTicking(): void
    {
        $script = $this->writeScript(
            "#!/bin/bash\ncat > /dev/null\nfor i in 1 2 3 4 5 6; do printf 'tok%s\\n' \"\$i\"; sleep 0.15; done",
        );

        try {
            $backend = new StreamingCommandBackend($script);
            $loop = \React\EventLoop\Loop::get();

            $ticks = 0;
            $ticker = $loop->addPeriodicTimer(0.02, static function () use (&$ticks): void { ++$ticks; });

            $tokens = [];
            $tickAtToken = [];
            $message = $this->awaitPromise($backend->completeAsync(
                [Message::user('hello')],
                static function (string $token) use (&$tokens, &$tickAtToken, &$ticks): void {
                    $tokens[] = $token;
                    $tickAtToken[] = $ticks;
                },
            ));
            $loop->cancelTimer($ticker);

            $this->assertSame(['tok1', 'tok2', 'tok3', 'tok4', 'tok5', 'tok6'], $tokens);
            $this->assertSame('tok1tok2tok3tok4tok5tok6', $message->content);
            $this->assertSame(
                $tickAtToken,
                array_values(array_unique($tickAtToken)),
                'two tokens were delivered without a single loop tick between them - they were batched, not streamed: ' . json_encode($tickAtToken),
            );
            // The first token lands within a few ms of the spawn, before a
            // 20ms ticker has fired at all, so its own count is legitimately 0
            // - the claim is about the SPAN, which a batched implementation
            // collapses to zero.
            $this->assertGreaterThan(
                5,
                $tickAtToken[5] - $tickAtToken[0],
                'the loop barely ticked between the first token and the last: ' . json_encode($tickAtToken),
            );
        } finally {
            unlink($script);
        }
    }

    /**
     * Cancellation has to be POLLED, not checked once before the spawn.
     * `Chat`'s double-Escape flips the shared token long after
     * `completeAsync()` has returned, and the old implementation could not have
     * looked again if it wanted to: it never got back to the loop.
     *
     * The fixture would take 5 seconds to answer. Coming back in a fraction of
     * that is the assertion, so it cannot be satisfied by waiting the child out
     * and reporting a cancel afterwards.
     */
    public function testCompleteAsyncIsCancellableWhileTheCommandIsStillRunning(): void
    {
        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 5\nprintf 'too late\\n'");

        try {
            $backend = new StreamingCommandBackend($script);
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
     * A cancelled completion must not leave the child behind as a zombie: the
     * abort path kills and reaps rather than dropping the handle, for the same
     * reason {@see testAnExpiredCommandIsReapedRatherThanLeftAsAZombie()}
     * exists. Same census, same caveat about it being a whole-process count.
     */
    public function testACancelledCompletionReapsItsChild(): void
    {
        if (!is_dir('/proc/self')) {
            $this->markTestSkipped('the zombie census needs a Linux-shaped /proc');
        }

        $script = $this->writeScript("#!/bin/bash\ncat > /dev/null\nsleep 5\nprintf 'too late\\n'");

        try {
            $backend = new StreamingCommandBackend($script);
            $before = self::ownZombieCount();

            for ($i = 0; $i < 2; ++$i) {
                $cancellation = new CancellationToken();
                $loop = \React\EventLoop\Loop::get();
                $promise = $backend->completeAsync([Message::user('hello')], null, $cancellation);
                $flip = $loop->addTimer(0.05, static function () use ($cancellation): void { $cancellation->cancel(); });

                try {
                    $this->awaitPromise($promise);
                } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                    // RULE 39, AND THE ONE SITE IN THIS FAMILY THAT REALLY WAS
                    // THE SILENT-PASS SHAPE. awaitPromise() ends in
                    // `$this->fail('Promise did not settle within the test
                    // timeout')`, which raises an AssertionFailedError -
                    // and that class extends PHPUnit\Framework\Exception
                    // extends \RuntimeException, so the bare
                    // `catch (\RuntimeException)` that stood here caught the
                    // harness's own timeout and discarded it as "expected".
                    // The loop then finished and the zombie census below - a
                    // count that has nothing to do with whether the promise
                    // ever settled - decided the verdict. Unlike this file's
                    // sibling sites, which still went RED through their
                    // instanceof assertions, a hang here could come out GREEN.
                    // Found by {@see AwaitPromiseDiagnosticArmTest}, which is
                    // what now keeps this arm here.
                    throw $e;
                } catch (\RuntimeException) {
                    // The cancellation rejection this loop is provoking.
                }
                $loop->cancelTimer($flip);
            }

            $this->assertSame(
                $before,
                self::ownZombieCount(),
                'two cancelled completions killed their children but never waited for them',
            );
        } finally {
            unlink($script);
        }
    }

    /**
     * THE CLASSIC DOUBLE-DEADLOCK, asserted on BOTH entry points because the
     * single blocking `fwrite()` this replaced sat before the read loop and so
     * froze the blocking path exactly as hard as the async one.
     *
     * The history is bigger than the kernel's ~64K pipe buffer and the command
     * writes more than 64K of stdout BEFORE it reads its stdin. Write the
     * history in one blocking call and both sides park forever: we are blocked
     * writing a full stdin pipe, the child is blocked writing a full stdout
     * pipe, and the only process that could drain either is the one blocked on
     * the other. Not a slow case — unbounded; a child that could exit would at
     * least hand the writer an EPIPE, and this one cannot reach its exit.
     *
     * {@see pump()} writes a slice of stdin from the same iteration that drains
     * stdout, so neither pipe can fill without the other being emptied — which
     * is why hoisting the loop fixed `complete()` too, and not only the method
     * this round set out to unblock.
     *
     * Without it neither of these fails — they HANG, unbounded, and no timer
     * rescues them: `awaitPromise()`'s safety timer lives on the very loop the
     * `fwrite()` is blocking, and `complete()` has no loop at all. A regression
     * here wedges the suite rather than reddening it. MEASURED by mutating
     * stdin back to blocking: killed by an external `timeout`, not by PHPUnit.
     *
     * @dataProvider entryPoints
     */
    public function testAHistoryLargerThanThePipeBufferDoesNotDeadlockAgainstTheCommandsOwnOutput(string $entryPoint): void
    {
        $script = $this->writeScript(
            "#!/bin/bash\n"
            . "yes 0123456789012345678901234567890123456789012345678901234567890123 | head -n 4000\n"
            . "cat > /dev/null\n"
            . "printf 'done\\n'",
        );

        try {
            $backend = new StreamingCommandBackend($script);
            $huge = [Message::user(str_repeat('x', 512 * 1024))];

            // `match` with no default, so a third `complete*()` method added
            // later fails here loudly instead of being silently dispatched to
            // whichever arm a ternary's else-branch happened to be.
            $message = match ($entryPoint) {
                'complete' => $backend->complete($huge, null),
                'completeAsync' => $this->awaitPromise($backend->completeAsync($huge)),
            };

            $this->assertStringEndsWith('done', $message->content);
            // 4000 tokens of 64 chars joined with the empty string, then the
            // `done` token: every byte the child wrote survived the interleaved
            // write, so nothing was lost to a pipe that filled.
            $this->assertSame(4000 * 64 + 4, strlen($message->content));
        } finally {
            unlink($script);
        }
    }

    /**
     * Named from the class's own two entry points rather than hand-listed, so
     * this provider cannot go stale by omission - a third public completion
     * method would show up here without anyone remembering to add it.
     *
     * @return iterable<string,array{string}>
     */
    public static function entryPoints(): iterable
    {
        foreach ((new \ReflectionClass(StreamingCommandBackend::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (str_starts_with($method->getName(), 'complete')) {
                yield $method->getName() => [$method->getName()];
            }
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
            . ' $b = new \\' . StreamingCommandBackend::class . '([\'printf\', \'hi\']);'
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
