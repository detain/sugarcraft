<?php

declare(strict_types=1);

namespace SugarCraft\Query\Admin;

use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use React\Promise\Timer\TimeoutException;

use function React\Promise\Timer\timeout;

/**
 * Deadline wrapper for async admin/query promises.
 *
 * Without a timeout, a network partition mid-query leaves the promise pending
 * forever: react/mysql and PgAsync only reject on an observed socket error,
 * and a silently dropped route never produces one. The admin tick's
 * single-in-flight gate (App's adminLoading) would then stay latched and all
 * admin polling would stop. Wrapping every async query with a deadline turns
 * that hang into an ordinary rejection the existing ->otherwise() error paths
 * already render.
 *
 * Built on react/promise-timer (pulled in by react/mysql): its timeout()
 * rejects when the timer fires and cancels the underlying promise, so a
 * late-arriving result is ignored rather than resurrected.
 */
final class QueryTimeout
{
    /**
     * Default per-query deadline in seconds. Generous on purpose: sys.* admin
     * reports on busy servers legitimately run for many seconds; the deadline
     * only needs to beat "forever".
     */
    public const DEFAULT_SECONDS = 30.0;

    /**
     * Apply a deadline to a query promise.
     *
     * promise-timer's timeout() cancel()s the underlying promise when the
     * deadline fires; for drivers whose promise cancellation is a client-side
     * no-op (react/mysql), pass `$onTimeout` to abort the query server-side
     * too — otherwise a timed-out query keeps burning the server long after
     * the caller has given up on it (see plan 5.1).
     *
     * @template T
     * @param PromiseInterface<T> $promise The in-flight query
     * @param float $seconds Deadline; <= 0 disables the timeout entirely
     * @param LoopInterface|null $loop Loop the timer runs on (default: global loop)
     * @param callable(): void|null $onTimeout Server-side abort hook, fired once when the deadline hits;
     *                                         errors are swallowed so a failed kill can't mask the timeout
     * @return PromiseInterface<T>
     */
    public static function wrap(PromiseInterface $promise, float $seconds, ?LoopInterface $loop = null, ?callable $onTimeout = null): PromiseInterface
    {
        if ($seconds <= 0.0) {
            return $promise;
        }

        return timeout($promise, $seconds, $loop)->otherwise(
            static function (\Throwable $e) use ($seconds, $onTimeout): never {
                // Re-throw with a message the result panes can show verbatim;
                // promise-timer's own message doesn't say what timed out.
                if ($e instanceof TimeoutException) {
                    if ($onTimeout !== null) {
                        try {
                            $onTimeout();
                        } catch (\Throwable) {
                            // The timeout rejection below is the caller-facing
                            // truth; a failed server-side kill must not replace it.
                        }
                    }
                    throw new \RuntimeException(
                        sprintf('Async query timed out after %.1fs', $seconds),
                        0,
                        $e,
                    );
                }
                throw $e;
            },
        );
    }

    private function __construct()
    {
        // Static helper only.
    }
}
