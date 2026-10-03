<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tools;

/**
 * A {@see Tool} that reports a delegated run's beats through a
 * {@see \SugarCraft\Crush\Events\SubAgentActivity} emitter — today only
 * {@see BuiltIn\TaskTool}.
 *
 * The emitter {@see \SugarCraft\Crush\Backend\EngineBackend::turnTools()}
 * binds is pinned to the process that built it, because only that process may
 * write onto the turn's socket. A concurrent group runs each member in its own
 * forked child, where the pinned emitter drops every beat, so a batch of Task
 * calls would show nothing while a single Task call (run in-process) showed
 * up fine. {@see \SugarCraft\Crush\Runtime::executeConcurrently()} closes that
 * gap through this seam: before forking such a member it opens a per-member
 * datagram pair ({@see \SugarCraft\Crush\Support\SubAgentActivityRelay}), the
 * child runs the copy {@see withActivitySink()} returns, writing onto that
 * pair through a {@see DatagramActivitySink}, and the parent replays each
 * beat it reads back through {@see subAgentEmitter()} — in the process the
 * original emitter belongs to.
 *
 * The pid pin on the turn's own emitter stays: in the child the rebind
 * replaces the emitter outright, so the pinned one is never asked to write
 * from the wrong process.
 */
interface StreamsActivity
{
    /**
     * The emitter bound to this instance, or null when nobody is listening
     * (and so nothing needs relaying).
     *
     * @return (\Closure(\SugarCraft\Crush\Events\SubAgentActivity): void)|null
     */
    public function subAgentEmitter(): ?\Closure;

    /**
     * The same tool, reporting every beat to $sink instead of its emitter.
     */
    public function withActivitySink(ActivitySink $sink): Tool;

    /**
     * The beat that stands for $call while it waits for a delegation slot
     * (step 0.16): its row reads "queued" instead of looking like a run that
     * has started. Null when the call says too little to name a row. The
     * forking process sends it through {@see subAgentEmitter()}; the run's
     * own `started` replaces it.
     *
     * @param array<string, mixed> $args the gated arguments the call will run with
     */
    public function queuedActivity(ToolCall $call, array $args): ?\SugarCraft\Crush\Events\SubAgentActivity;
}
