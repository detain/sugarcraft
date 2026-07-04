<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use SugarCraft\Async\CancellationToken;

/**
 * Bridges candy-async's {@see CancellationToken} onto a ReactPHP query promise.
 *
 * Cancellation is cooperative and two-sided:
 *
 *  - Client side (always): the caller's promise rejects IMMEDIATELY with
 *    {@see QueryCancelledException}; a late-arriving driver result is dropped,
 *    never resurrected.
 *  - Server side (driver-dependent): the `$onServerCancel` hook fires once so
 *    the connection can stop the query on the server (MySQL: `KILL QUERY` on a
 *    side-channel connection; Postgres: PgAsync's protocol CancelRequest via
 *    the underlying promise's own cancel chain).
 *
 * The wrapped promise is also cancel()led so drivers that support promise
 * cancellation (RxPHP's toPromise → subscription dispose) clean up themselves.
 *
 * Note: {@see CancellationToken} has no callback unregistration, so a token
 * reused across many queries accumulates one (settled-guarded, cheap) closure
 * per query until the token fires. Prefer one token per logical operation.
 */
final class CancellableQuery
{
    /**
     * @template T
     * @param PromiseInterface<T> $promise The in-flight query
     * @param CancellationToken|null $cancellation Optional cancel handle; null returns the promise untouched
     * @param callable(): void|null $onServerCancel Server-side abort hook (e.g. KILL QUERY); errors are swallowed —
     *                                              cancellation must never fail the caller a second time
     * @return PromiseInterface<T>
     */
    public static function wrap(
        PromiseInterface $promise,
        ?CancellationToken $cancellation,
        ?callable $onServerCancel = null,
    ): PromiseInterface {
        if ($cancellation === null) {
            return $promise;
        }

        $settled = false;

        $abort = static function () use (&$settled, $promise, $onServerCancel): void {
            if ($settled) {
                return;
            }
            $settled = true;
            if ($onServerCancel !== null) {
                try {
                    $onServerCancel();
                } catch (\Throwable) {
                    // A failed server-side kill must not mask the cancellation.
                }
            }
            $promise->cancel();
        };

        // Cancelling the returned promise directly (e.g. via promise-timer)
        // behaves exactly like cancelling through the token.
        $deferred = new Deferred(static function () use ($abort): never {
            $abort();
            throw new QueryCancelledException('Async query cancelled');
        });

        // onCancel fires synchronously when the token is already cancelled,
        // so a pre-cancelled token rejects before the query result can land.
        $cancellation->onCancel(static function () use (&$settled, $abort, $deferred): void {
            if ($settled) {
                return;
            }
            $abort();
            $deferred->reject(new QueryCancelledException('Async query cancelled'));
        });

        $promise->then(
            static function (mixed $value) use (&$settled, $deferred): void {
                if (!$settled) {
                    $settled = true;
                    $deferred->resolve($value);
                }
            },
            static function (\Throwable $e) use (&$settled, $deferred): void {
                if (!$settled) {
                    $settled = true;
                    $deferred->reject($e);
                }
            },
        );

        return $deferred->promise();
    }

    private function __construct()
    {
        // Static helper only.
    }
}
