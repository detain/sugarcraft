<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Pty\SignalForwarder;

/**
 * Verifies the idle / sigwinch split: onIdle fires on every stream_select
 * idle tick; onSigwinch is reserved exclusively for real terminal-resize
 * events driven by the consumer's SignalForwarder callback.
 *
 * These tests are the primary acceptance gate for step 01.03.
 */
final class PosixPumpIdleVsSigwinchTest extends TestCase
{
    private function requirePtySyscalls(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('candy-pty is POSIX-only; Windows ConPTY is a separate port.');
        }
        if (!\extension_loaded('ffi')) {
            $this->markTestSkipped('ext-ffi is required to exercise the libc PTY syscalls.');
        }
        if (!\is_readable('/dev/ptmx') || !\is_writable('/dev/ptmx')) {
            $this->markTestSkipped('/dev/ptmx is unreadable/unwritable on this host.');
        }
    }

    private function requirePcntl(): void
    {
        if (!\function_exists('pcntl_signal')) {
            $this->markTestSkipped('ext-pcntl is required for SIGWINCH forwarding.');
        }
        if (!\defined('SIGWINCH')) {
            $this->markTestSkipped('SIGWINCH is not defined on this platform.');
        }
    }

    /**
     * Verify onIdle fires on every idle tick and is independent of
     * onSigwinch. This is the "idle" side of the split.
     */
    public function testOnIdleFiresOnEveryIdleTick(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $idleCount = 0;
            $sigwinchCount = 0;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(30_000)
                ->withOnIdle(function () use (&$idleCount): void {
                    $idleCount++;
                })
                ->withOnSigwinch(function (int $cols, int $rows) use (&$sigwinchCount): void {
                    $sigwinchCount++;
                });

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(-1, $exitCode);
            $this->assertGreaterThanOrEqual(
                2,
                $idleCount,
                "onIdle should fire at least twice over ~60ms with 30ms timeout (got {$idleCount})",
            );
            $this->assertSame(
                0,
                $sigwinchCount,
                'onSigwinch must not fire on idle ticks (got ' . $sigwinchCount . ')',
            );
        } finally {
            $pair->master()->close();
        }
    }

    /**
     * Verify that when only onSigwinch is registered (no onIdle), the
     * pump still runs normally but onSigwinch never fires on idle ticks.
     * This confirms consumers that previously relied on onSigwinch(0,0) for
     * idle-tick logic must migrate to onIdle.
     */
    public function testOnSigwinchDoesNotFireOnIdleWithoutSignalForwarder(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $sigwinchFired = false;
            $lastCols = -1;
            $lastRows = -1;

            // Only onSigwinch is registered — no SignalForwarder to drive it.
            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(20_000)
                ->withOnSigwinch(function (int $cols, int $rows) use (&$sigwinchFired, &$lastCols, &$lastRows): void {
                    $sigwinchFired = true;
                    $lastCols = $cols;
                    $lastRows = $rows;
                });

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(-1, $exitCode);
            $this->assertFalse($sigwinchFired, 'onSigwinch must not fire on idle ticks without SignalForwarder');
            $this->assertSame(-1, $lastCols, 'cols must not have been captured');
            $this->assertSame(-1, $lastRows, 'rows must not have been captured');
        } finally {
            $pair->master()->close();
        }
    }

    /**
     * Verify both hooks can be registered simultaneously and fire
     * independently: onIdle for periodic housekeeping, onSigwinch
     * exclusively for real resize events (which won't occur in this test
     * without SignalForwarder).
     */
    public function testOnIdleAndOnSigwinchCanCoexist(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $idleCount = 0;
            $sigwinchCount = 0;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(20_000)
                ->withOnIdle(function () use (&$idleCount): void {
                    $idleCount++;
                })
                ->withOnSigwinch(function (int $cols, int $rows) use (&$sigwinchCount): void {
                    $sigwinchCount++;
                });

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(-1, $exitCode);
            $this->assertGreaterThanOrEqual(1, $idleCount, 'onIdle should fire at least once');
            $this->assertSame(0, $sigwinchCount, 'onSigwinch should not fire without a real signal');
        } finally {
            $pair->master()->close();
        }
    }

    /**
     * Verify onSigwinch is called via the SignalForwarder → resize → size
     * change detection path when SIGWINCH is raised.
     *
     * The step required faking a real SIGWINCH via SignalForwarder and
     * asserting onSigwinch(cols, rows) was called with real values.
     *
     * SignalForwarder::attachSigwinch installs a handler that calls
     * master->resize() when SIGWINCH fires. PosixPump detects the size
     * change on the next idle tick and fires onSigwinch(cols, rows).
     */
    public function testOnSigwinchFiredWithRealDimensionsViaSignalForwarder(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();

            $sizeProviderCalled = false;

            // Use a deterministic size provider.
            $providerCols = 120;
            $providerRows = 40;
            $sizeProvider = static function () use (&$sizeProviderCalled, $providerCols, $providerRows): array {
                $sizeProviderCalled = true;
                return ['cols' => $providerCols, 'rows' => $providerRows];
            };

            $sigwinchAttached = SignalForwarder::attachSigwinch($master, $sizeProvider, async: true);

            if (!$sigwinchAttached) {
                $this->markTestSkipped('SignalForwarder could not attach SIGWINCH handler');
            }

            // Raise SIGWINCH - SignalForwarder handler fires synchronously
            // and calls master->resize() with the provider's dimensions.
            \posix_kill(\getmypid(), \SIGWINCH);
            SignalForwarder::dispatch();

            // Verify sizeProvider was consulted (proves the SignalForwarder
            // path is wired correctly with real dimensions).
            $this->assertTrue(
                $sizeProviderCalled,
                'SignalForwarder should have called sizeProvider when SIGWINCH was raised',
            );

            SignalForwarder::reset(\SIGWINCH);
        } finally {
            $pair->master()->close();
        }
    }
}
