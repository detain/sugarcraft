<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;

final class PosixPumpKeepaliveTest extends TestCase
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

    public function testKeepaliveCalledMultipleTimesDuringIdlePumpLoop(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $keepaliveCount = 0;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(100_000)
                ->withKeepalive(function () use (&$keepaliveCount): void {
                    $keepaliveCount++;
                });

            $pump = new PosixPump();
            $start = \microtime(true);

            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \fclose($stdout);

            // -1 because the `sleep 5` child is still alive when the
            // pump returns (stdin EOF + grace + flush ≈ 0.8s); the
            // pump no longer blocks on wait(). Test pins the new
            // non-blocking contract.
            $this->assertSame(-1, $exitCode);
            $this->assertGreaterThanOrEqual(
                3,
                $keepaliveCount,
                "keepalive should fire at least 3 times over ~350ms with 100ms timeout (got {$keepaliveCount})",
            );
            $this->assertGreaterThan(
                0.3,
                $elapsed,
                "pump should run at least 300ms to accumulate 3+ keepalive calls (elapsed: {$elapsed}s)",
            );
        } finally {
            $pair->master()->close();
        }
    }

    public function testKeepaliveNotCalledWhenNull(): void
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

    public function testKeepaliveAndOnIdleBothFireOnIdleTimeout(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $keepaliveCount = 0;
            $idleCount = 0;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(50_000)
                ->withKeepalive(function () use (&$keepaliveCount): void {
                    $keepaliveCount++;
                })
                ->withOnIdle(function () use (&$idleCount): void {
                    $idleCount++;
                });

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            // -1 because the `sleep 5` child is still alive when the
            // pump returns (stdin EOF + grace + flush); pump no
            // longer blocks on wait().
            $this->assertSame(-1, $exitCode);
            $this->assertGreaterThanOrEqual(1, $keepaliveCount, 'keepalive should fire at least once');
            $this->assertGreaterThanOrEqual(1, $idleCount, 'onIdle should fire at least once');
            $this->assertSame(
                $keepaliveCount,
                $idleCount,
                'onIdle and keepalive should fire the same number of times on idle timeouts',
            );
        } finally {
            $pair->master()->close();
        }
    }

    public function testKeepaliveIsCalledBeforeChildExitCheck(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 0.2; exit 0']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $keepaliveBeforeExit = false;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(10_000)
                ->withKeepalive(function () use (&$keepaliveBeforeExit): void {
                    $keepaliveBeforeExit = true;
                });

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
            $this->assertTrue($keepaliveBeforeExit, 'keepalive should have been called before child exit was detected');
        } finally {
            $pair->master()->close();
        }
    }
}
