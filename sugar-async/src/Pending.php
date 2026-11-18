<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Pending-wait helpers: the advisory notice ({@see pendingNotice()}) and the
 * bounded rejection ({@see boundWait()}) for user-paced operations.
 *
 * Split out of AsyncOps; AsyncOps keeps the public names as forwarding shims.
 */
final class Pending
{
    /**
     * Announce slowness without interrupting the wait. If $promise is still
     * pending after $seconds, $onPending runs ONCE (a "taking longer than
     * expected" spinner hint). The returned promise tracks the inner one —
     * the notice neither cancels nor abandons the work, and the E646 rule
     * holds: this is an advisory tick, never a wall-clock killer of
     * user-paced operations. A hook that throws is logged, never propagated.
     *
     * @param callable(): void $onPending  Required advisory notice
     * @return PromiseInterface<mixed>
     */
    public static function pendingNotice(
        PromiseInterface $promise,
        float $seconds,
        callable $onPending,
        ?LoopInterface $loop = null,
    ): PromiseInterface {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Pending notice seconds must be a positive finite number');
        }

        return self::watchPending($promise, $seconds, $onPending, null, $loop);
    }

    /**
     * Bound the wait without stopping the work. If $promise is still pending
     * after $seconds, the returned promise rejects with a TimeoutException
     * whose message names (still pending) — the inner operation keeps
     * running, like under withTimeout(). These are the bounded-wait
     * semantics for user-paced operations: this library puts no blanket
     * wall-clock timeout on operations whose pace belongs to the user. To
     * announce a slow wait instead of ending it, use {@see pendingNotice()}.
     *
     * @return PromiseInterface<mixed>
     */
    public static function boundWait(
        PromiseInterface $promise,
        float $seconds,
        ?LoopInterface $loop = null,
        ?string $label = null,
    ): PromiseInterface {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Bounded wait seconds must be a positive finite number');
        }

        return self::watchPending($promise, $seconds, null, $label, $loop);
    }

    /**
     * @deprecated REMOVED-IN: 2.0.0 — the dual contract is split into
     *             {@see pendingNotice()} (advisory hook) and
     *             {@see boundWait()} (bounded rejection) so the mode is
     *             visible at the call site. This shim delegates: a hook
     *             goes to pendingNotice(), a null hook to boundWait().
     */
    public static function onPending(
        PromiseInterface $promise,
        float $seconds,
        ?callable $onPending = null,
        ?LoopInterface $loop = null,
        ?string $label = null,
    ): PromiseInterface {
        \trigger_error(
            'AsyncOps::onPending(): the dual-contract helper is deprecated — call pendingNotice() with a hook or boundWait() without one (support ends in 2.0.0).',
            \E_USER_DEPRECATED,
        );

        return $onPending !== null
            ? self::pendingNotice($promise, $seconds, $onPending, $loop)
            : self::boundWait($promise, $seconds, $loop, $label);
    }

    /**
     * Shared timer machinery for pendingNotice() and boundWait(): the hook
     * arm announces and keeps waiting, the no-hook arm rejects with a
     * TimeoutException at expiry while the inner runs on.
     *
     * @param null|callable(): void $onPending
     * @return PromiseInterface<mixed>
     */
    private static function watchPending(
        PromiseInterface $promise,
        float $seconds,
        ?callable $onPending,
        ?string $label,
        ?LoopInterface $loop,
    ): PromiseInterface {
        $loop ??= Loop::get();
        $settled = false;
        $timer = null;

        $deferred = new Deferred(static function () use (&$settled, &$timer, $loop, $label): never {
            if ($settled !== true) {
                $settled = true;
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
            }
            throw new OperationCancelledException(Timeouts::cancellationMessage($label), label: $label);
        });

        $timer = $loop->addTimer($seconds, static function () use (&$settled, &$timer, $loop, $onPending, $deferred, $seconds, $label): void {
            $timer = null;
            if ($settled === true) {
                return;
            }
            if ($onPending !== null) {
                try {
                    $onPending();
                } catch (\Throwable $noticeError) {
                    AsyncOps::logFailure('sugar-async: pending notice failed: ' . $noticeError->getMessage());
                }
                return; // advisory: the wait continues
            }
            $settled = true;
            $deferred->reject(new TimeoutException(
                Timeouts::timeoutMessage($label, $seconds) . ' (still pending)',
                seconds: $seconds,
                label: $label,
            ));
        });

        $promise->then(
            static function (mixed $value) use (&$settled, &$timer, $loop, $deferred): void {
                if ($settled === true) {
                    return;
                }
                $settled = true;
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
                $deferred->resolve($value);
            },
            static function (\Throwable $error) use (&$settled, &$timer, $loop, $deferred): void {
                if ($settled === true) {
                    return;
                }
                $settled = true;
                if ($timer !== null) {
                    $loop->cancelTimer($timer);
                    $timer = null;
                }
                $deferred->reject($error);
            },
        );

        return $deferred->promise();
    }
}
