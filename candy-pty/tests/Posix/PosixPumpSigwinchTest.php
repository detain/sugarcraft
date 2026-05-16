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

    public function testOnSigwinchCallbackFiresOnIdleTimeout(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'sleep 5']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $sigwinchCalled = false;
            $capturedCols = -1;
            $capturedRows = -1;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(50_000)
                ->withOnSigwinch(function (int $cols, int $rows) use (&$sigwinchCalled, &$capturedCols, &$capturedRows): void {
                    $sigwinchCalled = true;
                    $capturedCols = $cols;
                    $capturedRows = $rows;
                });

            $pump = new PosixPump();
            $start = \microtime(true);

            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
            $this->assertTrue($sigwinchCalled, 'onSigwinch callback should fire at least once during idle pump loop');
            $this->assertSame(0, $capturedCols, 'onSigwinch cols should be 0 (pump does not track PTY size)');
            $this->assertSame(0, $capturedRows, 'onSigwinch rows should be 0 (pump does not track PTY size)');
            $this->assertGreaterThan(0.04, $elapsed, 'pump should run long enough to trigger at least one idle timeout');
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

            $sigwinchCount = 0;
            $lastCols = -1;
            $lastRows = -1;

            $opts = (new PumpOptions())
                ->withSelectTimeoutUs(30_000)
                ->withOnSigwinch(function (int $cols, int $rows) use (&$sigwinchCount, &$lastCols, &$lastRows): void {
                    $sigwinchCount++;
                    $lastCols = $cols;
                    $lastRows = $rows;
                });

            $pump = new PosixPump();

            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
            $this->assertGreaterThanOrEqual(1, $sigwinchCount, 'onSigwinch should be called at least once per idle iteration');
        } finally {
            $pair->master()->close();
        }
    }
}
