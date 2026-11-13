<?php

declare(strict_types=1);

namespace CandyCode\Bundles\Discord\Tests\Support;

use CandyCode\Bundles\Discord\Support\QueueGate;
use CandyCode\Bundles\Discord\Tests\Support\Settle;
use PHPUnit\Framework\TestCase;
use React\EventLoop\LoopInterface;
use React\EventLoop\StreamSelectLoop;

/**
 * Pins for the collect-mode coalescing gate: one flush per window,
 * drop-oldest over the cap, and silence after cancelAll. Timers ride a
 * private loop; every settle is bounded well under a second (E646: no
 * wall-clock budget on anything that could outlive the test).
 */
final class QueueGateTest extends TestCase
{
    private LoopInterface $gateLoop;

    protected function setUp(): void
    {
        $this->gateLoop = new StreamSelectLoop();
    }

    public function testItemsWithinOneWindowFlushTogetherExactlyOnce(): void
    {
        $gate = new QueueGate($this->gateLoop, 20, 20);
        $flushed = null;
        $flush = static function (array $items) use (&$flushed): void {
            $flushed = $items;
        };

        self::assertSame(0, $gate->push('k', 'one', $flush));
        self::assertSame(0, $gate->push('k', 'two', $flush));
        self::assertSame(2, $gate->waiting('k'));
        Settle::await($this->gateLoop, 1000, static function () use (&$flushed): bool {
            return $flushed !== null;
        });

        self::assertSame(['one', 'two'], $flushed);
        self::assertSame(0, $gate->waiting('k'));
        self::assertSame([], $gate->pendingKeys());
    }

    public function testTheCapDropsTheOldestItemAndSaysHowMany(): void
    {
        $gate = new QueueGate($this->gateLoop, 60000, 2);
        $seen = [];
        $flush = static function (array $items) use (&$seen): void {
            $seen[] = $items;
        };

        self::assertSame(0, $gate->push('k', 'a', $flush));
        self::assertSame(0, $gate->push('k', 'b', $flush));
        self::assertSame(1, $gate->push('k', 'c', $flush));
        self::assertSame(2, $gate->waiting('k'));

        $gate->cancelAll();
        self::assertSame(0, $gate->waiting('k'));
        self::assertSame([], $seen, 'cancelAll must silence the window');
    }

    public function testTheGateReArmsForTheNextWindowAfterAFlush(): void
    {
        $gate = new QueueGate($this->gateLoop, 20, 5);
        $count = 0;
        $flush = static function (array $items) use (&$count): void {
            $count += count($items);
        };

        $gate->push('k', 'x', $flush);
        Settle::await($this->gateLoop, 1000, static function () use (&$count): bool {
            return $count >= 1;
        });
        $gate->push('k', 'y', $flush);
        Settle::await($this->gateLoop, 1000, static fn (): bool => $count >= 2);

        self::assertSame(2, $count);
    }

}
