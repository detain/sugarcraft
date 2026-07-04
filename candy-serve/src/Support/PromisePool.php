<?php

declare(strict_types=1);

namespace SugarCraft\Serve\Support;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

/**
 * Bounded-concurrency promise mapper — a `Promise\all()` with a cap on
 * how many tasks may be in flight at once, so a large batch cannot
 * flood the event loop (or a remote backend) all at once.
 *
 * A task counts as in flight from the moment its `futureTick` slot is
 * scheduled until the promise it returned settles. Tasks that return a
 * plain value therefore complete within their own tick; tasks that
 * return a pending promise hold their slot until resolution, which is
 * what makes the bound meaningful.
 *
 * Error semantics mirror `Promise\all()`: the first rejection (or
 * synchronous throw) rejects the aggregate. Callers that need per-item
 * error capture must catch inside `$fn` (see LFSHandler::handleBatchAsync).
 */
final class PromisePool
{
    /** Default in-flight cap when callers don't supply one. */
    public const DEFAULT_LIMIT = 8;

    /**
     * Map $items through $fn with at most $limit tasks in flight,
     * preserving input order in the resolved results.
     *
     * @template T
     * @param list<T> $items
     * @param callable(T, int): mixed $fn may return a plain value or a PromiseInterface
     * @return PromiseInterface<list<mixed>>
     */
    public static function map(
        LoopInterface $loop,
        array $items,
        callable $fn,
        int $limit = self::DEFAULT_LIMIT,
    ): PromiseInterface {
        $items = \array_values($items);
        if ($items === []) {
            return resolve([]);
        }

        $limit = \max(1, $limit);
        $total = \count($items);
        $deferred = new Deferred();

        $results = [];
        $next = 0;
        $active = 0;
        $settled = false;

        $settleError = static function (\Throwable $e) use (&$settled, $deferred): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $deferred->reject($e);
        };

        $launch = static function () use (
            &$launch,
            &$next,
            &$active,
            &$results,
            &$settled,
            $items,
            $fn,
            $limit,
            $total,
            $loop,
            $deferred,
            $settleError,
        ): void {
            while (!$settled && $active < $limit && $next < $total) {
                $index = $next++;
                $active++;

                $loop->futureTick(static function () use (
                    $index,
                    &$launch,
                    &$active,
                    &$results,
                    &$settled,
                    $items,
                    $fn,
                    $total,
                    $deferred,
                    $settleError,
                ): void {
                    if ($settled) {
                        return;
                    }

                    try {
                        $value = $fn($items[$index], $index);
                    } catch (\Throwable $e) {
                        $settleError($e);

                        return;
                    }

                    resolve($value)->then(
                        static function ($resolved) use (
                            $index,
                            &$launch,
                            &$active,
                            &$results,
                            &$settled,
                            $total,
                            $deferred,
                        ): void {
                            if ($settled) {
                                return;
                            }
                            $results[$index] = $resolved;
                            $active--;

                            if (\count($results) === $total) {
                                $settled = true;
                                \ksort($results);
                                $deferred->resolve(\array_values($results));

                                return;
                            }

                            $launch();
                        },
                        $settleError,
                    );
                });
            }
        };

        $launch();

        return $deferred->promise();
    }
}
