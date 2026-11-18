<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Await;
use SugarCraft\Async\ForkChannel;
use React\Promise\Deferred;

final class ForkChannelTest extends TestCase
{
    public function testFramesArriveWholeAndInOrderInBothDirections(): void
    {
        [$a, $b] = ForkChannel::pair();
        $loop = new StreamSelectLoop();
        $received = [];
        $done = new Deferred();
        $b->onFrame(static function (string $frame) use (&$received, $done): void {
            $received[] = $frame;
            if (count($received) === 3) {
                $done->resolve(true);
            }
        }, $loop);
        $a->send('first');
        $a->send('');
        $a->send(str_repeat('x', 60000));

        Await::until($done->promise(), 2.0, $loop);

        self::assertSame(['first', '', str_repeat('x', 60000)], $received);
        $a->close();
        $b->close();
    }

    public function testAFrameReplyTravelsTheOtherWay(): void
    {
        [$a, $b] = ForkChannel::pair();
        $loop = new StreamSelectLoop();
        $reply = new Deferred();
        $a->onFrame(static fn (string $frame) => $reply->resolve($frame), $loop);
        $b->send('pong');

        self::assertSame('pong', Await::until($reply->promise(), 2.0, $loop));
        $a->close();
        $b->close();
    }

    public function testSendRefusesAFrameOverTheCap(): void
    {
        [$a, $b] = ForkChannel::pair();

        $this->expectException(\InvalidArgumentException::class);
        try {
            $a->send(str_repeat('x', ForkChannel::MAX_FRAME_BYTES + 1));
        } finally {
            $a->close();
            $b->close();
        }
    }

    public function testClosingTheChannelRunsOnCloseOnceAndRefusesFurtherSends(): void
    {
        [$a, $b] = ForkChannel::pair();
        $closed = 0;
        $a->onClose(static function () use (&$closed): void {
            $closed++;
        });

        $a->close();
        $a->close();

        self::assertFalse($a->isOpen());
        self::assertSame(1, $closed);
        $this->expectException(\RuntimeException::class);
        try {
            $a->send('late');
        } finally {
            $b->close();
        }
    }

    public function testThePeerHangingUpClosesTheChannelOnTheLoop(): void
    {
        [$a, $b] = ForkChannel::pair();
        $loop = new StreamSelectLoop();
        $gone = new Deferred();
        $a->onFrame(static fn () => null, $loop);
        $a->onClose(static fn () => $gone->resolve(true));

        $b->close();

        self::assertTrue(Await::until($gone->promise(), 2.0, $loop));
        self::assertFalse($a->isOpen());
    }

    public function testASecondFrameHandlerIsRefused(): void
    {
        [$a, $b] = ForkChannel::pair();
        $loop = new StreamSelectLoop();
        $a->onFrame(static fn () => null, $loop);

        try {
            $this->expectException(\LogicException::class);
            $a->onFrame(static fn () => null, $loop);
        } finally {
            $a->close();
            $b->close();
        }
    }

    /**
     * Regression: once onFrame() makes the stream nonblocking, a frame larger
     * than the kernel buffer used to abort the send on the first 0-byte write
     * (temporary backpressure) and report the peer as closed. The reader runs
     * in a forked child so the parent's send really does have to wait for it.
     */
    public function testALargeFrameSurvivesBackpressureOnANonblockingEnd(): void
    {
        if (!\function_exists('pcntl_fork')) {
            self::markTestSkipped('needs ext-pcntl to run the reader in another process');
        }
        $size = 4 * 1024 * 1024;
        [$parent, $child] = ForkChannel::pair();
        $pid = \pcntl_fork();
        if ($pid === 0) {
            $parent->close();
            // Let the parent fill the kernel buffer before the first read.
            usleep(300000);
            $loop = new StreamSelectLoop();
            $received = null;
            $child->onFrame(static function (string $frame) use (&$received, $loop): void {
                $received = $frame;
                $loop->stop();
            }, $loop);
            $loop->run();
            exit($received !== null && strlen($received) === $size ? 0 : 1);
        }
        $child->close();
        $loop = new StreamSelectLoop();
        $parent->onFrame(static fn (string $frame) => null, $loop);

        try {
            $parent->send(str_repeat('x', $size));
        } finally {
            $parent->close();
        }
        \pcntl_waitpid($pid, $status);

        self::assertTrue(\pcntl_wifexited($status) && \pcntl_wexitstatus($status) === 0, 'the reader must receive the whole frame');
    }

    /**
     * Regression: a read holding thousands of small frames must split them all
     * in order. The framing walks one offset and trims the buffer once, so the
     * frames straddling the 64 KiB read boundary are the ones that would break
     * if the offset bookkeeping drifted.
     */
    public function testManySmallFramesInOneReadAllArriveInOrder(): void
    {
        [$a, $b] = ForkChannel::pair();
        $loop = new StreamSelectLoop();
        $count = 5000;
        $received = [];
        $done = new Deferred();
        $b->onFrame(static function (string $frame) use (&$received, $done, $count): void {
            $received[] = $frame;
            if (count($received) === $count) {
                $done->resolve(true);
            }
        }, $loop);
        $expected = [];
        for ($i = 0; $i < $count; $i++) {
            $expected[] = sprintf('%010d', $i);
            $a->send(sprintf('%010d', $i));
        }

        Await::until($done->promise(), 5.0, $loop);

        self::assertSame($expected, $received);
        $a->close();
        $b->close();
    }
}
