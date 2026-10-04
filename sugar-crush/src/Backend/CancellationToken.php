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
 *
 * TWO STRENGTHS (roadmap 1.C-4). {@see cancel()} is the hard stop: kill the
 * work now. {@see cancelSoft()} asks only that the work stop at its next
 * natural boundary — a forked engine turn finishes the step's tools and makes
 * no further provider call. A holder that has no boundary to stop at simply
 * ignores the soft flag; a later hard cancel always wins. {@see cancelTool()}
 * (roadmap 1.C-4b) is narrower still: stop one running call by its id.
 *
 * STEERING (roadmap 1.C-3) rides the same per-turn handle: {@see steer()}
 * queues a message the user typed while the turn runs, the forked turn's
 * parent takes it with {@see takeSteers()} on the tick it already polls this
 * token on and writes it down as a `steer` frame, and the child's
 * `steer_ack` comes back through {@see acknowledgeSteer()}. A holder that
 * cannot deliver mid-turn never takes them; the steer is then sent as the
 * next prompt by Chat's queue release, so it is never lost either way.
 */
final class CancellationToken
{
    private bool $cancelled = false;

    private bool $softCancelled = false;

    /** @var array<int, \Closure(): void> */
    private array $listeners = [];

    private int $nextListener = 0;

    /** @var list<array{steerId: string, text: string}> queued, not yet taken by the turn's parent */
    private array $steers = [];

    /** @var list<string> call ids asked to stop, not yet taken by the turn's parent */
    private array $toolCancels = [];

    /** @var array<string, true> every call id ever asked to stop, so each goes down once */
    private array $toolCancelled = [];

    /** @var array<string, int> steerId => the step it was delivered at */
    private array $acknowledged = [];

    private int $nextSteer = 0;

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
     * Ask the work to stop at its next boundary rather than at once. Polled,
     * like {@see isCancelled()}; it fires no listener, because those are the
     * hard stop's.
     */
    public function cancelSoft(): void
    {
        $this->softCancelled = true;
    }

    /** Whether {@see cancelSoft()} (or the stronger {@see cancel()}) was called. */
    public function isSoftCancelled(): bool
    {
        return $this->softCancelled || $this->cancelled;
    }

    /**
     * Ask the work to stop ONE running tool call now (roadmap 1.C-4b,
     * `cancel_tool{callId}`): a forked engine turn's parent takes it with
     * {@see takeToolCancels()} on the tick it polls this token on and sends
     * it down; the call settles as cancelled and the rest of the turn goes
     * on. Each id is queued once, however often it is asked. A holder that
     * cannot stop a single call never takes them.
     */
    public function cancelTool(string $callId): void
    {
        if ($callId === '' || isset($this->toolCancelled[$callId])) {
            return;
        }

        $this->toolCancelled[$callId] = true;
        $this->toolCancels[] = $callId;
    }

    /**
     * The call ids {@see cancelTool()} queued since the last call, oldest
     * first, each handed out once.
     *
     * @return list<string>
     */
    public function takeToolCancels(): array
    {
        $ids = $this->toolCancels;
        $this->toolCancels = [];

        return $ids;
    }

    /** Whether {@see cancelTool()} was ever called for $callId. */
    public function isToolCancelled(string $callId): bool
    {
        return isset($this->toolCancelled[$callId]);
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

    /**
     * Queue $text for delivery into the running turn at its next step
     * boundary, and answer the steer's id — or null when the turn is already
     * stopping (cancelled, or soft-cancelled: a turn on its way out has no
     * next boundary to deliver at).
     */
    public function steer(string $text): ?string
    {
        if ($this->isSoftCancelled() || trim($text) === '') {
            return null;
        }

        $id = 'st_' . ++$this->nextSteer;
        $this->steers[] = ['steerId' => $id, 'text' => $text];

        return $id;
    }

    /**
     * The steers queued since the last call, oldest first, each handed out
     * once — the turn's parent writes each down as a `steer` frame.
     *
     * @return list<array{steerId: string, text: string}>
     */
    public function takeSteers(): array
    {
        $steers = $this->steers;
        $this->steers = [];

        return $steers;
    }

    /** Record the child's `steer_ack`: $steerId was delivered at $step. */
    public function acknowledgeSteer(string $steerId, int $step): void
    {
        $this->acknowledged[$steerId] = $step;
    }

    /**
     * The steers the turn acknowledged, steerId => the step each was
     * delivered at.
     *
     * @return array<string, int>
     */
    public function acknowledgedSteers(): array
    {
        return $this->acknowledged;
    }
}
