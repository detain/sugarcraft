<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Worker pool for parallel agent execution.
 *
 * Manages concurrent agent execution with a configurable max limit (default 5),
 * returning results as they complete via a Generator. Coordinates execution through
 * an ExecutorInterface which handles the actual spawning mechanism.
 *
 * Mirrors charmbracelet/charmcrush AgentWorkerPool implementation.
 */
final class AgentWorkerPool
{
    /** @var array<string, SubAgent> Currently executing agents */
    private array $active = [];

    /** @var array<string, SubAgent> Queued agents */
    private array $queue = [];

    /** @var array<string, bool> Agent IDs marked for cancellation */
    private array $cancelled = [];

    /** @var bool When true, stop executing remaining agents on first failure */
    private bool $stopOnFirstFailure = false;

    /** @var ExecutorInterface|null The executor used for agent invocations */
    private ?ExecutorInterface $executor = null;

    /** True when a custom executor was injected (not the default ProcessExecutor). */
    private readonly bool $customExecutor;

    /** True when cancelAll() was called; cleared at the start of each executeAll(). */
    private bool $wasCancelledByUser = false;

    public function __construct(
        private readonly int $maxConcurrent = 5,
        ?ExecutorInterface $executor = null,
    ) {
        $this->executor = $executor;
        $this->customExecutor = $executor !== null;
    }

    /**
     * Execute all agents, yielding results as they complete.
     *
     * @param SubAgent[] $agents
     * @return \Generator<AgentResult>
     */
    public function executeAll(array $agents, CompleteRequest $request): \Generator
    {
        // If cancelAll() was called before this executeAll(), cancel all pending
        // agents before they are even queued. This makes cancelAll() effective
        // when called before executeAll() iteration begins.
        if ($this->wasCancelledByUser) {
            $this->wasCancelledByUser = false;
            return;
        }

        if ($agents === []) {
            return;
        }

        $this->queue = [];
        foreach ($agents as $agent) {
            $this->queue[$agent->id] = $agent;
        }
        $this->active = [];
        $this->cancelled = [];

        $executor = $this->executor ?? $this->createDefaultExecutor();

        while ($this->queue !== [] || $this->active !== []) {
            // Fill up to maxConcurrent slots
            while (count($this->active) < $this->maxConcurrent && $this->queue !== []) {
                $agent = array_shift($this->queue);
                if ($agent === null) {
                    break;
                }

                if (isset($this->cancelled[$agent->id])) {
                    unset($this->cancelled[$agent->id]);
                    continue;
                }

                $this->active[$agent->id] = $agent;

                // Start agent — try forking if available, fall back to sync
                $this->startAgent($agent, $request, $executor);
            }

            if ($this->active === []) {
                break;
            }

            // Wait for at least one to complete
            $completedId = $this->waitForCompletion();
            if ($completedId === null) {
                continue;
            }

            $result = $this->extractResult($completedId);
            if ($result !== null) {
                yield $result;

                if ($this->stopOnFirstFailure && $result->isFailure()) {
                    foreach (array_keys($this->queue) as $queuedId) {
                        $this->cancelled[$queuedId] = true;
                    }
                    $this->queue = [];
                }
            }
        }
    }

    /**
     * Execute a single agent and return the result immediately.
     */
    public function executeOne(SubAgent $agent, CompleteRequest $request): AgentResult
    {
        $executor = $this->executor ?? $this->createDefaultExecutor();
        return $executor->execute($agent, $request);
    }

    /**
     * Number of agents currently executing.
     */
    public function getActiveCount(): int
    {
        return count($this->active);
    }

    /**
     * Number of agents waiting to execute.
     */
    public function getQueueSize(): int
    {
        return count($this->queue);
    }

    /**
     * Cancel a specific agent by ID.
     *
     * If the agent is queued, it is removed from the queue.
     * If the agent is running, its executor is signalled to cancel.
     */
    public function cancel(string $agentId): void
    {
        if (isset($this->queue[$agentId])) {
            unset($this->queue[$agentId]);
            return;
        }

        if (isset($this->active[$agentId])) {
            $this->cancelled[$agentId] = true;
            $this->executor?->cancel($agentId);
        }
    }

    /**
     * Cancel all pending and running agents.
     *
     * Sets an internal flag so that a subsequent executeAll() call that has
     * not yet dispatched any agents will return immediately without running
     * anything.
     */
    public function cancelAll(): void
    {
        $this->wasCancelledByUser = true;

        foreach (array_keys($this->queue) as $queuedId) {
            $this->cancelled[$queuedId] = true;
        }
        $this->queue = [];

        $this->executor?->cancelAll();
        $this->active = [];
    }

    /**
     * Configure stopOnFirstFailure behavior.
     *
     * NOTE: This is an extension beyond the base 6-method spec — not in the
     * charmbracelet/charmcrush AgentWorkerPool contract.
     */
    public function withStopOnFirstFailure(bool $stop): self
    {
        $clone = clone $this;
        $clone->stopOnFirstFailure = $stop;
        return $clone;
    }

    /**
     * Returns the configured concurrency limit.
     */
    public function getMaxConcurrent(): int
    {
        return $this->maxConcurrent;
    }

    /**
     * Returns the executor instance used by this pool.
     */
    public function getExecutor(): ?ExecutorInterface
    {
        return $this->executor;
    }

    // -------------------------------------------------------------------------
    // Protected helpers — overridable for testing
    // -------------------------------------------------------------------------

    /**
     * Start an agent execution.
     *
     * When a custom executor is injected (e.g., for testing), runs synchronously
     * in the same process. When using the default ProcessExecutor, forks a child
     * process for true parallelism. The pool's concurrency management (dispatch
     * up to maxConcurrent, result collection, cancellation) is identical either way.
     */
    protected function startAgent(SubAgent $agent, CompleteRequest $request, ExecutorInterface $executor): void
    {
        // If a custom executor was injected, run synchronously.
        // PHPUnit mocks do not survive pcntl_fork across process boundaries.
        // The agent stays in $this->active until waitForCompletion extracts its result.
        if ($this->customExecutor) {
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            // Do NOT unset from active here — waitForCompletion handles removal
            return;
        }

        // Using the default ProcessExecutor — fork for true parallelism
        if (!function_exists('pcntl_fork')) {
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            unset($this->active[$agent->id]);
            return;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            // Fork failed — execute synchronously
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            unset($this->active[$agent->id]);
            return;
        }

        if ($pid === 0) {
            // Child process: execute and store result, then exit
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            exit(0);
        }

        // Parent: store a PID marker so waitForCompletion can find this agent
        $this->active['__pid:' . $pid . ':' . $agent->id] = $agent;
    }

    /**
     * Wait for at least one child process to complete.
     *
     * Returns the agent ID of the completed agent, or null if no agent
     * completed this cycle.
     */
    protected function waitForCompletion(): ?string
    {
        if (!function_exists('pcntl_wait')) {
            // No pcntl — check for any stored results (sync execution path)
            foreach ($this->active as $key => $agent) {
                if ($this->hasResult($agent->id)) {
                    unset($this->active[$key]);
                    return $agent->id;
                }
            }
            return null;
        }

        // Non-blocking wait for any child to exit
        $status = null;
        $pid = pcntl_wait($status, WNOHANG);

        if ($pid > 0) {
            // Find the agent with this PID
            $prefix = '__pid:' . $pid . ':';
            foreach ($this->active as $key => $agent) {
                if (str_starts_with($key, $prefix)) {
                    unset($this->active[$key]);
                    return $agent->id;
                }
            }
        }

        // Check if any sync agents (no PID) have completed
        foreach ($this->active as $key => $agent) {
            if ($this->hasResult($agent->id)) {
                unset($this->active[$key]);
                return $agent->id;
            }
        }

        return null;
    }

    /**
     * Store result to a temp file for inter-process communication.
     */
    protected function storeResult(string $agentId, AgentResult $result): void
    {
        $file = $this->resultFile($agentId);
        file_put_contents($file, serialize($result));
    }

    /**
     * Check if a result file exists.
     */
    protected function hasResult(string $agentId): bool
    {
        return file_exists($this->resultFile($agentId));
    }

    /**
     * Read and delete the result file for a completed agent.
     */
    protected function extractResult(string $agentId): ?AgentResult
    {
        $file = $this->resultFile($agentId);
        if (!file_exists($file)) {
            return null;
        }

        $data = file_get_contents($file);
        @unlink($file);

        if ($data === false) {
            return null;
        }

        // NOTE: allowed_classes=true is safe here because $data came from a temp file
        // we wrote ourselves via storeResult(); it cannot be attacker-controlled.
        $result = unserialize($data, ['allowed_classes' => true]);
        // If unserialize failed or returned something unexpected, treat as null
        if ($result === false || !$result instanceof AgentResult) {
            return null;
        }

        return $result;
    }

    /**
     * Path to the temp result file for an agent.
     */
    protected function resultFile(string $agentId): string
    {
        return sys_get_temp_dir() . '/sc_pool_' . basename($agentId) . '.result';
    }

    private function createDefaultExecutor(): ExecutorInterface
    {
        return new ProcessExecutor();
    }
}
