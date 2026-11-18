<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use function React\Promise\reject;
use function React\Promise\resolve;

/**
 * Timeout and deadline combinators: the caller-side bound ({@see withTimeout()})
 * and the work-side bound that cancels the operation ({@see withDeadline()}),
 * plus the shared rejection-message vocabulary both use.
 *
 * Split out of AsyncOps so the timer machinery lives beside its rejection
 * wording; AsyncOps keeps the public names as forwarding shims.
 */
final class Timeouts
{
    /**
     * Wrap a promise with a timeout. If the timeout fires before the
     * promise settles, the returned promise rejects with TimeoutException.
     * The inner promise is NOT cancelled and keeps running to completion.
     * The wait itself is abandonable: cancel() on the returned promise
     * releases the timer and rejects with OperationCancelledException —
     * abandon, not abort (stopping the work is withDeadline()'s contract).
     * When the work itself must stop as the wait ends — a hung I/O holding its
     * consumer's in-flight gate open forever — use {@see withDeadline()},
     * which cancels the inner operation on both expiry and abandonment.
     *
     * @param PromiseInterface $promise
     * @param float $seconds  Timeout in seconds (must be > 0 and finite)
     * @param LoopInterface|null $loop  Event loop; null (default) uses the shared Loop::get().
     * @param string|null $label  Operation name woven into the rejection
     *        message and carried on {@see TimeoutException::label()} — a TUI
     *        catching one TimeoutException among several wrapped calls needs
     *        the identity as data, not archaeology through the stack trace.
     * @return PromiseInterface
     *
     * @deprecated Passing the loop FIRST (loop, promise, seconds, ...) is
     *             deprecated since 1.x: such calls are transposed with an
     *             E_USER_DEPRECATED notice. Loop-first support is REMOVED-IN
     *             2.0.0 — call withTimeout($promise, $seconds, $loop).
     */
    public static function withTimeout(
        PromiseInterface|LoopInterface $promise,
        mixed $seconds = null,
        LoopInterface|float|int|string|null $loop = null,
        string|null $label = null,
    ): PromiseInterface {
        [$promise, $seconds, $loop, , $label] = Timeouts::normalizeWaitArgs(
            __FUNCTION__, $promise, $seconds, $loop, null, $label,
        );

        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Timeout seconds must be a positive finite number');
        }

        $loop ??= Loop::get();

        $settled = false;
        /** @var \React\EventLoop\TimerInterface|null */
        $timer = null;

        $deferred = new Deferred(static function () use (&$settled, $loop, &$timer, $label): never {
            $settled = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            throw new OperationCancelledException(Timeouts::cancellationMessage($label), label: $label);
        });

        // Arm the timeout BEFORE attaching the settle handlers. react/promise
        // v3 settles an already-settled inner synchronously inside then(), so
        // a handler attached first would see a not-yet-assigned timer, skip
        // the cancel, and leak the armed timer onto the (shared) loop for its
        // full duration. Armed first, the timer is always in hand when the
        // handler runs — pre-settled inners cancel it in the same synchronous
        // pass, and cancelTimer on an already-fired one-shot is a no-op.
        $timer = $loop->addTimer($seconds, function () use (&$settled, $deferred, $seconds, $label): void {
            if ($settled === true) {
                return; // wait already abandoned or settled — nothing to do
            }
            $settled = true;
            $deferred->reject(new TimeoutException(
                Timeouts::timeoutMessage($label, $seconds),
                seconds: $seconds,
                label: $label,
            ));
        });

        // Settle the outer promise when the inner settles.
        $promise->then(
            function ($value) use (&$settled, $deferred, $timer, $loop): void {
                if ($settled === true) {
                    return; // abandoned or timed out — late results are dropped
                }
                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->resolve($value);
            },
            function (\Throwable $reason) use (&$settled, $deferred, $timer, $loop): void {
                if ($settled === true) {
                    return;
                }
                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->reject($reason);
            },
        );

        return $deferred->promise();
    }

    /**
     * Wrap a promise with a deadline that CANCELS the inner promise when it
     * fires; {@see withTimeout()} by contrast lets the inner run on.
     *
     * WHY both exist: withTimeout answers "how long will the CALLER wait" and
     * stays out of the operation's way; withDeadline answers "how long may the
     * WORK take" — a hung I/O whose socket never errors (a silently dropped
     * route produces no socket error in react/mysql/PgAsync terms) would
     * otherwise keep a pending promise and its consumer's in-flight gate alive
     * forever. When the deadline fires, the inner is cancelled and the
     * optional $onTimeout hook gets one shot at the server-side abort the
     * driver's own cancellation cannot perform; a late driver result is
     * dropped, never resurrected.
     *
     * Bounded-I/O-ceiling semantics: a deadline is a ceiling computed on the
     * REMAINING budget — the caller decides how much of its allowance is left,
     * mirroring how retry() converts maxTotalSeconds into one absolute cutoff
     * — not a blanket per-request wall-clock killer applied blindly to every
     * hop.
     *
     * The timer is armed before the inner's settle handlers attach (the
     * withTimeout arming rule): react/promise v3 settles an already-settled
     * inner synchronously inside then(), so the handlers must find the timer
     * in hand to cancel it.
     *
     * Cancel() on the returned promise runs the full deadline teardown —
     * hook fired, inner cancelled, OperationCancelledException — the wait
     * ending early is still a wait that must not outlive its work.
     *
     * @param PromiseInterface $promise
     * @param float $seconds  Deadline in seconds (must be > 0 and finite)
     * @param LoopInterface|null $loop  Event loop; null (default) uses the shared Loop::get().
     * @param null|callable(): void $onTimeout  Abort hook, fired once when the
     *        deadline hits; errors are swallowed so a failed kill cannot mask
     *        the timeout rejection.
     * @param string|null $label  Operation name for the rejection message and
     *        {@see TimeoutException::label()}; see withTimeout().
     * @return PromiseInterface
     *
     * @deprecated Passing the loop FIRST (loop, promise, seconds, ...) is
     *             deprecated since 1.x: such calls are transposed with an
     *             E_USER_DEPRECATED notice. Loop-first support is REMOVED-IN
     *             2.0.0 — call withDeadline($promise, $seconds, $loop).
     */
    public static function withDeadline(
        PromiseInterface|LoopInterface $promise,
        mixed $seconds = null,
        LoopInterface|float|int|string|null $loop = null,
        ?callable $onTimeout = null,
        ?string $label = null,
    ): PromiseInterface {
        [$promise, $seconds, $loop, $onTimeout, $label] = Timeouts::normalizeWaitArgs(
            __FUNCTION__, $promise, $seconds, $loop, $onTimeout, $label,
        );

        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Deadline seconds must be a positive finite number');
        }

        $loop ??= Loop::get();

        $settled = false;
        /** @var \React\EventLoop\TimerInterface|null */
        $timer = null;

        $deferred = new Deferred(function () use (&$settled, $loop, &$timer, $promise, $onTimeout, $label): never {
            $settled = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
            }
            if ($onTimeout !== null) {
                try {
                    $onTimeout();
                } catch (\Throwable) {
                    // Same invariant as the deadline path: a failed kill is not the
                    // caller's truth.
                }
            }
            try {
                $promise->cancel();
            } catch (\Throwable) {
                // The abandonment rejection below stands regardless.
            }
            throw new OperationCancelledException(Timeouts::cancellationMessage($label), label: $label);
        });

        $timer = $loop->addTimer($seconds, function () use (&$settled, $deferred, $promise, $seconds, $onTimeout, $label): void {
            if ($settled === true) {
                return;
            }
            $settled = true;

            if ($onTimeout !== null) {
                try {
                    $onTimeout();
                } catch (\Throwable) {
                    // The timeout rejection below is the caller-facing truth;
                    // a failed abort hook must not replace it.
                }
            }

            $deferred->reject(new TimeoutException(
                Timeouts::deadlineMessage($label, $seconds),
                seconds: $seconds,
                label: $label,
            ));

            // Cancel AFTER settling the caller-facing promise: the caller's
            // truth is the timeout, and a driver whose cancel() re-settles the
            // inner must not reach them through the guards below. A driver
            // whose cancel() THROWS must likewise not escape this callback —
            // it would poison the loop tick for every other watcher.
            try {
                $promise->cancel();
            } catch (\Throwable) {
            }
        });

        $promise->then(
            function ($value) use (&$settled, $deferred, $timer, $loop): void {
                if ($settled === true) {
                    return; // deadline already won — late results are dropped
                }
                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->resolve($value);
            },
            function (\Throwable $reason) use (&$settled, $deferred, $timer, $loop): void {
                if ($settled === true) {
                    return;
                }
                $settled = true;
                $loop->cancelTimer($timer);
                $deferred->reject($reason);
            },
        );

        return $deferred->promise();
    }

    /**
     * Accept the lawful (promise, seconds, ..., loop) order and the deprecated
     * loop-first positional order, and hand back the lawful one.
     *
     * @param string $method  Caller name, for messages.
     * @param PromiseInterface|LoopInterface $promise
     * @param mixed $seconds
     * @param mixed $loop
     * @param mixed $onTimeout
     * @param mixed $label
     * @return array{0: PromiseInterface, 1: float, 2: LoopInterface|null, 3: mixed, 4: string|null}
     */
    public static function normalizeWaitArgs(
        string $method,
        PromiseInterface|LoopInterface $promise,
        mixed $seconds,
        mixed $loop,
        mixed $onTimeout,
        mixed $label,
    ): array {
        if ($promise instanceof LoopInterface) {
            // Deprecated positional transpose: (loop, promise, seconds, [hook], [label]).
            trigger_error(
                $method . '(): passing the event loop first is deprecated — the loop is a trailing'
                    . ' optional parameter; call ' . $method . '($promise, $seconds, $loop) instead'
                    . ' (loop-first support ends in 2.0.0).',
                E_USER_DEPRECATED,
            );
            if (!$seconds instanceof PromiseInterface) {
                throw new \InvalidArgumentException(
                    $method . '(): a loop-first call must pass the promise as its second argument.',
                );
            }
            if (!is_numeric($loop)) {
                throw new \InvalidArgumentException(
                    $method . '(): a loop-first call must pass the seconds as its third argument.',
                );
            }
            [$legacyLoop, $realPromise, $realSeconds] = [$promise, $seconds, $loop];
            $promise = $realPromise;
            $seconds = (float) $realSeconds;
            $loop = $legacyLoop;
        }

        if (!$promise instanceof PromiseInterface) {
            throw new \InvalidArgumentException($method . '(): the first argument must be the promise to wrap.');
        }
        if (!is_numeric($seconds)) {
            throw new \InvalidArgumentException($method . '(): the second argument must be the timeout in seconds.');
        }
        if ($loop !== null && !$loop instanceof LoopInterface) {
            throw new \InvalidArgumentException($method . '(): the loop must be an event loop or null.');
        }
        if ($label !== null && !is_string($label)) {
            throw new \InvalidArgumentException($method . '(): the label must be a string or null.');
        }

        return [$promise, (float) $seconds, $loop, $onTimeout, $label];
    }

    public static function subject(?string $label): string
    {
        return $label === null ? 'Operation' : 'Operation "' . $label . '"';
    }

    public static function cancellationMessage(?string $label): string
    {
        return $label === null
            ? 'Async operation cancelled'
            : Timeouts::subject($label) . ' cancelled';
    }

    public static function timeoutMessage(?string $label, float $seconds): string
    {
        return Timeouts::subject($label) . ' timed out after ' . MessageFormat::seconds($seconds) . ' ' . MessageFormat::unit($seconds);
    }

    public static function deadlineMessage(?string $label, float $seconds): string
    {
        // "N-second deadline": the bound is a point in time the wait gets
        // (a duration elapses; a deadline is exceeded).
        return Timeouts::subject($label) . ' exceeded its ' . MessageFormat::seconds($seconds) . '-second deadline';
    }
}
