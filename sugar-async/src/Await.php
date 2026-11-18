<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Synchronous wait on a promise: drive the loop until the promise settles or
 * a budget elapses, then return its value or rethrow its rejection.
 *
 * This is the one place a CLI entry point may block on the loop. Mirrors the
 * private `await()` helper candy-code's `Cli/Attach.php` carried, but makes
 * the timeout a typed {@see TimeoutException} instead of an in-band null, so a
 * timed-out wait cannot be mistaken for a resolved null.
 *
 * The inner promise is abandoned on timeout, not cancelled: the work keeps
 * running, as it does under {@see Timeouts::withTimeout()}. Pass a promise
 * that already carries its own deadline when the work must stop.
 *
 * Not re-entrant: calling this from inside a running loop callback would nest
 * `run()`, so it throws a LogicException instead.
 */
final class Await
{
    private static bool $driving = false;

    /**
     * @param PromiseInterface $promise  Settles with the value to return
     * @param float $seconds             Budget (positive and finite)
     * @param LoopInterface|null $loop   Loop to drive; null uses the shared Loop::get()
     * @return mixed  The resolved value
     * @throws \Throwable               The promise's rejection reason, as-is
     * @throws TimeoutException         When the promise is still pending after $seconds
     */
    public static function until(PromiseInterface $promise, float $seconds, ?LoopInterface $loop = null): mixed
    {
        if ($seconds <= 0 || !is_finite($seconds)) {
            throw new \InvalidArgumentException('Await seconds must be a positive finite number');
        }
        if (self::$driving) {
            throw new \LogicException('Await::until() cannot run inside a loop callback that is already driving the loop');
        }
        $loop ??= Loop::get();

        $settled = false;
        $ok = false;
        $value = null;
        // Only the active wait may stop the loop: a promise abandoned by a
        // timeout can still settle later, during someone else's run().
        $waiting = false;
        $promise->then(
            static function (mixed $v) use (&$settled, &$ok, &$value, &$waiting, $loop): void {
                $settled = true;
                $ok = true;
                $value = $v;
                if ($waiting) {
                    $loop->stop();
                }
            },
            static function (\Throwable $e) use (&$settled, &$value, &$waiting, $loop): void {
                $settled = true;
                $value = $e;
                if ($waiting) {
                    $loop->stop();
                }
            },
        );

        if ($settled === false) {
            $timer = $loop->addTimer($seconds, static function () use ($loop, &$waiting): void {
                if ($waiting) {
                    $loop->stop();
                }
            });
            $waiting = true;
            self::$driving = true;
            try {
                $loop->run();
            } finally {
                self::$driving = false;
                $waiting = false;
                $loop->cancelTimer($timer);
            }
        }

        if ($settled === false) {
            throw new TimeoutException(
                'Await timed out after ' . MessageFormat::seconds($seconds) . ' (still pending)',
                seconds: $seconds,
            );
        }
        if ($ok === false) {
            throw $value;
        }

        return $value;
    }
}
