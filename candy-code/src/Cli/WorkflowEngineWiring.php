<?php

declare(strict_types=1);

namespace SugarCraft\Code\Cli;

use SugarCraft\Code\Agents\AgentWorkerPool;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Tools\Tool;
use SugarCraft\Code\Workflows\WorkflowEngine;
use SugarCraft\Code\Workflows\WorkflowRegistry;

/**
 * The launch's {@see WorkflowEngine} assembly — its worker pool, its session
 * wiring and its deferred preset roster — carved out of
 * {@see Bootstrap::workflowEngine()} under the M6 size ratchet
 * (`tests/Support/SourceSizeRatchetTest`). Bootstrap still owns everything
 * that decides WHAT the engine reads (the registry's trusted tiers, the tool
 * universe, the gate); this class only puts the engine together from it.
 */
final class WorkflowEngineWiring
{
    /**
     * @param list<Tool> $toolRegistry the session's model-facing tool set,
     *        already filtered by the user config (E641): a workflow task
     *        declares tool NAMES, and only an engine holding the Tool OBJECTS
     *        can resolve them for a provider
     */
    public static function engine(
        WorkflowRegistry $registry,
        ?string $root,
        PermissionGate $gate,
        string $provider,
        string $model,
        array $toolRegistry,
    ): WorkflowEngine {
        // E663: the engine's pool gets the SAME launch-derived worker spec the
        // chat sub-agent path was fed in E652 — reached through the same
        // Bootstrap::agentPoolConfig() so the selection logic lives in exactly
        // one place and cannot drift. `/workflow run` stages fork workers that
        // genuinely consult the model. NO `executor:` is injected here, on
        // purpose: an injected executor flips the pool onto the synchronous
        // in-parent dispatch documented at AgentWorkerPool::__construct() and
        // silently opts the engine out of the forking topology (and its live
        // progress) that the pool's own `workerProvider` parameter is the
        // production seam for. A null spec is a verdict, not a gap: the pool
        // builds its default refusing worker and every stage surfaces FAILED
        // naming the absence — this wiring deliberately never echo-degrades.
        $poolConfig = Bootstrap::agentPoolConfig();

        $engine = new WorkflowEngine(
            $registry,
            // forkedExecutor: every stage agent is a real tool loop through
            // the session engine (bound late by Chat's constructor), run in
            // the pool's forked child so a long stage never blocks the TUI.
            // ONLY WITH A PROVIDER: a launch with none degrades the CHAT to the
            // offline echo backend, and a stage run through that engine would
            // "succeed" with echoed text — so a spec-less pool keeps the
            // worker that fails closed naming the absence (E663). A spec whose
            // provider then fails to build lands on the same echo engine;
            // EngineExecutor refuses that one itself at run time.
            pool: (new AgentWorkerPool(
                maxConcurrent: $poolConfig->maxConcurrent,
                workerProvider: $poolConfig->workerProvider,
                forkedExecutor: $poolConfig->workerProvider === null ? null : new EngineExecutor(),
            ))->withStopOnFirstFailure($poolConfig->stopOnFirstFailure)->withMaxRetries($poolConfig->maxRetries),
            model: $model,
            provider: $provider,
            permissionGate: $gate,
            environmentRoot: $root,
            toolRegistry: $toolRegistry,
        );

        // P1-6 (wf-impl C5): a stage's `preset:` resolves against the same
        // roster `Task` uses, so CLI and TUI runs need no AgentManager for it.
        // DEFERRED: the roster reads preset files from disk, so it is built
        // the first time a plan actually names a preset, never at launch.
        // Chat's setAgentManager() still answers when no roster resolves.
        $engine->setAgentRosterSource(
            static fn (): array => Bootstrap::agentRoster($root ?? (getcwd() ?: '.'), $provider, $model),
        );

        return $engine;
    }
}
