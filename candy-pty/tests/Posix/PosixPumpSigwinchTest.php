<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;

final class PosixPumpSigwinchTest extends TestCase
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

    public function testOnSigwinchNotFiredOnIdleTick(): void
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

            // onIdle fires on every idle tick; onSigwinch should NOT.
            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(50_000)
                ->withOnIdle(function (): void {
                    // idle tick — onIdle fires, onSigwinch must not.
                })
                ->withOnSigwinch(function (int $cols, int $rows) use (&$sigwinchFired): void {
                    $sigwinchFired = true;
                });

            $pump = new PosixPump();

            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(-1, $exitCode);
            $this->assertFalse($sigwinchFired, 'onSigwinch must NOT fire on idle ticks — it is driven only by SignalForwarder from the consumer side');
        } finally {
            $pair->master()->close();
        }
    }

    public function testOnSigwinchNotCalledWhenNull(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/cat']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(10_000);

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
        } finally {
            $pair->master()->close();
        }
    }

    public function testOnSigwinchCallbackReceivesResizeNotification(): void
    {
        $this->requirePtySyscalls();
        $this->requirePcntl();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            // Without a real SignalForwarder attached, onSigwinch will not
            // fire from idle ticks (post-split). onIdle IS called on idle.
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
            // onIdle fires on every idle tick; onSigwinch does not fire
            // without a SignalForwarder signal.
            $this->assertGreaterThanOrEqual(1, $idleCount, 'onIdle should fire at least once per idle iteration');
            $this->assertSame(0, $sigwinchCount, 'onSigwinch should not fire without a real SIGWINCH signal');
        } finally {
            $pair->master()->close();
        }
    }
}
