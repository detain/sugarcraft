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
    /**
     * The settings key that replaces {@see $maxConcurrent}'s default (roadmap
     * N-P4f): how many `Task` members of one batch run at once (decision D10),
     * and the width of every pool built from this config.
     */
    public const MAX_CONCURRENT_SETTINGS_KEY = 'subagentMaxConcurrent';

    /** {@see $maxConcurrent}'s default, named so the setting can cite it. */
    public const DEFAULT_MAX_CONCURRENT = 5;

    public function __construct(
        /**
         * Maximum number of agents allowed to run concurrently in the pool.
         * Defaults to 5, matching Claude Code's default.
         */
        public int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT,

        /**
         * Default timeout in seconds for each agent execution.
         * Agents exceeding this limit are marked TimedOut.
         */
        public int $defaultTimeoutSeconds = 300,

        /**
         * Pool-wide retry floor: every agent a pool built from this config
         * runs may be re-run up to this many times after a failed or
         * timed-out attempt, or up to its own {@see SubAgent::$maxRetries}
         * when that is higher (audit WF-1(b)). Applied through
         * {@see AgentWorkerPool::withMaxRetries()} by every site that builds a
         * pool from this config. 0, the default, disables the floor, so only
         * an agent that asks for retries (a workflow task's `retries`) gets
         * any.
         *
         * The default was 2 while nothing read this field. It is 0 now that
         * the pool acts on it, because a pool-wide retry re-runs agents that
         * never asked for it, and a sub-agent that failed after editing files
         * or spending tokens is not automatically safe to run again — that
         * choice belongs to whoever declares the agent, not to a default.
         */
        public int $maxRetries = 0,

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

        /**
         * THE PROVIDER SPEC FORKED SUB-AGENT WORKERS INHERIT (E652, closing the
         * E649 seam lane B left open).
         *
         * This is the serialized provider CONFIG — the same
         * `array<string, mixed>` shape {@see \SugarCraft\Crush\Providers\ProviderFactory::create()}
         * accepts (`['type' => ..., 'model' => ..., ...]`), plus the one spelling
         * the factory does not take that the live worker also honours:
         * `['type' => 'echo']` for the shipped offline
         * {@see \SugarCraft\Crush\Providers\EchoProvider}. `Chat::executeAgents()`
         * feeds it to BOTH sides of the default pool it builds —
         * `new ProcessExecutor(timeoutSeconds: ..., workerProvider: ...)` and
         * `new AgentWorkerPool(maxConcurrent: ..., executor: ..., workerProvider: ...)`
         * — and the executor puts it verbatim on the startup frame's `provider`
         * key ({@see ProcessExecutor::spawnWorker()}), which the child parses in
         * {@see ProcessExecutor::createLiveWorkerScript()}.
         *
         * NULL IS A VERDICT, NOT A DEFAULT LEFT ALONE: it means this session has
         * no serializable provider for sub-agents, and the forked worker then
         * refuses with FAILED naming the absence instead of inventing an answer.
         * The wiring deliberately does NOT fall back to `echo` here — an echo
         * sub-agent in production is exactly the silently-fabricated "Completed"
         * the E641-era refusal replaced. Production derives the spec only from a
         * configured provider; see {@see \SugarCraft\Crush\Cli\Bootstrap}.
         *
         * @var ?array<string, mixed>
         */
        public ?array $workerProvider = null,
    ) {}

    /**
     * This config with the settings that shape it applied (roadmap N-P4f):
     * `subagentMaxConcurrent` replaces {@see $maxConcurrent} when $config
     * sets a value its schema definition accepts; anything else leaves the
     * config as it is. `Bootstrap::agentPoolConfig()` passes the merged
     * config, so every pool a launch builds — and the engine's per-batch
     * `Task` cap — honours it.
     *
     * @param array<string, mixed> $config the merged config
     */
    public function withSettings(array $config): self
    {
        $maxConcurrent = \SugarCraft\Crush\Tools\ToolLimits::honoured($config, self::MAX_CONCURRENT_SETTINGS_KEY);

        return \is_int($maxConcurrent) && $maxConcurrent !== $this->maxConcurrent
            ? $this->withMaxConcurrent($maxConcurrent)
            : $this;
    }

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
            workerProvider: $this->workerProvider,
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
            workerProvider: $this->workerProvider,
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
            workerProvider: $this->workerProvider,
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
            workerProvider: $this->workerProvider,
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
            workerProvider: $this->workerProvider,
        );
    }

    /**
     * Create a new config whose forked sub-agent workers inherit this provider
     * spec (see the constructor's `$workerProvider` for what the array must
     * look like and why null means "refuse", not "echo").
     */
    public function withWorkerProvider(?array $workerProvider): self
    {
        return new self(
            maxConcurrent: $this->maxConcurrent,
            defaultTimeoutSeconds: $this->defaultTimeoutSeconds,
            maxRetries: $this->maxRetries,
            stopOnFirstFailure: $this->stopOnFirstFailure,
            executorType: $this->executorType,
            workerProvider: $workerProvider,
        );
    }
}
