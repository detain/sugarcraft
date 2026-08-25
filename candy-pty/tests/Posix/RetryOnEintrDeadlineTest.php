<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixMasterPty;

/**
 * {@see PosixMasterPty::retryOnEintr()} must honour its caller's DEADLINE, not
 * restart its caller's TIMEOUT.
 *
 * Written because the helper had no behavioural test at all: the only coverage
 * was a `method_exists()` assertion plus a `ReflectionMethod` poke, under a
 * comment conceding "we can't actually call retryOnEintr with invalid args due
 * to by-ref signature". So the retry, the errno discrimination and the timeout
 * semantics had never been executed, and a helper that answered "block roughly
 * forever" would have reported green.
 *
 * The defect this pins: the EINTR branch used to re-pass the caller's ORIGINAL
 * `$sec`/`$usec`, so every interruption restarted the full wait. A signal
 * arriving faster than the timeout therefore pushed the deadline out
 * indefinitely — and candy-pty generates exactly that signal rate whenever a
 * suite reaps children while a pump is selecting.
 */
final class RetryOnEintrDeadlineTest extends TestCase
{
    /**
     * The wait a caller asks for. Three seconds, not one, because
     * `pcntl_alarm()` has whole-second granularity — this host has no
     * `pcntl_setitimer`, so sub-second interruption is not available and the
     * timeout has to be long enough to fit several alarms inside it.
     */
    private const TIMEOUT_SEC = 3;

    /**
     * Stop re-arming after this many alarms, so the test TERMINATES on the
     * buggy code too. A regression must fail this test, never hang it — a
     * hanging regression test is the exact failure mode this file exists to
     * remove.
     */
    private const MAX_ALARMS = 3;

    private function requireSignals(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only.');
        }
        foreach (['pcntl_signal', 'pcntl_alarm', 'pcntl_signal_dispatch'] as $fn) {
            if (!\function_exists($fn)) {
                $this->markTestSkipped("ext-pcntl is required ({$fn} missing).");
            }
        }
        if (!\extension_loaded('ffi')) {
            // errno() is read through the libc shim; without it stream_select's
            // false is indistinguishable from a real error and the retry branch
            // is never entered.
            $this->markTestSkipped('ext-ffi is required to observe EINTR.');
        }
    }

    public function testAnInterruptedWaitStillExpiresOnTheCallersDeadline(): void
    {
        $this->requireSignals();

        // A pipe nobody ever writes to: the select can only ever end by timing
        // out or by being interrupted, never by becoming ready.
        $pipe = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pipe, 'stream_socket_pair() failed');

        $fired = 0;
        $previous = \pcntl_signal(SIGALRM, static function () use (&$fired): void {
            $fired++;
            // Re-arm from inside the handler; pcntl_alarm() is one-shot. Stops
            // re-arming at MAX_ALARMS so the buggy path still terminates.
            if ($fired < self::MAX_ALARMS) {
                \pcntl_alarm(1);
            }
        });
        $this->assertTrue($previous, 'could not install SIGALRM handler');

        try {
            \pcntl_alarm(1);

            $read = [$pipe[0]];
            $write = null;
            $except = null;

            $start = \microtime(true);
            $ready = PosixMasterPty::retryOnEintr($read, $write, $except, self::TIMEOUT_SEC, 0);
            $elapsed = \microtime(true) - $start;
        } finally {
            \pcntl_alarm(0);
            \pcntl_signal(SIGALRM, SIG_DFL);
            \fclose($pipe[0]);
            \fclose($pipe[1]);
        }

        $this->assertGreaterThan(
            0,
            $fired,
            'no SIGALRM was delivered, so the EINTR path was never exercised and a '
            . 'green result here would mean nothing',
        );

        $this->assertSame(
            0,
            $ready,
            'an expired deadline must be reported as 0 (nothing became ready), never as '
            . 'false — callers turn false into a thrown PtyException, and a timeout is '
            . 'not an error',
        );

        // Fixed: the deadline is 3 s from the first call, the three alarms land
        // at 1 s, 2 s and 3 s, and the third finds the deadline expired — so
        // the wait ends at ~3 s. Buggy: each alarm restarted the full 3 s, so
        // the last one (at 3 s) ran a further 3 s and the wait ended at ~6 s.
        // 4 s sits between the two with a full second of slack either side.
        $this->assertLessThan(
            self::TIMEOUT_SEC + 1.0,
            $elapsed,
            \sprintf(
                'retryOnEintr waited %.3fs for a %ds deadline across %d interruptions — '
                . 'the EINTR retry is restarting the timeout instead of recomputing what '
                . 'is left of it',
                $elapsed,
                self::TIMEOUT_SEC,
                $fired,
            ),
        );
    }

    /**
     * The other half of the contract: a null timeout means "block until ready"
     * and must NOT acquire a deadline from this change.
     */
    public function testANullTimeoutStillBlocksUntilReady(): void
    {
        // Deliberately NOT gated on signals or FFI: a ready stream returns
        // without ever reaching the errno branch, so this arm runs everywhere
        // and keeps the null contract pinned on hosts where the EINTR arm above
        // has to skip.
        $pipe = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        $this->assertIsArray($pipe, 'stream_socket_pair() failed');

        try {
            // Written BEFORE the select, so readiness is already true and the
            // call returns without blocking. This asserts the null path is
            // still reached and still answers readiness — it deliberately does
            // not try to prove "blocks forever", which no terminating test can.
            \fwrite($pipe[1], 'x');

            $read = [$pipe[0]];
            $write = null;
            $except = null;

            $ready = PosixMasterPty::retryOnEintr($read, $write, $except, null, null);

            $this->assertSame(1, $ready, 'a ready stream must be reported under a null timeout');
        } finally {
            \fclose($pipe[0]);
            \fclose($pipe[1]);
        }
    }
}
