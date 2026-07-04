<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin;

use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;
use SugarCraft\Async\CancellationSource;
use SugarCraft\Query\Admin\CancellableQuery;
use SugarCraft\Query\Admin\QueryCancelledException;

use function React\Promise\resolve;

/**
 * Guards the cooperative-cancellation semantics (plan 5.1): cancelling the
 * candy-async token must reject the caller's promise IMMEDIATELY (no loop
 * turn needed), fire the server-side abort hook exactly once, and drop — not
 * resurrect — a late-arriving driver result. A query that settles first must
 * never trigger the server-side kill.
 */
final class CancellableQueryTest extends TestCase
{
    public function testNullTokenReturnsThePromiseUntouched(): void
    {
        $deferred = new Deferred();

        $this->assertSame(
            $deferred->promise(),
            CancellableQuery::wrap($deferred->promise(), null),
            'no token → no wrapper, no overhead',
        );

        $deferred->resolve([]);
    }

    public function testCancellingTheTokenRejectsImmediately(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $error = null;
        CancellableQuery::wrap($pending->promise(), $source->token())->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $source->cancel();

        // Rejection is synchronous — no event-loop turn required.
        $this->assertInstanceOf(QueryCancelledException::class, $error);
    }

    public function testCancellationFiresTheServerCancelHookExactlyOnce(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $kills = 0;
        $wrapped = CancellableQuery::wrap($pending->promise(), $source->token(), function () use (&$kills): void {
            $kills++;
        });
        $wrapped->then(null, static fn () => null);

        $source->cancel();
        $source->cancel(); // idempotent

        $this->assertSame(1, $kills, 'KILL hook fires exactly once');
    }

    public function testLateDriverResultAfterCancellationIsDropped(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $outcome = null;
        CancellableQuery::wrap($pending->promise(), $source->token())->then(
            static function (mixed $rows) use (&$outcome): void {
                $outcome = ['resolved', $rows];
            },
            static function (\Throwable $e) use (&$outcome): void {
                $outcome = ['rejected', $e::class];
            },
        );

        $source->cancel();
        $pending->resolve([['id' => 1]]); // the server answered anyway — too late

        $this->assertSame(['rejected', QueryCancelledException::class], $outcome, 'late result must not resurrect the promise');
    }

    public function testSettledQueryIsNeverKilledByALaterCancel(): void
    {
        $source = CancellationSource::new();

        $kills = 0;
        $rows = null;
        CancellableQuery::wrap(resolve([['ok' => 1]]), $source->token(), function () use (&$kills): void {
            $kills++;
        })->then(static function (array $value) use (&$rows): void {
            $rows = $value;
        });

        $this->assertSame([['ok' => 1]], $rows, 'value passes through the wrapper');

        $source->cancel();

        $this->assertSame(0, $kills, 'cancelling after completion must not KILL an innocent later query');
    }

    public function testPreCancelledTokenRejectsBeforeTheQueryResultCanLand(): void
    {
        $source = CancellationSource::new();
        $source->cancel();

        $error = null;
        CancellableQuery::wrap(resolve([['ok' => 1]]), $source->token())->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $this->assertInstanceOf(QueryCancelledException::class, $error);
    }

    public function testDriverErrorStillPropagatesThroughTheWrapper(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $error = null;
        CancellableQuery::wrap($pending->promise(), $source->token())->then(
            null,
            static function (\Throwable $e) use (&$error): void {
                $error = $e;
            },
        );

        $pending->reject(new \RuntimeException('server exploded'));

        $this->assertInstanceOf(\RuntimeException::class, $error);
        $this->assertSame('server exploded', $error->getMessage());
    }

    public function testCancellingTheReturnedPromiseBehavesLikeTheToken(): void
    {
        $source = CancellationSource::new();
        $pending = new Deferred();

        $kills = 0;
        $wrapped = CancellableQuery::wrap($pending->promise(), $source->token(), function () use (&$kills): void {
            $kills++;
        });

        $error = null;
        $wrapped->then(null, static function (\Throwable $e) use (&$error): void {
            $error = $e;
        });

        $wrapped->cancel();

        $this->assertSame(1, $kills, 'promise-level cancel (e.g. promise-timer) reaches the KILL hook too');
        $this->assertInstanceOf(QueryCancelledException::class, $error);
    }
}
