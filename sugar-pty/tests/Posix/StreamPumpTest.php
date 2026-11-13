<?php

declare(strict_types=1);

namespace SugarCraft\Pty\Tests\Posix;

use PHPUnit\Framework\TestCase;
use SugarCraft\Pty\Posix\StreamPump;

final class StreamPumpTest extends TestCase
{
    /** @return array{0: resource, 1: resource} */
    private function pair(): array
    {
        // Not php://temp/memory: those are not selectable (see CALIBER_LEARNINGS).
        $p = \stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        self::assertIsArray($p);
        return $p;
    }

    public function testEmptyPumpIsVacuousEof(): void
    {
        self::assertSame(StreamPump::EOF, StreamPump::new()->run());
    }

    public function testDrainsEverySourceToEof(): void
    {
        [$a1, $a2] = $this->pair();
        [$b1, $b2] = $this->pair();
        \fwrite($a2, 'out');
        \fwrite($b2, 'err');
        \fclose($a2);
        \fclose($b2);
        $got = [0 => '', 1 => ''];
        $r = StreamPump::new()
            ->withSource($a1, static function (string $c) use (&$got): void { $got[0] .= $c; })
            ->withSource($b1, static function (string $c) use (&$got): void { $got[1] .= $c; })
            ->withTimeout(5.0)
            ->run();
        self::assertSame(StreamPump::EOF, $r);
        self::assertSame(['out', 'err'], $got);
    }

    public function testLargeStderrDoesNotWedgeStdout(): void
    {
        // The deadlock shape DrainsStreamedChild exists for: stream A silent
        // until B (>> one socket buffer) is drained. A is closed by a fork-free
        // trick: close A only from inside B's callback once B has delivered 1 MiB.
        [$a1, $a2] = $this->pair();
        [$b1, $b2] = $this->pair();
        \stream_set_blocking($b2, false);
        $payload = \str_repeat('x', 1 << 20);
        $off = 0;
        $seen = 0;
        $r = StreamPump::new()
            ->withSource($a1, static function (string $c): void {})
            ->withSource($b1, static function (string $c) use (&$seen, &$a2): void {
                $seen += \strlen($c);
                if ($seen >= (1 << 20) && \is_resource($a2)) {
                    \fclose($a2);
                }
            })
            ->withOnTick(static function () use (&$off, $b2, $payload): void {
                if ($off < \strlen($payload)) {
                    $n = @\fwrite($b2, \substr($payload, $off, 65536));
                    $off += \is_int($n) ? $n : 0;
                } elseif (\is_resource($b2)) {
                    \fclose($b2);
                }
            })
            ->withTimeout(10.0)
            ->run();
        self::assertSame(StreamPump::EOF, $r);
        self::assertSame(1 << 20, $seen);
    }

    public function testDeadlineExpiresWithSilentSourceAndDoesNotCloseIt(): void
    {
        [$a1, $a2] = $this->pair();
        $t = \microtime(true);
        $r = StreamPump::new()->withSource($a1, static function (string $c): void {})
            ->withTimeout(0.15)->withTickUs(20_000)->run();
        self::assertSame(StreamPump::EXPIRED, $r);
        self::assertLessThan(1.5, \microtime(true) - $t);
        self::assertTrue(\is_resource($a1), 'the pump never closes caller streams');
        self::assertTrue(\is_resource($a2));
    }

    public function testShouldStopEndsRun(): void
    {
        [$a1, $keep] = $this->pair();
        $n = 0;
        $r = StreamPump::new()->withSource($a1, static function (string $c): void {})
            ->withTickUs(1000)
            ->withShouldStop(static function () use (&$n): bool { return ++$n > 3; })
            ->run();
        self::assertSame(StreamPump::STOPPED, $r);
        self::assertTrue(\is_resource($keep));
    }

    public function testPersistentSourceEofDoesNotEndRun(): void
    {
        [$a1, $a2] = $this->pair();
        [$b1, $b2] = $this->pair();
        \fclose($b2); // persistent source at EOF immediately
        \fwrite($a2, 'hi');
        $got = '';
        $r = StreamPump::new()
            ->withSource($a1, static function (string $c) use (&$got, &$a2): void { $got .= $c; \fclose($a2); })
            ->withSource($b1, static function (string $c): void {}, false)
            ->withTickUs(1000)
            ->run();
        self::assertSame(StreamPump::EOF, $r);
        self::assertSame('hi', $got);

        // Only persistent sources: lives until the deadline.
        [$c1, $c2] = $this->pair();
        \fclose($c2);
        $r = StreamPump::new()->withSource($c1, static function (string $c): void {}, false)
            ->withTimeout(0.1)->withTickUs(10_000)->run();
        self::assertSame(StreamPump::EXPIRED, $r);
    }

    public function testBlockingModeRestoredEvenWhenCallbackThrows(): void
    {
        [$a1, $a2] = $this->pair();
        \fwrite($a2, 'x');
        try {
            StreamPump::new()->withSource($a1, static function (string $c): void {
                throw new \LogicException('boom');
            })->withTimeout(2.0)->run();
            self::fail('expected throw');
        } catch (\LogicException) {
        }
        self::assertTrue(\stream_get_meta_data($a1)['blocked']);
    }

    public function testClosedStreamYieldsSelectFailedNotAHang(): void
    {
        [$a1, $keep] = $this->pair();
        $r = StreamPump::new()->withSource($a1, static function (string $c): void {})
            ->withTickUs(1000)
            ->withOnTick(static function () use ($a1): void { if (\is_resource($a1)) { \fclose($a1); } })
            ->withTimeout(2.0)->run();
        self::assertContains($r, [StreamPump::SELECT_FAILED, StreamPump::EXPIRED]);
        self::assertNotSame(StreamPump::EOF, $r);
        self::assertTrue(\is_resource($keep));
    }

    public function testSignalDuringSelectIsRiddenOutAndBytesAfterItStillArrive(): void
    {
        if (!\function_exists('pcntl_alarm') || !\function_exists('pcntl_signal')) {
            self::markTestSkipped('ext-pcntl required');
        }
        [$a1, $a2] = $this->pair();
        $handled = 0;
        $prevAsync = \pcntl_async_signals(true);
        \pcntl_signal(SIGALRM, static function () use (&$handled, $a2): void {
            $handled++;
            \fwrite($a2, 'late');
            \fclose($a2);
        });
        $got = '';
        try {
            \pcntl_alarm(1);
            // Long tick: the alarm lands INSIDE the select, which returns EINTR.
            $r = StreamPump::new()
                ->withSource($a1, static function (string $c) use (&$got): void { $got .= $c; })
                ->withTickUs(3_000_000)
                ->withTimeout(8.0)
                ->run();
        } finally {
            \pcntl_alarm(0);
            \pcntl_signal(SIGALRM, SIG_DFL);
            \pcntl_async_signals($prevAsync);
        }
        self::assertSame(1, $handled);
        self::assertSame('late', $got, 'EINTR must not be treated as end-of-output');
        self::assertSame(StreamPump::EOF, $r);
    }

    public function testRejectsBadArguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamPump::new()->withChunkBytes(0);
    }

    public function testWithSourceRejectsNonResource(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StreamPump::new()->withSource('nope', static function (string $c): void {});
    }

    public function testWithersDoNotMutateReceiver(): void
    {
        $a = StreamPump::new();
        $b = $a->withTickUs(5);
        self::assertNotSame($a, $b);
    }

    public function testWriteStreamDeliversMoreThanAPipeBufferToASlowReader(): void
    {
        [$w, $r] = $this->pair();
        $payload = \str_repeat('y', 1 << 20);
        // Reader is the pump in the same process: interleave via onTick-free
        // approach — fork-free: drain in a handler is impossible, so write in
        // slices bounded by what the peer has drained.
        \stream_set_blocking($r, false);
        $sent = 0;
        $drained = 0;
        while ($sent < \strlen($payload)) {
            $sent += StreamPump::writeStream($w, \substr($payload, $sent, 65536), 0.05);
            while (($c = \fread($r, 1 << 16)) !== false && $c !== '') {
                $drained += \strlen($c);
            }
        }
        while (($c = \fread($r, 1 << 16)) !== false && $c !== '') {
            $drained += \strlen($c);
        }
        self::assertSame(1 << 20, $sent);
        self::assertSame(1 << 20, $drained);
        self::assertTrue(\stream_get_meta_data($w)['blocked'], 'blocking mode restored');
    }

    public function testWriteStreamReturnsPartialCountAtDeadlineWhenReaderStalls(): void
    {
        [$w, $r] = $this->pair();
        $t = \microtime(true);
        $n = StreamPump::writeStream($w, \str_repeat('z', 128 << 20), 0.2);
        self::assertGreaterThan(0, $n);
        self::assertLessThan(128 << 20, $n);
        self::assertLessThan(3.0, \microtime(true) - $t);
        self::assertNotNull($r);
    }

    public function testWriteStreamToClosedPeerReturnsWithoutHanging(): void
    {
        [$w, $r] = $this->pair();
        \fclose($r);
        $n = StreamPump::writeStream($w, 'abc', 1.0);
        self::assertLessThanOrEqual(3, $n);
    }

    public function testWriteStreamEmptyAndInvalid(): void
    {
        [$w] = $this->pair();
        self::assertSame(0, StreamPump::writeStream($w, ''));
        $this->expectException(\InvalidArgumentException::class);
        StreamPump::writeStream('x', 'a');
    }
}
