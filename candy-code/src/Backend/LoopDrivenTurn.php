<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

/**
 * Runs a forked turn child's work on the event loop (P4-12 CC-1 step e0).
 *
 * The turn is a synchronous generator and its tool batches call straight into
 * the approver and the client caller. An approver or client answer that has
 * not arrived yet therefore has to suspend the call stack, not return. The
 * turn runs inside a {@see \Fiber}: {@see ChildChannel::ask()} and
 * {@see ChildChannel::callClient()} suspend it on a pending promise, the loop
 * watches the turn socket, and the `ask_reply` or `client_result` frame
 * resumes it. Nothing blocks on the socket while the user decides.
 *
 * A turn that never asks never suspends: the fiber runs to its return inside
 * {@see \Fiber::start()}, the loop is not run, and the result is what the turn
 * returned, exactly as before.
 *
 * Only the turn's own fiber suspends. A suspension from a nested fiber (a
 * workflow's, say) is not this driver's, so such a caller keeps the blocking
 * wait and the nested fiber's own driver is never handed a promise it did not
 * ask for.
 */
final class LoopDrivenTurn
{
    /**
     * @template T
     *
     * @param \Closure(): T $turn
     *
     * @return T whatever the turn returned
     */
    public static function run(\Closure $turn, ChildChannel $channel, ?LoopInterface $loop = null): mixed
    {
        $loop ??= Loop::get();
        $fiber = new \Fiber($turn);
        $channel->attachLoop($loop, $fiber);

        $finished = false;
        $failure = null;
        $wait = null;
        $wait = static function (mixed $suspended) use (&$wait, $fiber, $loop, &$finished, &$failure): void {
            // Only the channel suspends this fiber, always on a promise. Anything
            // else is not ours to wait for, so it is resumed at once with nothing.
            while (!$suspended instanceof PromiseInterface) {
                try {
                    $suspended = $fiber->resume(null);
                } catch (\Throwable $e) {
                    $failure = $e;
                    $finished = true;
                    $loop->stop();

                    return;
                }
                if ($fiber->isTerminated()) {
                    $finished = true;
                    $loop->stop();

                    return;
                }
            }

            $suspended->then(
                static function (mixed $answer) use (&$wait, $fiber, $loop, &$finished, &$failure): void {
                    try {
                        $next = $fiber->resume($answer);
                    } catch (\Throwable $e) {
                        $failure = $e;
                        $finished = true;
                        $loop->stop();

                        return;
                    }
                    if ($fiber->isTerminated()) {
                        $finished = true;
                        $loop->stop();

                        return;
                    }
                    $wait($next);
                },
                static function (\Throwable $e) use (&$failure, &$finished, $loop): void {
                    $failure = $e;
                    $finished = true;
                    $loop->stop();
                },
            );
        };

        try {
            $suspended = $fiber->start();
            if ($fiber->isTerminated()) {
                return $fiber->getReturn();
            }

            $wait($suspended);
            if (!$finished) {
                $loop->run();
            }
            if (!$finished) {
                throw new \RuntimeException('the turn loop stopped before the turn finished');
            }
            if ($failure !== null) {
                throw $failure;
            }

            return $fiber->getReturn();
        } finally {
            $channel->detachLoop();
        }
    }
}
