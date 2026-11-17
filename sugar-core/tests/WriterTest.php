<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Recorder;
use SugarCraft\Core\Writer;
use SugarCraft\Core\WriteStalledException;

/**
 * The queued writer behind Program and Renderer, and the stall policy that
 * keeps a dead reader from holding the event loop. Stall tests use a socket
 * pair with no reader, so the backlog cannot drain.
 */
final class WriterTest extends TestCase
{
    /** A payload larger than any unix-socket buffer, so a reader-less pair must stall. */
    private const STALL_PAYLOAD = 4_000_000;

    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        $this->assertIsArray($pair);
        return $pair;
    }

    /** @return resource */
    private function memory()
    {
        $out = fopen('php://memory', 'w+b');
        $this->assertNotFalse($out);
        return $out;
    }

    private function recorder(string &$tee): Recorder
    {
        return new class ($tee) implements Recorder {
            public function __construct(private string &$tee)
            {
            }

            public function recordResize(int $cols, int $rows): void
            {
            }

            public function recordInputBytes(string $bytes): void
            {
            }

            public function recordOutput(string $bytes): void
            {
                $this->tee .= $bytes;
            }

            public function recordQuit(): void
            {
            }

            public function close(): void
            {
            }
        };
    }

    /**
     * A child that never reads its stdin. The pipe holds about 64 KiB, so a payload much
     * larger than that stalls the writer for certain. A socket pair does not stall here:
     * its kernel buffer takes several megabytes.
     *
     * @return array{0: resource, 1: resource} the process and its stdin pipe
     */
    private function stalledPipe(): array
    {
        $proc = proc_open(
            ['sleep', '30'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        $this->assertIsResource($proc);
        return [$proc, $pipes[0]];
    }

    /** @param resource $proc @param resource $stdin */
    private function reap($proc, $stdin): void
    {
        if (is_resource($stdin)) {
            fclose($stdin);
        }
        proc_terminate($proc);
        proc_close($proc);
    }

    public function testDetachedEnqueueWritesBeforeItReturns(): void
    {
        $out = $this->memory();
        $writer = Writer::new($out);

        $writer->enqueue("\x1b[2J");
        $writer->enqueue('hello');

        rewind($out);
        $this->assertSame("\x1b[2Jhello", stream_get_contents($out));
        $this->assertTrue($writer->isIdle());
    }

    public function testStalledReaderDoesNotBlockTheLoop(): void
    {
        [$proc, $stdin] = $this->stalledPipe();
        $loop = new StreamSelectLoop();

        $stalled = null;
        $writer = Writer::new($stdin, $loop);
        $writer->attach($loop, 0.5, function (WriteStalledException $e) use (&$stalled): void {
            $stalled = $e;
        });
        $writer->enqueue(str_repeat('B', self::STALL_PAYLOAD));

        // The loop must keep turning while the stream is full: if a write blocked it,
        // this heartbeat would not tick at all.
        $ticks = 0;
        $heartbeat = $loop->addPeriodicTimer(0.05, function () use (&$ticks): void {
            $ticks++;
        });
        $loop->addTimer(1.5, function () use ($loop, $heartbeat): void {
            $loop->cancelTimer($heartbeat);
            $loop->stop();
        });
        $loop->run();

        $this->assertGreaterThanOrEqual(5, $ticks, 'the loop kept running while the reader was stalled');
        $this->assertInstanceOf(WriteStalledException::class, $stalled, 'the stall bound fired');
        $this->assertLessThan(self::STALL_PAYLOAD, $stalled->written);
        $this->assertSame(self::STALL_PAYLOAD, $stalled->total);
        $this->assertTrue($writer->isIdle(), 'a stalled backlog is dropped, not kept');

        $writer->close();
        $this->reap($proc, $stdin);
    }

    public function testStallRejectsTheDrainPromiseWithTheProgressCounters(): void
    {
        [$proc, $stdin] = $this->stalledPipe();
        $loop = new StreamSelectLoop();
        $writer = Writer::new($stdin, $loop);
        $writer->attach($loop, 0.3);
        $writer->enqueue(str_repeat('B', self::STALL_PAYLOAD));

        $rejected = null;
        $writer->drain()->then(null, function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });
        $loop->addTimer(1.5, fn () => $loop->stop());
        $loop->run();

        $this->assertInstanceOf(WriteStalledException::class, $rejected);
        $this->assertStringContainsString('handed off', $rejected->getMessage());
        $this->assertSame(self::STALL_PAYLOAD, $rejected->total);

        $writer->close();
        $this->reap($proc, $stdin);
    }

    public function testDrainResolvesOnceAReaderKeepsUp(): void
    {
        // A real reader: a child that copies its stdin into a file, so the bytes can be checked.
        $dump = sys_get_temp_dir() . '/writer-dump-' . bin2hex(random_bytes(6)) . '.bin';
        $proc = proc_open(['sh', '-c', 'cat > "$0"', $dump], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        $this->assertIsResource($proc);
        $stdin = $pipes[0];
        $loop = new StreamSelectLoop();
        $writer = Writer::new($stdin, $loop);
        $writer->attach($loop, 10.0);

        $payload = str_repeat('C', self::STALL_PAYLOAD);
        $writer->enqueue($payload);
        $resolved = null;
        $writer->drain()->then(function (int $n) use (&$resolved, $loop): void {
            $resolved = $n;
            $loop->stop();
        });
        $loop->addTimer(20.0, fn () => $loop->stop());
        $loop->run();

        $this->assertSame(self::STALL_PAYLOAD, $resolved, 'every queued byte was handed off');
        $writer->close();
        fclose($stdin);
        proc_close($proc);
        $this->assertSame($payload, (string) file_get_contents($dump), 'and the reader received them in order');
        unlink($dump);
    }

    public function testChunksReachTheStreamInEnqueueOrder(): void
    {
        [$keep, $sink] = $this->pair();
        stream_set_blocking($sink, false);
        $loop = new StreamSelectLoop();
        $writer = Writer::new($sink, $loop);
        $writer->attach($loop, 10.0);

        $received = '';
        $loop->addReadStream($keep, function ($stream) use (&$received): void {
            $chunk = fread($stream, 65536);
            if ($chunk !== false) {
                $received .= $chunk;
            }
        });

        $expected = '';
        for ($i = 0; $i < 50; $i++) {
            $piece = str_repeat(chr(65 + ($i % 26)), 40_000 + $i);
            $expected .= $piece;
            $writer->enqueue($piece);
        }
        $writer->drain()->then(fn () => $loop->stop());
        $loop->addTimer(10.0, fn () => $loop->stop());
        $loop->run();

        $this->assertSame($expected, $received, 'a frame never lands behind a later write');

        $writer->close();
        $loop->removeReadStream($keep);
        fclose($keep);
        fclose($sink);
    }

    public function testRecorderTeesOnlyTheBytesTheStreamAccepted(): void
    {
        [$proc, $stdin] = $this->stalledPipe();
        $loop = new StreamSelectLoop();
        $tee = '';
        $writer = Writer::new($stdin, $loop);
        $writer->setRecorder($this->recorder($tee));
        $writer->attach($loop, 0.3);
        $writer->enqueue(str_repeat('R', self::STALL_PAYLOAD));

        $rejected = null;
        $writer->drain()->then(null, function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });
        $loop->addTimer(1.5, fn () => $loop->stop());
        $loop->run();

        $this->assertInstanceOf(WriteStalledException::class, $rejected);
        $this->assertSame($rejected->written, strlen($tee), 'the cassette holds exactly the flushed prefix');
        $this->assertLessThan(self::STALL_PAYLOAD, strlen($tee), 'the dropped tail is not claimed');

        $writer->close();
        $this->reap($proc, $stdin);
    }

    public function testCloseRestoresTheBlockingModeItSwitched(): void
    {
        [$keep, $sink] = $this->pair();
        $this->assertTrue(stream_get_meta_data($sink)['blocked']);

        $loop = new StreamSelectLoop();
        $writer = Writer::new($sink, $loop);
        $writer->enqueue('x');
        $this->assertFalse(stream_get_meta_data($sink)['blocked'], 'attached writes run non-blocking');

        $writer->close();
        $this->assertTrue(stream_get_meta_data($sink)['blocked'], 'close hands the stream back blocking');

        fclose($keep);
        fclose($sink);
    }

    public function testCancelRejectsWaitersAndLeavesTheStreamOpen(): void
    {
        [$proc, $stdin] = $this->stalledPipe();
        $loop = new StreamSelectLoop();
        $writer = Writer::new($stdin, $loop);
        $writer->attach($loop, 30.0);
        $writer->enqueue(str_repeat('K', self::STALL_PAYLOAD));

        $rejected = null;
        $writer->drain()->then(null, function (\Throwable $e) use (&$rejected): void {
            $rejected = $e;
        });
        $this->assertNull($rejected, 'the backlog is still pending before the cancel');
        $writer->cancel();

        $this->assertInstanceOf(\RuntimeException::class, $rejected);
        $this->assertNotInstanceOf(WriteStalledException::class, $rejected, 'a cancel is not a stall');
        $this->assertTrue($writer->isIdle());
        $this->assertTrue(is_resource($stdin), 'the stream belongs to its owner, not the writer');

        $writer->close();
        $this->reap($proc, $stdin);
    }

    public function testDrainWhenIdleResolvesAtOnce(): void
    {
        $writer = Writer::new($this->memory());
        $resolved = null;
        $writer->drain()->then(function (int $n) use (&$resolved): void {
            $resolved = $n;
        });
        $this->assertSame(0, $resolved);
    }
}
