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
 * ceiling is enforced twice over: a ceiling timer armed by this class
 * rejects the await with react/promise-timer's TimeoutException, and —
 * since the 2026-11 audit — the spin this call starts is ENDED by that
 * rejection instead of being left to whatever else the loop happens to
 * have registered. The timer is armed, cancelled, and owned here (not
 * inside a third-party wrapper promise) because audit 015 proved a
 * ceiling you cannot cancel is a ceiling that outlives its await: an
 * abandoned spin used to strand its timer on the shared loop, where it
 * could fire during somebody else's spin and stop it mid-flight.
 *
 * Read the two laws below before using this from an embedding host.
 *
 * **Law 1: this call owns the loop while it runs.** It calls `run()`, and
 * it calls {@see \React\EventLoop\LoopInterface::stop()} as soon as the
 * awaited promise is decided (and at the ceiling, via a watchdog timer
 * cancelled before returning). Before the fix the spin ran until the loop was
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
 * await lands. This library's own transports use the synchronous
 * `Middleware::handle()` contract, which has no loop running underneath
 * it, which is why the stack walk can await there — and a chain that
 * breaks that assumption (an async hop stacked under another async
 * hop) is now caught mechanically: a process-wide spin counter makes a
 * nested `settle()` throw a `LogicException` at entry, before it can
 * hijack the outer spin, rather than surfacing later as a spurious
 * `transport.async_timeout` over live work. The ceiling is pre-existing
 * and bounds an await of already-issued work, which the single-settle
 * rule deliberately keeps.
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

    /**
     * Law-2 enforcement: how many `settle()` spins this process is
     * currently driving. A second entry while one is running is the
     * stacked-async-hop shape that corrupts the outer spin, so it is
     * refused at the door instead of discovered at the ceiling.
     */
    private static int $spinDepth = 0;

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

        // Law 2, mechanically: the loop has one owner per spin. An await
        // entered from inside another await's spin (stacked async hops)
        // would end the OUTER spin at the INNER promise's decision and
        // strand the outer ceiling — refuse it by name instead. The
        // message is a developer-facing contract string, outside the
        // peer-answer catalogue (PromiseDispatchTest's TypeError arm
        // and the Auth doors' LogicException set this precedent).
        if (self::$spinDepth > 0) {
            throw new \LogicException(
                'PromiseAwait::settle() was entered while another settle spin is already driving the loop.'
                . ' A middleware that needs to await inside an async chain must return its promise instead'
                . ' (AsyncMiddleware::handleAsync), so the stack walk awaits it once, at the top.'
            );
        }

        $loop = Loop::get();
        $ex = null;
        $done = false;

        // The ceiling timer is armed here and cancelled here — never by
        // a wrapper this class cannot reach (audit 015: react/promise-timer's
        // timeout() kept its timer private, so an await abandoned by a
        // foreign stop() left a live ceiling on the shared loop). The
        // rejection still carries react/promise-timer's TimeoutException
        // class so callers' existing mapping is untouched. $decision is
        // the promise the single set of decision handlers attaches to;
        // its timer is cancelled by this frame — on the synchronous
        // decision path before any spin, and in the finally after the
        // run — so neither a settled nor an abandoned await can strand
        // a live ceiling on a loop this class does not own.
        $ceiling = null;
        $decision = new \React\Promise\Promise(
            /** @param callable(mixed): void $resolve @param callable(\Throwable): void $reject */
            function (callable $resolve, callable $reject) use ($promise, $loop, $timeout, &$ceiling): void {
                $ceiling = $loop->addTimer($timeout, static function () use ($reject, $timeout): void {
                    // Same constructor shape react/promise-timer's timeout() uses: the
                    // breach carries the number of seconds it waited for.
                    $reject(new \React\Promise\Timer\TimeoutException($timeout));
                });
                $promise->then(
                    static function (mixed $value) use ($resolve): void {
                        $resolve($value);
                    },
                    /** @param \Throwable $e */
                    static function (\Throwable $e) use ($reject): void {
                        $reject($e);
                    },
                );
            }
        );

        // The decision handlers see the wrapper, not the raw promise:
        // exactly one settlement path — the awaited promise's verdict or
        // the ceiling breach — can decide this await, and it decides
        // through one pair of handlers (audit 015; the old code attached
        // to the raw promise as well, where an inner stop() could strand
        // the wrapper undecided). Either way the await has its answer, so
        // end the spin this class started (the 2026-11 fix) — driving everybody
        // else's registrations to quiescence is not this call's business.
        $decision->then(
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
            $loop->cancelTimer($ceiling);
            if ($ex !== null) {
                throw $ex;
            }
            return;
        }

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
        self::$spinDepth++;
        try {
            $loop->run();
        } finally {
            self::$spinDepth--;
            // Never leave our own timers armed on a loop we do not own:
            // the watchdog belt, and — the audit-015 abandon cure — the
            // ceiling of a spin ended by another owner before this await
            // had a decision. cancelTimer is idempotent, so cancelling a
            // ceiling the decision path already released is inert.
            $loop->cancelTimer($watchdog);
            $loop->cancelTimer($ceiling);
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
