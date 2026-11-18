<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Poll-until on the loop: call $fn, and while it answers null wait out the
 * backoff schedule and call it again.
 *
 * The first attempt runs immediately. After attempt $n answers null, the next
 * attempt waits {@see Backoff::secondsForAttempt()} for $n, so the attempt cap
 * and the delay ladder are the same {@see Backoff} the retry and reconnect
 * paths use. The promise resolves with the first non-null answer; it rejects
 * with a TimeoutException once the backoff allows no further attempt, with the
 * callable's own exception if it throws, and with an OperationCancelledException
 * when $token fires or the returned promise is cancelled. Mirrors the
 * progress-and-job loops candy-code's media pollers hand-rolled.
 */
final class Poll
{
    /**
     * @template T
     * @param callable(): ?T $fn  One attempt; null means "not yet, poll again"
     * @param Backoff $backoff  Delay ladder and attempt cap (no cap when unset)
     * @param CancellationToken|null $token  Ends the poll when it fires
     * @param LoopInterface|null $loop  Loop to schedule on; null uses the shared Loop::get()
     * @return PromiseInterface<T>
     */
    public static function until(
        callable $fn,
        Backoff $backoff,
        ?CancellationToken $token = null,
        ?LoopInterface $loop = null,
    ): PromiseInterface {
        $loop ??= Loop::get();
        $timer = null;
        $done = false;
        $registration = null;

        $finish = static function () use (&$done, &$timer, &$registration, $loop): void {
            $done = true;
            if ($timer !== null) {
                $loop->cancelTimer($timer);
                $timer = null;
            }
            $registration?->unsubscribe();
            $registration = null;
        };

        $deferred = new Deferred(static function () use ($finish): never {
            $finish();
            throw new OperationCancelledException('Poll cancelled');
        });

        $attempt = null;
        $attempt = static function (int $n) use (&$attempt, &$done, &$timer, $fn, $backoff, $deferred, $loop, $finish): void {
            $timer = null;
            if ($done) {
                return;
            }
            try {
                $value = $fn();
            } catch (\Throwable $error) {
                $finish();
                $deferred->reject($error);
                return;
            }
            if ($value !== null) {
                $finish();
                $deferred->resolve($value);
                return;
            }
            if (!$backoff->allows($n)) {
                $finish();
                $deferred->reject(new TimeoutException('Poll gave up after ' . $n . ' attempt' . ($n === 1 ? '' : 's') . ' without a result'));
                return;
            }
            $timer = $loop->addTimer($backoff->secondsForAttempt($n), static function () use (&$attempt, $n): void {
                $attempt($n + 1);
            });
        };

        if ($token !== null) {
            if ($token->isCancelled()) {
                $done = true;
                $deferred->reject(new OperationCancelledException('Poll cancelled'));
                return $deferred->promise();
            }
            $registration = $token->onCancel(static function () use ($finish, $deferred): void {
                $finish();
                $deferred->reject(new OperationCancelledException('Poll cancelled'));
            });
        }

        $attempt(1);

        return $deferred->promise();
    }
}
