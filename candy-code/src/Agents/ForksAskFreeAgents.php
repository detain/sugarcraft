<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Providers\CompleteRequest;

/**
 * An INJECTED executor that may still have some of its agents forked
 * (workflow-update P2-1).
 *
 * {@see AgentWorkerPool} runs an injected executor synchronously in the
 * parent — the right default, since a test double does not survive
 * `pcntl_fork()` and the `Workflow` tool's stages must keep the turn's
 * approver. An executor implementing this is asked per agent instead: an agent
 * it clears is dispatched on the pool's forking path (same child, deadline,
 * reap and kill bookkeeping as a non-injected pool), anything else still runs
 * inline. The contract lives on the executor rather than the pool because a
 * workflow stage pool is rebuilt from `executor()` alone, so pool state would
 * not survive into it.
 */
interface ForksAskFreeAgents extends ExecutorInterface
{
    /**
     * Why $agent must run inline in the parent, or null when it may be forked.
     * Asked once per dispatch, with the per-agent request the run will get.
     */
    public function forkRefusal(SubAgent $agent, CompleteRequest $request): ?string;

    /**
     * The dispatch actually taken — $forked false with $why when the agent
     * ran inline (refused above, or the fork itself was unavailable/failed).
     */
    public function recordDispatch(SubAgent $agent, bool $forked, ?string $why): void;

    /**
     * Called in the parent on every idle poll while forked agents run — the
     * one point where the parent is waiting rather than working, so the place
     * to beat a liveness heartbeat the forked children cannot beat themselves.
     */
    public function whileForkedAgentsRun(): void;
}
