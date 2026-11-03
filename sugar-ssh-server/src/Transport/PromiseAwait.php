<?php

declare(strict_types=1);

namespace SugarCraft\SshServer\Transport;

use React\EventLoop\Loop;
use React\Promise\PromiseInterface;
use SugarCraft\SshServer\Context;
use SugarCraft\SshServer\Lang;

/**
 * Shared synchronous-promise await helper.
 *
 * Drives a promise to synchronously settle using the event loop. The
 * ceiling is enforced twice over: react/promise-timer rejects the await,
 * and — since audit K10 — the spin this call starts is ENDED by that
 * rejection instead of being left to whatever else the loop happens to
 * have registered.
 *
 * Read the two laws below before using this from an embedding host.
 *
 * **Law 1: this call owns the loop while it runs.** It calls `run()`, and
 * it calls {@see \React\EventLoop\LoopInterface::stop()} as soon as the
 * awaited promise is decided (and at the ceiling, via a watchdog timer
 * cancelled before returning). Before K10 the spin ran until the loop was
 * *quiescent*, which is a different thing: a panel that keeps a listening
 * socket or a repeating timer registered never goes quiescent, so an await
 * that had already been answered — or already timed out — kept spinning.
 * Unrelated sessions' timers executed inside somebody's auth hop, and the
 * advertised 30-second ceiling bounded nothing.
 *
 * **Law 2: therefore never await from inside a running loop.** One owner
 * drives the loop per phase. A middleware that returns a promise is the
 * async path: {@see AsyncMiddleware::handleAsync()} hands it to the stack
 * untouched, so a host that runs its own loop keeps running it. Calling
 * `settle()` from a loop callback stops that host's loop the moment the
 * await lands. The synchronous `Middleware::handle()` contract used by
 * this library's own transports has no loop running underneath it, which
 * is why the stack walk can await here safely; the ceiling is pre-existing
 * and bounds an await of already-issued work, which the single-settle rule
 * deliberately keeps.
 *
 * One settle point: this is invoked from exactly one place — the
 * transport stack walk ({@see DispatchesMiddlewareStack}) — and now
 * consults the session Context cooperatively: a context that is
 * already done when the await is entered never gets to block the
 * loop, and its cancellation error is raised instead. Cancellation is
 * consulted at ENTRY only, deliberately: polling for it would mean
 * arming a repeating timer on a loop this class does not own.
 *
 * Mirrors charmbracelet/wish PromiseDispatch.awaitPromise.
 */
final class PromiseAwait
{
    /**
     * Slack added to the timeout for the watchdog that guarantees this
     * call's own spin ends even if neither the promise nor the timer ever
     * reports. The timer itself must fire first, or the rejection this
     * class raises would never be the promise's.
     */
    public const WATCHDOG_SLACK_SECONDS = 0.05;

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
     * @throws \RuntimeException if the timeout is reached (wraps the
     *                           react/promise-timer TimeoutException with
     *                           the i18n'd transport.async_timeout message,
     *                           keeping the timer error as the previous)
     */
    public static function settle(PromiseInterface $promise, float $timeout = 30.0, ?Context $ctx = null): void
    {
        // Cooperative cancellation: never enter the await for work
        // whose caller is already gone — abort with the context error
        // instead of blocking up to the timeout ceiling on it.
        $abort = $ctx?->done() === true ? $ctx->err() : null;
        if ($abort !== null) {
            throw $abort;
        }

        $loop = Loop::get();
        $ex = null;
        $done = false;

        // Attach callbacks to detect when the original promise settles. Each
        // of them ends the spin this class started (K10): the decision has
        // been made, and driving everybody else's registrations to
        // quiescence is not this call's business.
        $promise->then(
            function () use (&$done, $loop): void {
                $done = true;
                $loop->stop();
            },
            function (\Throwable $e) use (&$ex, &$done, $loop): void {
                $ex = $e;
                $done = true;
                $loop->stop();
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
        $timed = \React\Promise\Timer\timeout($promise, $timeout, $loop);

        // When the timeout fires (or the wrapped promise settles), catch it.
        // Only set $ex if not already set to preserve the original rejection.
        // Either way the ceiling has been reached, so end the spin (K10) —
        // that is what gives the documented ceiling its teeth.
        $timed->then(
            null,
            function (\Throwable $e) use (&$ex, &$done, $loop): void {
                if ($ex === null) {
                    $ex = $e;
                    $done = true;
                }
                $loop->stop();
            },
        );

        // Belt and braces: a promise that neither resolves nor reports must
        // not leave this call spinning past its own ceiling. The slack keeps
        // the timer above the breach it is meant to follow.
        $watchdog = $loop->addTimer(
            $timeout + self::WATCHDOG_SLACK_SECONDS,
            static function () use ($loop): void {
                $loop->stop();
            },
        );

        // Drive the loop until this await has its answer, then hand it back.
        try {
            $loop->run();
        } finally {
            // Never leave our own timer armed on a loop we do not own.
            $loop->cancelTimer($watchdog);
        }

        if ($ex !== null) {
            // Give the timer's breach a project-local, i18n'd message;
            // genuine promise rejections pass through untouched.
            if ($ex instanceof \React\Promise\Timer\TimeoutException) {
                throw new \RuntimeException(Lang::t('transport.async_timeout', ['timeout' => $timeout]), 0, $ex);
            }
            throw $ex;
        }

        if (!$done) {
            // The spin ended with no decision at all: the watchdog fired, or
            // somebody else that shares this loop stopped it. Returning
            // quietly would hand the caller an unsettled promise it believes
            // has settled, so the failure is raised rather than assumed away.
            throw new \RuntimeException(Lang::t('transport.async_timeout', ['timeout' => $timeout]));
        }
    }
}
