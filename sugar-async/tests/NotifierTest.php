<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\EventLoop\StreamSelectLoop;
use SugarCraft\Async\Await;
use SugarCraft\Async\Notifier;
use SugarCraft\Async\OperationCancelledException;
use SugarCraft\Async\TimeoutException;

final class NotifierTest extends TestCase
{
    public function testAWaitParkedBeforeANotifyResolvesOnIt(): void
    {
        $loop = new StreamSelectLoop();
        $notifier = Notifier::new($loop);
        $promise = $notifier->wait(2.0);
        $loop->addTimer(0.01, static fn () => $notifier->notify());

        self::assertNull(Await::until($promise, 3.0, $loop));
        self::assertSame(0, $notifier->waiting());
    }

    public function testANotifyWithNoWaiterIsRememberedForTheNextWait(): void
    {
        $loop = new StreamSelectLoop();
        $notifier = Notifier::new($loop);
        $notifier->notify();

        // Regression for a lost wake-up: a message posted between two waits
        // must not be missed, so the next wait resolves without a second notify.
        self::assertNull(Await::until($notifier->wait(1.0), 2.0, $loop));
    }

    public function testPendingSignalsDoNotStackAndAreConsumedOnce(): void
    {
        $loop = new StreamSelectLoop();
        $notifier = Notifier::new($loop);
        $notifier->notify();
        $notifier->notify();
        $notifier->notify();

        self::assertNull(Await::until($notifier->wait(1.0), 2.0, $loop));

        $this->expectException(TimeoutException::class);
        Await::until($notifier->wait(0.01), 2.0, $loop);
    }

    public function testOneNotifyWakesEveryParkedWaiter(): void
    {
        $loop = new StreamSelectLoop();
        $notifier = Notifier::new($loop);
        $first = $notifier->wait(2.0);
        $second = $notifier->wait(2.0);
        self::assertSame(2, $notifier->waiting());

        $notifier->notify();

        self::assertNull(Await::until($first, 2.0, $loop));
        self::assertNull(Await::until($second, 2.0, $loop));
        self::assertSame(0, $notifier->waiting());
    }

    public function testABoundedWaitRejectsWithATypedTimeout(): void
    {
        $loop = new StreamSelectLoop();
        $notifier = Notifier::new($loop);

        try {
            Await::until($notifier->wait(0.01), 2.0, $loop);
            self::fail('An unnotified bounded wait must time out');
        } catch (TimeoutException $e) {
            self::assertSame(0.01, $e->seconds());
        }
        self::assertSame(0, $notifier->waiting(), 'a timed-out waiter is withdrawn');
    }

    public function testCancellingAWaitWithdrawsIt(): void
    {
        $loop = new StreamSelectLoop();
        $notifier = Notifier::new($loop);
        $promise = $notifier->wait();
        self::assertSame(1, $notifier->waiting());

        $promise->cancel();

        self::assertSame(0, $notifier->waiting());
        $this->expectException(OperationCancelledException::class);
        Await::until($promise, 1.0, $loop);
    }

    public function testAnUnboundedWaitStaysPendingUntilNotified(): void
    {
        $loop = new StreamSelectLoop();
        $notifier = Notifier::new($loop);
        $promise = $notifier->wait();
        $loop->addTimer(0.02, static fn () => $notifier->notify());

        self::assertNull(Await::until($promise, 2.0, $loop));
    }

    /** @dataProvider invalidSecondsProvider */
    public function testRejectsANonPositiveOrNonFiniteBound(float $seconds): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Notifier::new(new StreamSelectLoop())->wait($seconds);
    }

    /** @return iterable<string, array{float}> */
    public static function invalidSecondsProvider(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-2.0];
        yield 'infinite' => [INF];
    }
}
