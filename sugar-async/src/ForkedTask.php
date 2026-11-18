<?php

declare(strict_types=1);

namespace SugarCraft\Async;

use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

/**
 * Fork a child process, stream its frames back to the parent's loop, and
 * settle with its exit status.
 *
 * The child runs $child with a {@see ForkChannel} to the parent; each frame it
 * sends reaches $collect on the loop. The promise resolves with the child's
 * exit status once its channel closes and it has been reaped: 0 when $child
 * returned, 1 when it threw. Three things end the task early, each killing the
 * child with SIGKILL and reaping it before the promise settles: $token fires
 * (rejects with OperationCancelledException), the optional $deadline elapses
 * (rejects with TimeoutException), or $collect throws (that exception rejects).
 *
 * Requires ext-pcntl and ext-posix; without them it refuses up front rather
 * than returning a promise that can never settle. The child exits through
 * PHP's normal exit path, so every object the fork inherited runs its
 * __destruct() there, including objects the parent holds. A destructor with
 * external effects is therefore unsafe to hold across the fork unless it
 * checks the process that minted it: {@see \SugarCraft\Core\Util\LockGuard}
 * does, so a held file lock survives the child's exit.
 */
final class ForkedTask
{
    /** Seconds between WNOHANG polls while a settled task waits on its child. */
    private const REAP_POLL_SECONDS = 0.01;

    /**
     * @param callable(ForkChannel): void $child  Runs in the forked child
     * @param callable(string): void $collect  Parent-side handler for each child frame
     * @param LoopInterface $loop  Loop that delivers frames and arms the deadline
     * @param CancellationToken|null $token  Kills the child and rejects when it fires
     * @param float|null $deadline  Parent-side budget in seconds (positive and finite); null for none
     * @return PromiseInterface<int>  The child's exit status
     */
    public static function run(
        callable $child,
        callable $collect,
        LoopInterface $loop,
        ?CancellationToken $token = null,
        ?float $deadline = null,
    ): PromiseInterface {
        if (!\function_exists('pcntl_fork') || !\function_exists('posix_kill')) {
            throw new \RuntimeException('ForkedTask needs ext-pcntl and ext-posix');
        }
        if ($deadline !== null && ($deadline <= 0 || !is_finite($deadline))) {
            throw new \InvalidArgumentException('ForkedTask deadline must be a positive finite number of seconds');
        }

        // The settle closure exists only after the fork; a cancel before that
        // point has no child to stop, so the reference is still null then.
        $settle = null;
        $deferred = new Deferred(static function () use (&$settle): never {
            if ($settle !== null) {
                $settle(new OperationCancelledException('Forked task cancelled'));
            }
            throw new OperationCancelledException('Forked task cancelled');
        });
        if ($token !== null && $token->isCancelled()) {
            $deferred->reject(new OperationCancelledException('Forked task cancelled'));
            return $deferred->promise();
        }

        [$parent, $childEnd] = ForkChannel::pair();
        $pid = \pcntl_fork();
        if ($pid === -1) {
            $parent->close();
            $childEnd->close();
            throw new \RuntimeException('ForkedTask: pcntl_fork() failed');
        }
        if ($pid === 0) {
            self::runChild($child, $childEnd, $parent);
        }
        $childEnd->close();

        $settled = false;
        $deadlineHandle = null;
        $registration = null;

        // The reap is polled on the loop, never blocking: a child that closed its
        // channel early may keep running for a long time, and a child that
        // survives SIGKILL may sit in an uninterruptible wait. Either would park
        // every other connection the loop serves behind a blocking waitpid().
        $settle = static function (?\Throwable $error) use (&$settled, &$deadlineHandle, &$registration, $deferred, $parent, $pid, $loop): void {
            if ($settled) {
                return;
            }
            $settled = true;
            $deadlineHandle?->disarm();
            $registration?->unsubscribe();
            $parent->close();
            if ($error !== null) {
                \posix_kill($pid, \defined('SIGKILL') ? \SIGKILL : 9);
            }
            self::reapWhenExited($loop, $pid, static function (int $status) use ($deferred, $error): void {
                if ($error !== null) {
                    $deferred->reject($error);
                    return;
                }
                $deferred->resolve($status);
            });
        };

        $parent->onFrame(static function (string $frame) use (&$settled, $settle, $collect): void {
            if ($settled) {
                return;
            }
            try {
                $collect($frame);
            } catch (\Throwable $error) {
                $settle($error);
            }
        }, $loop);
        $parent->onClose(static function () use (&$settled, $settle): void {
            if (!$settled) {
                $settle(null);
            }
        });

        if ($deadline !== null) {
            $deadlineHandle = LoopDeadline::arm($loop, $deadline, static function () use ($settle, $deadline): void {
                $settle(new TimeoutException('Forked task exceeded its ' . MessageFormat::seconds($deadline) . ' deadline', seconds: $deadline));
            });
        }
        if ($token !== null) {
            $registration = $token->onCancel(static function () use ($settle): void {
                $settle(new OperationCancelledException('Forked task cancelled'));
            });
        }

        return $deferred->promise();
    }

    /** Child side: run the work, close the channel, and exit without returning. */
    private static function runChild(callable $child, ForkChannel $channel, ForkChannel $parentEnd): never
    {
        $parentEnd->close();
        $code = 0;
        try {
            $child($channel);
        } catch (\Throwable) {
            $code = 1;
        }
        $channel->close();
        // The child inherits the parent's output-buffer stack. A normal exit()
        // flushes it, and the child shares the parent's stdout, so the parent's
        // un-emitted bytes would be written a second time from here. Drain the
        // levels without flushing before exiting.
        while (\ob_get_level() > 0) {
            \ob_end_clean();
        }
        exit($code);
    }

    /**
     * Reap the child on loop timers: answer at once when it has already exited,
     * otherwise poll WNOHANG each tick and answer the tick it is seen gone.
     * `$done` gets the exit status, or -1 when the child did not exit normally
     * (or could not be waited for at all).
     *
     * @param callable(int):void $done
     */
    private static function reapWhenExited(LoopInterface $loop, int $pid, callable $done): void
    {
        $status = 0;
        $poll = static function () use ($pid, &$status): ?int {
            $waited = \pcntl_waitpid($pid, $status, \WNOHANG);
            if ($waited === 0) {
                return null;
            }

            return $waited === $pid && \pcntl_wifexited($status) ? \pcntl_wexitstatus($status) : -1;
        };

        $code = $poll();
        if ($code !== null) {
            $done($code);

            return;
        }

        $timer = null;
        $timer = $loop->addPeriodicTimer(self::REAP_POLL_SECONDS, static function () use (&$timer, $loop, $poll, $done): void {
            $code = $poll();
            if ($code === null) {
                return;
            }
            $loop->cancelTimer($timer);
            $done($code);
        });
    }
}
