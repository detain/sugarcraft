<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Core\Recorder;
use SugarCraft\Core\Writer;

final class WriterTest extends TestCase
{
    /**
     * A unix pair whose writer end has a small send buffer, so a burst overruns
     * it. The default buffer here is 16 MiB, which would swallow the test payloads.
     *
     * @return array{0: resource, 1: resource}
     */
    private function socketPair(): array
    {
        if (!function_exists('socket_import_stream')) {
            self::markTestSkipped('ext-sockets is needed to pin SO_SNDBUF on the writer end');
        }
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertNotFalse($pair);
        $sock = socket_import_stream($pair[0]);
        self::assertNotFalse($sock);
        self::assertTrue(socket_set_option($sock, SOL_SOCKET, SO_SNDBUF, 8192));
        return $pair;
    }

    /** @param list<string> $log shared with the test by reference */
    private function recorder(array &$log): Recorder
    {
        return new class ($log) implements Recorder {
            public function __construct(private array &$log)
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
                $this->log[] = $bytes;
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
     * Read from $peer on the loop, as a terminal would, until $length bytes
     * arrived or the guard fires. The writer's write watch runs on the same loop.
     */
    private function readUntil(StreamSelectLoop $loop, $peer, int $length): string
    {
        stream_set_blocking($peer, false);
        $received = '';
        $reader = $loop->addPeriodicTimer(0.001, function () use ($loop, $peer, $length, &$received): void {
            $chunk = fread($peer, 65536);
            if ($chunk !== false && $chunk !== '') {
                $received .= $chunk;
            }
            if (strlen($received) >= $length) {
                $loop->stop();
            }
        });
        $guard = $loop->addTimer(15.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($reader);
        $loop->cancelTimer($guard);
        return $received;
    }

    public function testSynchronousWriteDeliversEveryByteInOrder(): void
    {
        $out = fopen('php://memory', 'w+');
        $writer = new Writer($out);

        $writer->write('hello ');
        $writer->write('world');

        rewind($out);
        self::assertSame('hello world', stream_get_contents($out));
        self::assertSame(11, $writer->handedOff());
        self::assertSame(0, $writer->pendingBytes());
    }

    public function testAnEmptyWriteIsANoOp(): void
    {
        $out = fopen('php://memory', 'w+');
        $writer = new Writer($out);

        $writer->write('');

        rewind($out);
        self::assertSame('', stream_get_contents($out));
        self::assertSame(0, $writer->handedOff());
    }

    public function testStreamReturnsTheResourceItWrites(): void
    {
        $out = fopen('php://memory', 'w+');

        self::assertSame($out, (new Writer($out))->stream());
    }

    public function testSynchronousWriteTeesTheBytesItActuallySent(): void
    {
        $log = [];
        $writer = new Writer(fopen('php://memory', 'w+'));
        $writer->setRecorder($this->recorder($log));

        $writer->write('abc');

        self::assertSame(['abc'], $log);
    }

    public function testDetachingTheRecorderStopsTheTee(): void
    {
        $log = [];
        $writer = new Writer(fopen('php://memory', 'w+'));
        $writer->setRecorder($this->recorder($log));
        $writer->setRecorder(null);

        $writer->write('silent');

        self::assertSame([], $log);
    }

    public function testWritingToAClosedStreamIsSilent(): void
    {
        $out = fopen('php://memory', 'w+');
        fclose($out);
        $writer = new Writer($out);

        $writer->write('lost');

        self::assertSame(0, $writer->handedOff());
    }

    public function testQueuedWriteToAStalledReaderReturnsAtOnceAndQueuesTheRest(): void
    {
        [$writerEnd, $peer] = $this->socketPair();
        $writer = new Writer($writerEnd);
        $writer->attach(new StreamSelectLoop());
        $payload = str_repeat('x', 4 * 1024 * 1024);

        $started = microtime(true);
        $writer->write($payload);
        $elapsed = microtime(true) - $started;

        self::assertLessThan(1.0, $elapsed, 'a reader that never reads must not block write()');
        self::assertGreaterThan(0, $writer->pendingBytes(), 'the kernel took only part of the burst');
        self::assertSame(strlen($payload), $writer->pendingBytes() + $writer->handedOff());

        $writer->release();
        fclose($writerEnd);
        fclose($peer);
    }

    public function testTheBacklogDrainsInOrderAndOnDrainFiresOnce(): void
    {
        [$writerEnd, $peer] = $this->socketPair();
        $loop = new StreamSelectLoop();
        $writer = new Writer($writerEnd);
        $writer->attach($loop);
        $drains = 0;
        // Fires once every byte has been handed to the socket; the peer may still
        // hold the tail, so the loop is left running for readUntil().
        $writer->onDrain(function () use (&$drains): void {
            $drains++;
        });
        $payload = '';
        for ($i = 0; $i < 3000; $i++) {
            $payload .= sprintf('%06d|', $i) . str_repeat('y', 1000);
        }

        $writer->write($payload);
        self::assertGreaterThan(0, $writer->pendingBytes());
        $received = $this->readUntil($loop, $peer, strlen($payload));

        self::assertSame(1, $drains);
        self::assertSame(0, $writer->pendingBytes());
        self::assertSame($payload, $received);

        $writer->release();
        fclose($writerEnd);
        fclose($peer);
    }

    public function testSmallWritesCoalesceBehindTheBacklogAndStayInOrder(): void
    {
        [$writerEnd, $peer] = $this->socketPair();
        $loop = new StreamSelectLoop();
        $writer = new Writer($writerEnd);
        $writer->attach($loop);
        $big = str_repeat('B', 2 * 1024 * 1024);
        $writer->write($big);
        $smallTotal = 0;
        $expected = $big;
        for ($i = 0; $i < 300; $i++) {
            $piece = sprintf('s%03d;', $i);
            $writer->write($piece);
            $expected .= $piece;
            $smallTotal += strlen($piece);
        }

        self::assertGreaterThanOrEqual(
            $smallTotal,
            $writer->pendingBytes(),
            'the small writes wait in the one backlog rather than each taking a syscall',
        );
        $received = $this->readUntil($loop, $peer, strlen($expected));

        self::assertSame($expected, $received, 'nothing is dropped or reordered');

        $writer->release();
        fclose($writerEnd);
        fclose($peer);
    }

    public function testAStreamThatGoesAwayFiresOnErrorAndDiscardsTheBacklog(): void
    {
        [$writerEnd, $peer] = $this->socketPair();
        $loop = new StreamSelectLoop();
        $writer = new Writer($writerEnd);
        $writer->attach($loop);
        $errors = 0;
        $writer->onError(function () use (&$errors): void {
            $errors++;
        });
        fclose($peer);

        $writer->write(str_repeat('x', 1024 * 1024));
        $guard = $loop->addTimer(2.0, static function () use ($loop): void {
            $loop->stop();
        });
        $loop->run();
        $loop->cancelTimer($guard);

        self::assertSame(1, $errors);
        self::assertSame(0, $writer->pendingBytes());
        $writer->release();
        fclose($writerEnd);
    }

    public function testFlushReportsWhetherTheBacklogWentOutWithinTheDeadline(): void
    {
        [$writerEnd, $peer] = $this->socketPair();
        $writer = new Writer($writerEnd);
        $writer->attach(new StreamSelectLoop());
        $payload = str_repeat('f', 3 * 1024 * 1024);
        $writer->write($payload);

        self::assertFalse($writer->flush(0.2), 'a reader that is not reading leaves the backlog queued');
        self::assertGreaterThan(0, $writer->pendingBytes());

        stream_set_blocking($peer, false);
        $received = '';
        for ($i = 0; $i < 5000 && strlen($received) < strlen($payload); $i++) {
            $chunk = fread($peer, 65536);
            if ($chunk !== false && $chunk !== '') {
                $received .= $chunk;
            }
            if ($writer->pendingBytes() > 0) {
                $writer->flush(0.02);
            }
        }

        self::assertSame($payload, $received);
        self::assertTrue($writer->flush(0.1));
        $writer->release();
        fclose($writerEnd);
        fclose($peer);
    }

    public function testSaturationFollowsTheConfiguredThreshold(): void
    {
        [$writerEnd, $peer] = $this->socketPair();
        $loop = new StreamSelectLoop();
        $writer = new Writer($writerEnd, 1024);
        $writer->attach($loop);

        $writer->write(str_repeat('s', 512 * 1024));
        self::assertTrue($writer->isSaturated(), 'a backlog past the threshold is saturated');

        $this->readUntil($loop, $peer, 512 * 1024);
        self::assertFalse($writer->isSaturated());

        $writer->release();
        fclose($writerEnd);
        fclose($peer);
    }

    public function testAttachIsIdempotentForTheSameLoop(): void
    {
        $out = fopen('php://memory', 'w+');
        $loop = new StreamSelectLoop();
        $writer = new Writer($out);

        $writer->attach($loop);
        $writer->attach($loop);
        $writer->write('ok');

        rewind($out);
        self::assertSame('ok', stream_get_contents($out));
        $writer->release();
    }

    public function testDetachRestoresTheBlockingModeAndFlushesFirst(): void
    {
        [$writerEnd, $peer] = $this->socketPair();
        stream_set_blocking($writerEnd, true);
        $writer = new Writer($writerEnd);
        $writer->attach(new StreamSelectLoop());
        self::assertFalse(stream_get_meta_data($writerEnd)['blocked']);

        $writer->write('tail');

        self::assertTrue($writer->detach(1.0));
        self::assertTrue(stream_get_meta_data($writerEnd)['blocked'], 'the blocking mode is handed back');
        stream_set_blocking($peer, false);
        self::assertSame('tail', fread($peer, 64));
        fclose($writerEnd);
        fclose($peer);
    }

    public function testReleaseDiscardsTheBacklogWithoutFlushing(): void
    {
        [$writerEnd, $peer] = $this->socketPair();
        $writer = new Writer($writerEnd);
        $writer->attach(new StreamSelectLoop());
        $writer->write(str_repeat('r', 2 * 1024 * 1024));
        self::assertGreaterThan(0, $writer->pendingBytes());

        $writer->release();

        self::assertSame(0, $writer->pendingBytes());
        fclose($writerEnd);
        fclose($peer);
    }
}
