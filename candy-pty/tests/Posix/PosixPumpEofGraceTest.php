<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;

final class PosixPumpEofGraceTest extends TestCase
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

    public function testEofGraceDrainsChildOutput(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn([
                '/bin/bash', '-c',
                'for i in 1 2 3 4 5 6 7 8 9 10; do echo "line-$i"; sleep 0.05; done',
            ]);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $pump = new PosixPump();
            $start = \microtime(true);

            $exitCode = $pump->run($master, $stdin, $stdout, $child);

            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \rewind($stdout);
            $output = \stream_get_contents($stdout, -1, 0);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);

            for ($i = 1; $i <= 10; $i++) {
                $this->assertStringContainsString("line-$i", $output, "line-$i should appear in output");
            }

            $this->assertLessThan(
                3.0,
                $elapsed,
                'pump should complete within 3 seconds (10 lines * 0.05s + grace period)',
            );
        } finally {
            $pair->master()->close();
        }
    }

    public function testEofGraceDeadlineTriggersEvenIfChildIgnoresVEOF(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn([
                '/bin/bash', '-c',
                'trap "exit 0" TERM; sleep 2; echo "wake"',
            ]);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $opts = (new PumpOptions())->withStdinEofGraceSec(0.3);

            $pump = new PosixPump();
            $start = \microtime(true);
            $exitCode = $pump->run($master, $stdin, $stdout, $child, $opts);
            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \fclose($stdout);

            $this->assertLessThan(
                2.5,
                $elapsed,
                'pump should return within 2.5s when grace deadline (0.3s) triggers and child exits upon PTY close',
            );
        } finally {
            $pair->master()->close();
        }
    }

    public function testStdinEofGraceAllowsChildToFinishWriting(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn([
                '/bin/bash', '-c',
                'for i in 1 2 3; do echo "msg-$i"; sleep 0.1; done; echo "done"',
            ]);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $pump = new PosixPump();
            $start = \microtime(true);

            $exitCode = $pump->run($master, $stdin, $stdout, $child);

            $elapsed = \microtime(true) - $start;

            \fclose($stdin);
            \rewind($stdout);
            $output = \stream_get_contents($stdout, -1, 0);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('msg-1', $output);
            $this->assertStringContainsString('msg-2', $output);
            $this->assertStringContainsString('msg-3', $output);
            $this->assertStringContainsString('done', $output);

            $this->assertLessThan(
                2.0,
                $elapsed,
                'pump should complete within 2s, allowing child to write all 3 msgs + done',
            );
        } finally {
            $pair->master()->close();
        }
    }
}
