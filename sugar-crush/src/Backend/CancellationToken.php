<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Backend;

/**
 * A shared, mutable cancel flag threaded from {@see \SugarCraft\Crush\Chat}
 * into a {@see \SugarCraft\Crush\Backend}'s {@see Backend::completeAsync()}
 * call for one turn. Deliberately NOT one of Chat's immutable `with*()`
 * value-object fields — the whole point is a single shared instance both
 * sides can see mutate, so Chat's later double-Escape handler can flip it
 * after the async Cmd closure has already captured it.
 *
 * A POLLED flag for a turn, and a PUSHED one for a workflow run. A backend's
 * forked turn checks {@see isCancelled()} on its own schedule; a workflow run
 * cannot, because while Esc Esc is being handled its fiber is suspended inside
 * a worker pool's idle poll and nothing on its stack is running. So a holder
 * that has to ACT the moment the flag flips — `WorkflowEngine` killing the
 * stage's forked agents through `AgentWorkerPool::cancelAll()` — registers
 * with {@see onCancel()}, and {@see cancel()} calls it there and then.
 */
final class CancellationToken
{
    private bool $cancelled = false;

    /** @var array<int, \Closure(): void> */
    private array $listeners = [];

    private int $nextListener = 0;

    public function cancel(): void
    {
        if ($this->cancelled) {
            return;
        }

        $this->cancelled = true;

        // Taken off the token before they run, so each fires exactly once and
        // a listener that detaches itself (or registers another) cannot
        // disturb the walk.
        $listeners = $this->listeners;
        $this->listeners = [];
        foreach ($listeners as $listener) {
            $listener();
        }
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * Run $listener when this token is cancelled — at once, when it already
     * is. Returns the detach: call it when the work $listener would stop is
     * over, so a token that outlives that work (Chat keeps a turn's token
     * until the turn settles) does not keep its objects alive or act on them.
     *
     * @param \Closure(): void $listener
     * @return \Closure(): void
     */
    public function onCancel(\Closure $listener): \Closure
    {
        if ($this->cancelled) {
            $listener();

            return static function (): void {
            };
        }

        $id = $this->nextListener++;
        $this->listeners[$id] = $listener;

        return function () use ($id): void {
            unset($this->listeners[$id]);
        };
    }
}
