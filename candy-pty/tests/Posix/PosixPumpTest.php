<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\PumpOptions;
use SugarCraft\Pty\Contract\Child;

final class PosixPumpTest extends TestCase
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

    public function testPumpOptionsDefaults(): void
    {
        $opts = new PumpOptions();
        $this->assertSame(PumpOptions::DEFAULT_CHUNK_BYTES, $opts->chunkBytes);
        $this->assertSame(PumpOptions::DEFAULT_SELECT_TIMEOUT_US, $opts->selectTimeoutUs);
        $this->assertSame(PumpOptions::DEFAULT_FLUSH_DEADLINE_SEC, $opts->flushDeadlineSec);
        $this->assertSame(PumpOptions::DEFAULT_STDIN_EOF_GRACE_SEC, $opts->stdinEofGraceSec);
        $this->assertSame(PumpOptions::DEFAULT_VEOF, $opts->veof);
        $this->assertNull($opts->keepalive);
        $this->assertNull($opts->onSigwinch);
        $this->assertNull($opts->onChildExit);
    }

    public function testPumpOptionsWithCustomValues(): void
    {
        $opts = (new PumpOptions())
            ->withChunkBytes(8192)
            ->withSelectTimeoutUs(100000)
            ->withFlushDeadlineSec(1.0)
            ->withStdinEofGraceSec(0.5)
            ->withVEOF("\x00");

        $this->assertSame(8192, $opts->chunkBytes);
        $this->assertSame(100000, $opts->selectTimeoutUs);
        $this->assertSame(1.0, $opts->flushDeadlineSec);
        $this->assertSame(0.5, $opts->stdinEofGraceSec);
        $this->assertSame("\x00", $opts->veof);
    }

    public function testSimpleEcho(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'echo hello']);

            // Use /dev/null as stdin so pump sees immediate EOF
            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child);

            \rewind($stdout);
            $output = \stream_get_contents($stdout, -1, 0);
            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
            $this->assertStringContainsString('hello', $output);
        } finally {
            $pair->master()->close();
        }
    }

    public function testChildExitsCleanly(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/bash', '-c', 'exit 0']);

            $stdin = \fopen('/dev/null', 'r');
            $stdout = \fopen('php://temp', 'r+');

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, $child);

            \fclose($stdin);
            \fclose($stdout);

            $this->assertSame(0, $exitCode);
        } finally {
            $pair->master()->close();
        }
    }

    public function testMasterReadWriteRoundTrip(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();
            $child = $pair->slave()->spawn(['/bin/cat']);

            \stream_set_blocking($master->stream(), false);
            $master->write("test\n");
            $child->kill(Child::SIGKILL);
            $child->wait();

            $output = '';
            $deadline = \microtime(true) + 0.5;
            while (\microtime(true) < $deadline) {
                $data = $master->read(1024, 0.05);
                if ($data !== null && $data !== '') {
                    $output .= $data;
                }
                if (\strlen($output) >= 5) {
                    break;
                }
            }

            $this->assertStringStartsWith("test", $output);
        } finally {
            $pair->master()->close();
        }
    }

    public function testRunWithNullChildReturnsZero(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);

        try {
            $master = $pair->master();

            $pump = new PosixPump();
            $exitCode = $pump->run($master, \STDIN, \STDOUT, null);

            $this->assertSame(0, $exitCode);
        } finally {
            $pair->master()->close();
        }
    }
}
