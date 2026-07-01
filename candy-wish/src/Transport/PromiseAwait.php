<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Wish\Lang;

/**
 * Shared synchronous-promise await helper.
 *
 * Drives a promise to synchronously settle using the event loop.
 * Wraps with react/promise-timer for timeout enforcement so the
 * 30-second ceiling is enforced by the loop instead of a busy spin.
 *
 * Mirrors charmbracelet/wish PromiseDispatch.awaitPromise.
 */
final class PromiseAwait
{
    private function __construct()
    {
    }

    /**
     * Synchronously wait for a promise to settle.
     *
     * @param PromiseInterface $promise The promise to await
     * @param float            $timeout  Timeout in seconds (default 30)
     *
     * @throws \Throwable if the promise rejects
     * @throws \RuntimeException if the timeout is reached
     */
    public static function settle(PromiseInterface $promise, float $timeout = 30.0): void
    {
        $ex = null;
        $done = false;

        // Attach callbacks to detect when the original promise settles.
        $promise->then(
            function () use (&$done): void { $done = true; },
            function (\Throwable $e) use (&$ex, &$done): void {
                $ex = $e;
                $done = true;
            },
        );

        // If the promise already settled synchronously, handle immediately.
        if ($done) {
            if ($ex !== null) {
                throw $ex;
            }
            return;
        }

        // Wrap with react/promise-timer to enforce the timeout ceiling.
        $timed = \React\Promise\Timer\timeout($promise, $timeout, Loop::get());

        // When the timeout fires (or the wrapped promise settles), catch it.
        // Only set $ex if not already set to preserve the original rejection.
        $timed->then(
            null,
            function (\Throwable $e) use (&$ex, &$done): void {
                if ($ex === null) {
                    $ex = $e;
                    $done = true;
                }
            },
        );

        // Drive the loop until the timeout promise settles.
        Loop::run();

        if ($ex !== null) {
            throw $ex;
        }
    }
}
