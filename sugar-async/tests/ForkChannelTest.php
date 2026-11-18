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
}
