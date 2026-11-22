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
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    public function testPumpOptionsDefaults(): void
    {
        $opts = new PumpOptions();
        $this->assertSame(PumpOptions::DEFAULT_CHUNK_BYTES, $opts->chunkBytes);
        $this->assertSame(PumpOptions::DEFAULT_SELECT_TIMEOUT_US, $opts->selectTimeoutUs);
        $this->assertSame(PumpOptions::DEFAULT_FLUSH_DEADLINE_SEC, $opts->flushDeadlineSec);
        $this->assertSame(PumpOptions::DEFAULT_STDIN_EOF_GRACE_SEC, $opts->stdinEofGraceSec);
        $this->assertSame(PumpOptions::DEFAULT_VEOF, $opts->veof);
        $this->assertNull($opts->keepalive);
        $this->assertNull($opts->onResize);
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

    /**
     * The pump's own stdin and stdout, never the runner's. With no child the
     * run ends on stdin EOF, and the runner's STDIN gives none: an interactive
     * phpunit waits for Ctrl-D, and a ParaTest worker's STDIN is its command
     * pipe, which stays open until the run ends. The worker then hung until
     * HangWatchdog killed it (exit 137) at every K. The pump also sets its
     * stdin non-blocking, which on the real STDIN reaches the worker's pipe.
     * /dev/null is at EOF at once and can be selected, which php://memory
     * cannot.
     */
    public function testRunWithNullChildReturnsZero(): void
    {
        $this->requirePtySyscalls();

        $system = new PosixPtySystem();
        $pair = $system->open(80, 24);
        $stdin = \fopen('/dev/null', 'rb');
        $stdout = \fopen('/dev/null', 'wb');
        $this->assertIsResource($stdin);
        $this->assertIsResource($stdout);

        try {
            $master = $pair->master();

            $pump = new PosixPump();
            $exitCode = $pump->run($master, $stdin, $stdout, null);

            $this->assertSame(0, $exitCode);
        } finally {
            $pair->master()->close();
            \fclose($stdin);
            \fclose($stdout);
        }
    }
}
