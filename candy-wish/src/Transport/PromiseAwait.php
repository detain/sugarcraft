<?php

declare(strict_types=1);

namespace SugarCraft\Wish\Transport;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\Wish\Context;

/**
 * Shared synchronous-promise await helper.
 *
 * Drives a promise to synchronously settle using the event loop.
 * Wraps with react/promise-timer for timeout enforcement so the
 * 30-second ceiling is enforced by the loop instead of a busy spin.
 * (The ceiling is PRE-EXISTING and bounds an await of already-issued
 * work — E730 deliberately keeps it as-is.)
 *
 * E730 (round 83): this is invoked from exactly one place — the
 * transport stack walk ({@see DispatchesMiddlewareStack}) — and now
 * consults the session Context cooperatively: a context that is
 * already done when the await is entered never gets to block the
 * loop, and its cancellation error is raised instead.
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
     * @param Context|null     $ctx      Live session context; when done at
     *                                   entry the await is REFUSED and the
     *                                   context error is thrown instead.
     *                                   Cancellation is consulted at entry
     *                                   only — the loop is never polled for
     *                                   it (no extra timers); aborting
     *                                   mid-await stays the promise
     *                                   producer's own responsibility.
     *
     * @throws \Throwable if the promise rejects or the context is done
     * @throws \RuntimeException if the timeout is reached
     */
    public static function settle(PromiseInterface $promise, float $timeout = 30.0, ?Context $ctx = null): void
    {
        // Cooperative cancellation (E730): never enter the await for work
        // whose caller is already gone — abort with the context error
        // instead of blocking up to the timeout ceiling on it.
        $abort = $ctx?->done() === true ? $ctx->err() : null;
        if ($abort !== null) {
            throw $abort;
        }

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
