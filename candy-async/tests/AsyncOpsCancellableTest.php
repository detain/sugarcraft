<?php

declare(strict_types=1);

namespace SugarCraft\Async\Tests;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use SugarCraft\Async\AsyncOps;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Async\OperationCancelledException;

use function React\Promise\resolve;

/**
 * Guards the cooperative-cancellation semantics ported from candy-query's
 * CancellableQuery (port Q2): cancelling the token must reject the caller's
 * promise IMMEDIATELY (no loop turn needed), fire the abort hook exactly once,
 * and drop — not resurrect — a late-arriving result. An operation that settles
 * first must never trigger the abort hook.
 *
 * @covers \SugarCraft\Async\AsyncOps::cancellable
 */
final class AsyncOpsCancellableTest extends TestCase
{
    public function testNullTokenReturnsThePromiseUntouched(): void
    {
        $deferred = new Deferred();

        $this->assertSame(
            $deferred->promise(),
            AsyncOps::cancellable($deferred->promise(), null),
            'no token → no wrapper, no overhead',
        );

        $deferred->resolve([]);
    }

    public function testCancellingTheTokenRejectsImmediately(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $error = null;
        AsyncOps::cancellable($pending->promise(), $source->token())->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $source->cancel();

        // Rejection is synchronous — no event-loop turn required.
        $this->assertInstanceOf(OperationCancelledException::class, $error);
        $this->assertStringContainsString('cancelled', $error->getMessage());
    }

    public function testCancellationFiresTheAbortHookExactlyOnce(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $aborts = 0;
        $wrapped = AsyncOps::cancellable($pending->promise(), $source->token(), function () use (&$aborts): void {
            $aborts++;
        });
        $wrapped->then(null, static fn () => null);

        $source->cancel();
        $source->cancel(); // idempotent

        $this->assertSame(1, $aborts, 'abort hook fires exactly once');
    }

    public function testThrowingAbortHookStillRejectsWithCancellation(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $error = null;
        AsyncOps::cancellable($pending->promise(), $source->token(), static function (): void {
            throw new \LogicException('abort failed — must not mask the cancellation');
        })->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $source->cancel();

        $this->assertInstanceOf(OperationCancelledException::class, $error);
    }

    public function testLateResultAfterCancellationIsDropped(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $outcome = null;
        AsyncOps::cancellable($pending->promise(), $source->token())->then(
            static function (mixed $value) use (&$outcome): void {
                $outcome = ['resolved', $value];
            },
            static function (\Throwable $e) use (&$outcome): void {
                $outcome = ['rejected', $e::class];
            },
        );

        $source->cancel();
        $pending->resolve([['id' => 1]]); // the producer answered anyway — too late

        $this->assertSame(['rejected', OperationCancelledException::class], $outcome, 'late result must not resurrect the promise');
    }

    public function testSettledOperationIsNeverAbortedByALaterCancel(): void
    {
        $source = CancellationSource::new();

        $aborts = 0;
        $value = null;
        AsyncOps::cancellable(resolve([['ok' => 1]]), $source->token(), function () use (&$aborts): void {
            $aborts++;
        })->then(static function (array $result) use (&$value): void {
            $value = $result;
        });

        $this->assertSame([['ok' => 1]], $value, 'value passes through the wrapper');

        $source->cancel();

        $this->assertSame(0, $aborts, 'cancelling after completion must not abort an innocent later operation');
    }

    public function testPreCancelledTokenRejectsBeforeTheResultCanLand(): void
    {
        $source = CancellationSource::new();
        $source->cancel();

        $error = null;
        AsyncOps::cancellable(resolve([['ok' => 1]]), $source->token())->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $this->assertInstanceOf(OperationCancelledException::class, $error);
    }

    public function testInnerErrorStillPropagatesThroughTheWrapper(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $error = null;
        AsyncOps::cancellable($pending->promise(), $source->token())->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $pending->reject(new \RuntimeException('server exploded'));

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('server exploded', $error->getMessage());
    }

    public function testTokenCancellationAlsoCancelsTheInnerPromise(): void
    {
        $source = CancellationSource::new();
        $cancellations = 0;
        $inner = new \React\Promise\Promise(
            static function (): void {},
            static function () use (&$cancellations): void {
                $cancellations++;
            },
        );

        $wrapped = AsyncOps::cancellable($inner, $source->token());
        $wrapped->then(null, static fn () => null);

        $source->cancel();

        // Producers that support promise cancellation (subscription dispose)
        // must be reached through the token path too, not only the hook.
        $this->assertSame(1, $cancellations);
    }

    public function testCancellingTheReturnedPromiseBehavesLikeTheToken(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $aborts = 0;
        $wrapped = AsyncOps::cancellable($pending->promise(), $source->token(), function () use (&$aborts): void {
            $aborts++;
        });

        $error = null;
        $wrapped->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $wrapped->cancel();

        $this->assertSame(1, $aborts, 'promise-level cancel (e.g. promise-timer) reaches the abort hook too');
        $this->assertInstanceOf(OperationCancelledException::class, $error);
    }
}
