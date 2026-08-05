<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Contract for executing agents within the worker pool.
 *
 * Implementations provide the actual execution strategy — process-based,
 * async, or hybrid — while presenting a consistent interface to the pool
 * scheduler.
 */
interface ExecutorInterface
{
    /**
     * Execute a single agent to completion and return the result.
     */
    public function execute(SubAgent $agent, CompleteRequest $request): AgentResult;

    /**
     * Execute a single agent with streaming output, yielding partial results.
     *
     * @return \Generator<AgentResult>
     */
    public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator;

    /**
     * Cancel a specific agent execution by its ID.
     */
    public function cancel(string $agentId): void;

    /**
     * Cancel all currently running agent executions.
     */
    public function cancelAll(): void;
}
