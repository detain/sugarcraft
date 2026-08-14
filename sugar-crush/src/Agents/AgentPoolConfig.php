<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Configuration for the agent worker pool.
 *
 * Controls concurrency limits, timeout defaults, retry behavior, and which
 * executor strategy the pool uses when launching parallel agents. All values
 * are immutable after construction — use with*() methods to produce derived
 * instances.
 */
final readonly class AgentPoolConfig
{
    public function __construct(
        /**
         * Maximum number of agents allowed to run concurrently in the pool.
         * Defaults to 5, matching Claude Code's default.
         */
        public int $maxConcurrent = 5,

        /**
         * Default timeout in seconds for each agent execution.
         * Agents exceeding this limit are marked TimedOut.
         */
        public int $defaultTimeoutSeconds = 300,

        /**
         * Maximum number of retry attempts for a failed agent.
         * Set to 0 to disable retries.
         *
         * DORMANT SEAM — deliberately not consumed yet. {@see AgentWorkerPool}
         * takes maxConcurrent and an executor, not this config, and has no retry
         * loop at all; the per-sub-agent equivalent ({@see SubAgent::$maxRetries},
         * populated by WorkflowEngine from a task's `retries`) is likewise carried
         * but never acted on. Recorded here because it was suspected of being part
         * of the #54 executeAll() hang: it is not. A retry path that never retries
         * cannot hang a waiter — nothing waits on it. That hang came entirely from
         * AgentWorkerPool::$active retaining entries no completion path could
         * remove (see its docblock).
         *
         * Wiring point when retries are implemented: AgentWorkerPool::executeAll()
         * would re-queue an agent whose AgentResult::isFailure() is true, up to
         * this many attempts, instead of yielding it. That is a behavioural
         * decision, not a bug fix — a sub-agent that failed after editing files or
         * spending tokens is not automatically safe to run again — so it is left
         * for whoever owns that call.
         */
        public int $maxRetries = 2,

        /**
         * When true, the pool stops executing remaining agents as soon as
         * the first one fails. When false, all agents complete regardless.
         */
        public bool $stopOnFirstFailure = false,

        /**
         * The executor strategy used to run agents.
         * Process: fork separate PHP processes for true parallelism.
         * Async: event-loop based cooperative multitasking.
         * Hybrid: process pool for agents, async for coordination.
         */
        public ExecutorType $executorType = ExecutorType::Process,
    ) {}

    /**
     * Create a new config with a different maxConcurrent value.
     */
    public function withMaxConcurrent(int $maxConcurrent): self
    {
        return new self(
            maxConcurrent: $maxConcurrent,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            maxRetries: $this->maxRetries,
            stopOnFirstFailure: $this->stopOnFirstFailure,
            executorType: $this->executorType,
        );
    }

    /**
     * Create a new config with a different defaultTimeoutSeconds value.
     */
    public function withDefaultTimeoutSeconds(int $defaultTimeoutSeconds): self
    {
        return new self(
            maxConcurrent: $this->maxConcurrent,
            defaultTimeoutSeconds: $defaultTimeoutSeconds,
            maxRetries: $this->maxRetries,
            stopOnFirstFailure: $this->stopOnFirstFailure,
            executorType: $this->executorType,
        );
    }

    /**
     * Create a new config with a different maxRetries value.
     */
    public function withMaxRetries(int $maxRetries): self
    {
        return new self(
            maxConcurrent: $this->maxConcurrent,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            maxRetries: $maxRetries,
            stopOnFirstFailure: $this->stopOnFirstFailure,
            executorType: $this->executorType,
        );
    }

    /**
     * Create a new config with a different stopOnFirstFailure value.
     */
    public function withStopOnFirstFailure(bool $stopOnFirstFailure): self
    {
        return new self(
            maxConcurrent: $this->maxConcurrent,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            maxRetries: $this->maxRetries,
            stopOnFirstFailure: $stopOnFirstFailure,
            executorType: $this->executorType,
        );
    }

    /**
     * Create a new config with a different executorType value.
     */
    public function withExecutorType(ExecutorType $executorType): self
    {
        return new self(
            maxConcurrent: $this->maxConcurrent,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            maxRetries: $this->maxRetries,
            stopOnFirstFailure: $this->stopOnFirstFailure,
            executorType: $executorType,
        );
    }
}
