<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Recorder;
use SugarCraft\Pty\Posix\PosixPtySystem;
use SugarCraft\Pty\Posix\PosixPump;
use SugarCraft\Pty\Posix\ReactPump;
use SugarCraft\Pty\PumpOptions;

/**
 * The output drain is bounded, and what it could not deliver is counted
 * (P1-8 drain and relay; AUD §3.10).
 *
 * The sync pump's drain used to end in one plain fwrite of the owed STDOUT
 * remainder, in the caller's own stream mode: unbounded on a blocking sink
 * whose reader stalled, and a silent loss on a non-blocking one. Now the
 * drain stops at {@see PumpOptions::$flushDeadlineSec} and reports the rest
 * through droppedBytes(). The pins use a sink nobody reads (a FIFO) and a
 * recorder that counts every byte read from the master,
 * so the accounting law is exact: recorded = delivered + dropped.
 */
final class PumpDroppedBytesTest extends TestCase
{
    use \SugarCraft\Pty\Tests\Support\RequiresPtyCapability;

    /** Far past the 64 KiB pipe buffer, so the sink must stall. */
    private const FLOOD_BYTES = 400_000;

    /** @var list<string> FIFOs to unlink in tearDown */
    private array $fifos = [];

    protected function tearDown(): void
    {
        foreach ($this->fifos as $path) {
            @\unlink($path);
        }
        $this->fifos = [];
    }

    /**
     * A real pipe nobody reads: [write end, read end]. A FIFO, not a socket
     * pair, because a UNIX socket pair autotunes its buffer to megabytes and
     * never stalls a 400 KB flood.
     *
     * @return array{0: resource, 1: resource}
     */
    private function stalledPipe(): array
    {
        if (!\function_exists('posix_mkfifo')) {
            $this->markTestSkipped('ext-posix posix_mkfifo() is unavailable.');
        }
        $path = \sys_get_temp_dir() . '/sugar-pty-sink-' . \bin2hex(\random_bytes(6));
        if (!\posix_mkfifo($path, 0600)) {
            $this->markTestSkipped('posix_mkfifo() failed in ' . \sys_get_temp_dir());
        }
        $this->fifos[] = $path;
        // Read end first, O_RDWR so the open does not wait for a writer;
        // then the write end opens at once.
        $reader = \fopen($path, 'r+');
        $sink = \fopen($path, 'w');
        $this->assertIsResource($reader);
        $this->assertIsResource($sink);
        return [$sink, $reader];
    }

    private function requireShell(): void
    {
        $this->requireBasePtyCapability();
        if (!\is_executable('/bin/sh')) {
            $this->markTestSkipped('/bin/sh is not executable on this host.');
        }
    }

    public function testACleanRunDropsNothing(): void
    {
        $this->requireShell();

        $pair = (new PosixPtySystem())->open(80, 24);
        $stdin = \fopen('/dev/null', 'r');
        $stdout = \fopen('php://temp', 'r+');
        try {
            $child = $pair->slave()->spawn(['/bin/sh', '-c', 'printf hello']);
            $pump = new PosixPump();
            $exit = $pump->run($pair->master(), $stdin, $stdout, $child, new PumpOptions());

            $this->assertSame(0, $exit);
            $this->assertStringContainsString('hello', (string) \stream_get_contents($stdout, -1, 0));
            $this->assertSame(0, $pump->droppedBytes());
        } finally {
            $pair->master()->close();
            \fclose($stdin);
            \fclose($stdout);
        }
    }

    public function testAStalledSinkIsCutOffAtTheFlushDeadlineAndTheRemainderCounted(): void
    {
        $this->requireShell();

        [$sink, $reader] = $this->stalledPipe();
        \stream_set_blocking($sink, false);
        $recorder = new CountingRecorder();
        $pair = (new PosixPtySystem())->open(80, 24);
        $stdin = \fopen('/dev/null', 'r');
        $child = null;
        try {
            $child = $pair->slave()->spawn([
                '/bin/sh', '-c', 'head -c ' . self::FLOOD_BYTES . ' /dev/zero | tr "\\0" a; sleep 30',
            ]);
            $opts = (new PumpOptions())
                ->withRecorder($recorder)
                ->withPumpDeadlineUs(600_000)
                ->withFlushDeadlineSec(0.2);
            $pump = new PosixPump();

            $start = \microtime(true);
            $pump->run($pair->master(), $stdin, $sink, $child, $opts);
            $elapsed = \microtime(true) - $start;

            $this->assertLessThan(3.0, $elapsed, 'the drain did not stop at its deadline');
            $this->assertGreaterThan(0, $pump->droppedBytes(), 'the owed remainder was not counted');
            $this->assertFalse(
                (bool) (\stream_get_meta_data($sink)['blocked'] ?? true),
                'the caller\'s non-blocking sink mode was not handed back',
            );
            $delivered = \strlen($this->drain($reader));
            $this->assertSame(
                $recorder->outputBytes,
                $delivered + $pump->droppedBytes(),
                'every byte read from the master must be either delivered or counted as dropped',
            );

            // The counter belongs to one run: a clean follow-up run resets it.
            $out = \fopen('php://temp', 'r+');
            $pair2 = (new PosixPtySystem())->open(80, 24);
            try {
                $child2 = $pair2->slave()->spawn(['/bin/sh', '-c', 'printf ok']);
                $pump->run($pair2->master(), $stdin, $out, $child2, new PumpOptions());
                $this->assertSame(0, $pump->droppedBytes());
            } finally {
                $pair2->master()->close();
                \fclose($out);
            }
        } finally {
            if ($child !== null && !$child->exited()) {
                $child->kill(9);
                $child->wait();
            }
            $pair->master()->close();
            \fclose($stdin);
            \fclose($sink);
            \fclose($reader);
        }
    }

    public function testTheDrainHandsABlockingSinkBackBlocking(): void
    {
        $this->requireShell();

        [$sink, $reader] = $this->stalledPipe();
        $pair = (new PosixPtySystem())->open(80, 24);
        $stdin = \fopen('/dev/null', 'r');
        try {
            $child = $pair->slave()->spawn(['/bin/sh', '-c', 'printf tail-bytes']);
            $pump = new PosixPump();
            $pump->run($pair->master(), $stdin, $sink, $child, new PumpOptions());

            $this->assertTrue((bool) (\stream_get_meta_data($sink)['blocked'] ?? false));
            $this->assertSame(0, $pump->droppedBytes());
            $this->assertStringContainsString('tail-bytes', $this->drain($reader));
        } finally {
            $pair->master()->close();
            \fclose($stdin);
            \fclose($sink);
            \fclose($reader);
        }
    }

    public function testReactPumpCountsTheRemainderItCouldNotDeliver(): void
    {
        $this->requireShell();

        $loop = new StreamSelectLoop();
        [$sink, $reader] = $this->stalledPipe();
        \stream_set_blocking($sink, false);
        $recorder = new CountingRecorder();
        $pair = (new PosixPtySystem())->open(80, 24);
        $child = null;
        try {
            $child = $pair->slave()->spawn([
                '/bin/sh', '-c', 'head -c ' . self::FLOOD_BYTES . ' /dev/zero | tr "\\0" a; sleep 30',
            ]);
            $opts = (new PumpOptions())
                ->withRecorder($recorder)
                ->withPumpDeadlineUs(600_000);
            $pump = new ReactPump($loop);
            $settled = false;
            $pump->start($pair->master(), null, $sink, $child, $opts)
                ->then(static function () use (&$settled, $loop): void {
                    $settled = true;
                    $loop->stop();
                });
            $watchdog = $loop->addTimer(10.0, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($watchdog);

            $this->assertTrue($settled, 'the pump never settled');
            $this->assertGreaterThan(0, $pump->droppedBytes(), 'the owed remainder was not counted');
            $delivered = \strlen($this->drain($reader));
            $this->assertSame(
                $recorder->outputBytes,
                $delivered + $pump->droppedBytes(),
                'every byte read from the master must be either delivered or counted as dropped',
            );
        } finally {
            if ($child !== null && !$child->exited()) {
                $child->kill(9);
                $child->wait();
            }
            $pair->master()->close();
            \fclose($sink);
            \fclose($reader);
        }
    }

    /**
     * ReactPump's teardown gives the owed remainder the same bounded flush
     * window PosixPump's drain does. It used to make one non-blocking fwrite
     * and count the rest as dropped, so a reader that would take the bytes
     * a moment later lost them on the ReactPump path only. Here the sink is
     * the stdin pipe of a reader that wakes after the pump deadline but well
     * inside flushDeadlineSec: nothing may be dropped.
     */
    public function testReactPumpDeliversTheRemainderToASlowReaderWithinTheFlushWindow(): void
    {
        $this->requireShell();

        $reader = \proc_open(
            ['/bin/sh', '-c', 'sleep 1; wc -c'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($reader);
        [$sink, $count] = $pipes;
        \stream_set_blocking($sink, false);

        $loop = new StreamSelectLoop();
        $recorder = new CountingRecorder();
        $pair = (new PosixPtySystem())->open(80, 24);
        $child = null;
        try {
            $child = $pair->slave()->spawn([
                '/bin/sh', '-c', 'head -c ' . self::FLOOD_BYTES . ' /dev/zero | tr "\\0" a; sleep 30',
            ]);
            $opts = (new PumpOptions())
                ->withRecorder($recorder)
                ->withPumpDeadlineUs(300_000)
                ->withFlushDeadlineSec(10.0);
            $pump = new ReactPump($loop);
            $settled = false;
            $pump->start($pair->master(), null, $sink, $child, $opts)
                ->then(static function () use (&$settled, $loop): void {
                    $settled = true;
                    $loop->stop();
                });
            $watchdog = $loop->addTimer(20.0, static fn () => $loop->stop());
            $loop->run();
            $loop->cancelTimer($watchdog);

            $this->assertTrue($settled, 'the pump never settled');
            $this->assertSame(0, $pump->droppedBytes(), 'the slow reader\'s bytes were dropped instead of flushed');
            \fclose($sink);
            $sink = null;
            $delivered = (int) \trim((string) \stream_get_contents($count));
            $this->assertGreaterThan(0, $recorder->outputBytes);
            $this->assertSame($recorder->outputBytes, $delivered, 'every byte read from the master must reach the reader');
        } finally {
            if ($child !== null && !$child->exited()) {
                $child->kill(9);
                $child->wait();
            }
            $pair->master()->close();
            if ($sink !== null) {
                \fclose($sink);
            }
            \fclose($count);
            \proc_close($reader);
        }
    }

    /**
     * Everything the sink delivered. The FIFO read end is O_RDWR, so it
     * never sees EOF; a non-blocking read stops at the empty pipe instead.
     *
     * @param resource $reader
     */
    private function drain($reader): string
    {
        \stream_set_blocking($reader, false);
        $got = '';
        while (true) {
            $chunk = \fread($reader, 65536);
            if ($chunk === false || $chunk === '') {
                return $got;
            }
            $got .= $chunk;
        }
    }
}

/** Counts the master output bytes the pump tees, nothing else. */
final class CountingRecorder implements Recorder
{
    public int $outputBytes = 0;

    public function recordResize(int $cols, int $rows): void
    {
    }

    public function recordInputBytes(string $bytes): void
    {
    }

    public function recordOutput(string $bytes): void
    {
        $this->outputBytes += \strlen($bytes);
    }

    public function recordQuit(): void
    {
    }

    public function close(): void
    {
    }
}
