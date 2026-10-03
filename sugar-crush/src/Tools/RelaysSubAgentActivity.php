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
 * forked child, where the pinned emitter drops every beat: a batch of Task
 * calls ran with no row in the Agents pane at all, while a single Task call
 * (run in-process) showed up fine. {@see \SugarCraft\Crush\Runtime::executeConcurrently()}
 * closes that gap through this seam: the child runs the copy
 * {@see withSubAgentEmitter()} returns, writing onto a per-member relay, and
 * the parent forwards each beat it reads back to {@see subAgentEmitter()} — in
 * the process the original emitter belongs to.
 */
interface RelaysSubAgentActivity
{
    /**
     * The emitter bound to this instance, or null when nobody is listening
     * (and so nothing needs relaying).
     *
     * @return (\Closure(\SugarCraft\Crush\Events\SubAgentActivity): void)|null
     */
    public function subAgentEmitter(): ?\Closure;

    /**
     * The same tool, reporting through $emitter instead.
     *
     * @param \Closure(\SugarCraft\Crush\Events\SubAgentActivity): void $emitter
     */
    public function withSubAgentEmitter(\Closure $emitter): Tool;
}
