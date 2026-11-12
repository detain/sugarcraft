<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Support;

use SugarCraft\Code\Agents\Agent;
use SugarCraft\Code\Agents\SubAgent;

/**
 * One builder for the throwaway SubAgent the AgentWorkerPool fork suites hand
 * the pool. It was three private copies — `AgentWorkerPoolForkFailureTest`,
 * `AgentWorkerPoolTeardownForkTotalTest` and (wf-impl P2-1)
 * `AgentWorkerPoolInjectedForkTest` — the third differing only in the agent's
 * name, which {@see DuplicatedTestHelperDriftTest} reads as a copy fixed in
 * one place. No suite asserts on the name, so one shared spelling is honest.
 */
trait BuildsPoolSubAgentTrait
{
    private static function agent(string $id): SubAgent
    {
        return new SubAgent(
            id: $id,
            agent: new Agent(
                name: 'PoolTestAgent',
                description: 'Test agent',
                prompt: 'You are a test agent.',
                model: 'test-model',
                provider: 'test',
                tools: [],
                skillNames: [],
                hooks: [],
                isActive: true,
            ),
            task: 'Test task for ' . $id,
        );
    }
}
