<?php

declare(strict_types=1);

namespace CandyCode\Bundles\MsTeams\Tests\Support;

use CandyCode\Bundles\MsTeams\Support\QueueGate;
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
        self::settleGate($this->gateLoop, 1000, static fn (): bool => $flushed === null);

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
        self::settleGate($this->gateLoop, 1000, static fn (): bool => $count === 0);
        $gate->push('k', 'y', $flush);
        self::settleGate($this->gateLoop, 1000, static fn (): bool => $count < 2);

        self::assertSame(2, $count);
    }

    /**
     * msteams-27 pin: the docs/SPEC.md §9.2 shipped defaults are the single
     * source of truth — the constructor fallbacks AND the boot seam in
     * MsTeamsChannel both read these constants, so a drift here is loud at
     * zero wall cost (mutation: change the constant, this test goes red).
     */
    public function testTheShippedDefaultsAreTheDocumentedConstants(): void
    {
        $params = (new \ReflectionMethod(QueueGate::class, '__construct'))->getParameters();

        self::assertSame(500, QueueGate::DEFAULT_DEBOUNCE_MS);
        self::assertSame(20, QueueGate::DEFAULT_MAX_PENDING);
        self::assertTrue($params[1]->isDefaultValueConstant());
        self::assertTrue($params[2]->isDefaultValueConstant());
        self::assertSame(QueueGate::DEFAULT_DEBOUNCE_MS, $params[1]->getDefaultValue());
        self::assertSame(QueueGate::DEFAULT_MAX_PENDING, $params[2]->getDefaultValue());
    }

    private static function settleGate(LoopInterface $loop, int $capMs, callable $stillWaiting): void
    {
        $deadline = microtime(true) + $capMs / 1000.0;
        while ($stillWaiting() && microtime(true) < $deadline) {
            $loop->addTimer(0.01, static function (): void {
            });
            $loop->run();
        }
    }
}
