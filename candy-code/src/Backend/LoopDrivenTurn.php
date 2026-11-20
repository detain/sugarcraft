<?php

declare(strict_types=1);

namespace SugarCraft\Code\Backend;

use React\EventLoop\Loop;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;
use SugarCraft\Code\Agents\AgentWorkerPool;

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
 * A turn that never suspends runs to its return inside {@see \Fiber::start()}:
 * the loop is not run, and the result is what the turn returned, exactly as
 * before. Not asking is not the same as not suspending, though. A turn that
 * waits on a sub-agent suspends bare in {@see \SugarCraft\Code\Agents\AgentWorkerPool::idle()}
 * with no question pending, and this driver resumes it on the loop like any
 * other suspension.
 *
 * Only the turn's own fiber suspends. A suspension from a nested fiber (a
 * workflow's, say) is not this driver's, so such a caller keeps the blocking
 * wait and the nested fiber's own driver is never handed a promise it did not
 * ask for.
 */
final class LoopDrivenTurn
{
    /**
     * How long a bare suspension waits before the turn resumes: one loop turn
     * at the cadence the blocking `usleep()` it replaces used
     * ({@see \SugarCraft\Code\Support\ProcessReaper::POLL_INTERVAL_US}, 5 ms).
     *
     * WHY A TIMER AND NOT A RESUME AT ONCE. {@see \SugarCraft\Code\Agents\AgentWorkerPool::idle()}
     * suspends with no value and expects its driver to resume it later. Resuming
     * straight away spins the turn at full CPU and never returns control to the
     * loop, so the loop's own probes and its socket reads never run.
     */
    private const YIELD_SECONDS = 0.005;

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
        // Every pool the turn builds, not only one that asks, tears down
        // synchronously: this loop stops when the turn returns (see
        // AgentWorkerPool::enterTurnOwnedLoop()).
        AgentWorkerPool::enterTurnOwnedLoop();

        $finished = false;
        $failure = null;
        $advance = null;
        $wait = null;
        $advance = static function (mixed $value) use (&$advance, &$wait, $fiber, $loop, &$finished, &$failure): void {
            try {
                $suspended = $fiber->resume($value);
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
            $wait($suspended);
        };
        $wait = static function (mixed $suspended) use (&$advance, $loop, &$finished, &$failure): void {
            // A promise is the channel's wait for an answer: resume the turn
            // with it when it settles. Anything else is a bare yield, not an
            // answer, so the turn resumes on the loop's next timer, never in
            // the same call stack.
            if (!$suspended instanceof PromiseInterface) {
                $loop->addTimer(self::YIELD_SECONDS, static function () use (&$advance): void {
                    $advance(null);
                });

                return;
            }

            $suspended->then(
                static function (mixed $answer) use (&$advance): void {
                    $advance($answer);
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
            AgentWorkerPool::leaveTurnOwnedLoop();
            $channel->detachLoop();
        }
    }
}
