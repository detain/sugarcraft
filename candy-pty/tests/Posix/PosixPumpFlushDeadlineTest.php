<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;

final class PosixPumpFlushDeadlineTest extends TestCase
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

    public function testFlushDeadlineRespected(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn([
                '/bin/bash', '-c',
                'for i in $(seq 1 1000); do echo "x"; done; echo "flush-me"; exit 0',
            ]);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $opts = (new PumpOptions())->withFlushDeadlineSec(0.15);

            $pump = new PosixPump();
            $start = \microtime(true);
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);
            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \rewind($stdout);
            $output = \stream_get_contents($stdout, -1, 0);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
            $this->assertLessThan(
                2.0,
                $elapsed,
                'pump should return within 2s (flush deadline 0.15s + child exit)',
            );
            $this->assertStringContainsString('flush-me', $output, 'some output should be drained before deadline');
        } finally {
            $pair->master()->close();
        }
    }

    public function testFlushDeadlineWithZeroChildWritesReturnsQuickly(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/cat']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $opts = (new PumpOptions())->withFlushDeadlineSec(0.1);

            $pump = new PosixPump();
            $start = \microtime(true);
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);
            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \rewind($stdout);
            $output = \stream_get_contents($stdout, -1, 0);
            \fclose($stdout);

            $this->assertLessThan(
                0.5,
                $elapsed,
                'pump with zero output and 0.1s deadline should return within 0.5s',
            );
            $this->assertSame('', $output);
        } finally {
            $pair->master()->close();
        }
    }

    public function testFlushDeadlineWithChildExitingDuringFlush(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn([
                '/bin/bash', '-c',
                'printf "a%.0s" $(seq 1 4096); sleep 0.1; exit 0',
            ]);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $opts = (new PumpOptions())->withFlushDeadlineSec(0.5);

            $pump = new PosixPump();
            $start = \microtime(true);
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);
            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \rewind($stdout);
            $output = \stream_get_contents($stdout, -1, 0);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
            $this->assertLessThan(
                2.0,
                $elapsed,
                'flushDeadlineSec 0.5s with child exiting quickly should complete within 2s',
            );
            $this->assertStringContainsString('aaaa', $output);
        } finally {
            $pair->master()->close();
        }
    }
}
