<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Tests\Transport;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use React\Promise;
use SugarCraft\Wish\CancellationException;
use SugarCraft\Wish\Context;
use SugarCraft\Wish\DeadlineExceededException;
use SugarCraft\Wish\Transport\PromiseAwait;

/**
 * Direct pins for the cooperative-cancellation entry guard added to
 * {@see PromiseAwait::settle()} by E730 (round 83): a context that is
 * already done when the await is entered must refuse the await and
 * raise its own error, never enter the loop.
 *
 * The never-settling promises below would otherwise block until the
 * (pre-existing) timeout ceiling, so each case passes a deliberately
 * tight 0.2 s cap: with the guard the context error lands instantly;
 * if the guard is removed the timeout error lands after 200 ms and the
 * type assertion goes red — discriminating and fast either way.
 */
final class PromiseAwaitTest extends TestCase
{
    /**
     * @return iterable<string, array{Context, class-string<\Throwable>}>
     */
    public static function doneContextPolarities(): iterable
    {
        $cancelled = Context::background()->withCancelable();
        $cancelled->cancel();
        yield 'cancelled context' => [$cancelled, CancellationException::class];
        yield 'expired deadline' => [
            Context::background()->withDeadline(new \DateTimeImmutable('-1 second')),
            DeadlineExceededException::class,
        ];
    }

    #[DataProvider('doneContextPolarities')]
    public function testADoneContextRefusesTheAwaitAndRaisesItsOwnError(Context $ctx, string $expected): void
    {
        $never = new Promise\Promise(static function (): void {
            // Deliberately never settles: only the entry guard can end this
            // await before the timeout ceiling fires.
        });

        $failure = null;
        try {
            PromiseAwait::settle($never, 0.2, $ctx);
        } catch (\Throwable $e) {
            $failure = $e;
        }

        $this->assertNotNull($failure, 'a done context must abort the await with an error');
        $this->assertInstanceOf($expected, $failure);
    }

    public function testTheCancellationReasonRidesThroughTheRefusal(): void
    {
        $ctx = Context::background()->withCancelable();
        $reason = new \RuntimeException('ssh client gone');
        $ctx->cancel($reason);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ssh client gone');

        PromiseAwait::settle(new Promise\Promise(static function (): void {}), 0.2, $ctx);
    }

    public function testALiveContextStillAwaitsToCompletion(): void
    {
        $loop = \React\EventLoop\Loop::get();
        $value = null;
        $pending = new Promise\Promise(static function (callable $resolve) use ($loop): void {
            $loop->addTimer(0.001, static fn () => $resolve('settled-by-loop'));
        });

        // Cancelable but NOT cancelled — done() is false, the guard must
        // stand aside and the loop-driven work must complete as before.
        PromiseAwait::settle($pending, 5.0, Context::background()->withCancelable());

        $captured = null;
        $pending->then(static function (mixed $v) use (&$captured): void {
            $captured = $v;
        });
        $this->assertSame('settled-by-loop', $captured);
    }

    public function testSettleWithoutAContextKeepsTheLegacyShape(): void
    {
        $failure = null;
        try {
            PromiseAwait::settle(Promise\reject(new \RuntimeException('plain rejection')), 0.2);
        } catch (\RuntimeException $e) {
            $failure = $e;
        }
        $this->assertNotNull($failure);
        $this->assertSame('plain rejection', $failure->getMessage());
    }
}
