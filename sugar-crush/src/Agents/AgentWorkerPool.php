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

    /** Poll backoff for waitForCompletion() when nothing has completed yet (microseconds). */
    private const WAIT_POLL_INTERVAL_USEC = 5_000;

    /** True once the sequential-fallback warning has been logged for this pool instance. */
    private bool $sequentialFallbackWarned = false;

    /**
     * @internal Test-only seam. When non-null, overrides pcntlForkAvailable()
     * so the sequential-fallback path can be exercised deterministically via
     * Reflection, without requiring an environment that actually lacks pcntl.
     */
    private ?bool $forcePcntlUnavailableForTesting = null;

    /**
     * Absolute path to this pool instance's private IPC directory.
     *
     * Result files used to live directly in sys_get_temp_dir() under a name
     * derived only from the agent id, so two processes (or two pools) running
     * the same agent id read and clobbered each other's results — exactly what
     * happened when sibling git worktrees ran their suites concurrently and
     * produced an intermittent FanOutResearchTest failure. It was also a
     * predictable path in a world-writable directory, i.e. a symlink-attack
     * target. One unpredictable 0700 directory per pool instance fixes both.
     */
    private string $resultDir;

    /**
     * PID that created $resultDir. Forked children inherit this object and run
     * destructors on exit(), so only the creating process may delete the
     * directory — otherwise a child would erase the very result file the
     * parent is still waiting to read.
     */
    private int $resultDirOwnerPid;

    public function __construct(
        private readonly int $maxConcurrent = 5,
        ?ExecutorInterface $executor = null,
    ) {
        $this->executor = $executor;
        $this->customExecutor = $executor !== null;
        // The path is fixed at construction (not lazily on first write) because
        // forked children must derive the SAME path the parent polls; a lazily
        // randomised path would differ per process and hang the pool.
        $this->resultDir = self::makeResultDirPath();
        $this->resultDirOwnerPid = (int) getmypid();
    }

    /**
     * A clone (see withStopOnFirstFailure()) gets its own IPC directory so the
     * two instances never observe each other's results and neither destructor
     * removes files the other still owns.
     */
    public function __clone(): void
    {
        $this->resultDir = self::makeResultDirPath();
        $this->resultDirOwnerPid = (int) getmypid();
    }

    /**
     * Remove this pool's private IPC directory, if this process created it.
     */
    public function __destruct()
    {
        if ($this->resultDirOwnerPid !== (int) getmypid()) {
            return;
        }

        if (!is_dir($this->resultDir)) {
            return;
        }

        foreach (glob($this->resultDir . '/*.result') ?: [] as $leftover) {
            @unlink($leftover);
        }
        @rmdir($this->resultDir);
    }

    /**
     * Execute all agents, yielding results as they complete.
     *
     * @param SubAgent[] $agents
     *
     * For each agent, this method builds a per-agent CompleteRequest using the
     * agent's ->task field as the user message.  This ensures that parallel
     * stages where each agent has a distinct prompt work correctly — a future
     * executor that reads $request->messages directly (rather than using the
     * agent's task field) would still receive the correct per-agent prompt.
     *
     * The $request parameter supplies shared fields (model, tools, systemPrompt,
     * temperature, maxTokens) that are common across all agents in the pool.
     *
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

                // Build a per-agent request from the agent's task field so that
                // each agent's executor receives its own prompt. This ensures
                // parallel stages with non-identical prompts work correctly —
                // a future executor that uses $request->messages directly (rather
                // than the agent's task field) would otherwise receive only the
                // first task's prompt for all agents.
                $agentRequest = new CompleteRequest(
                    model: $request->model,
                    messages: [
                        ['role' => 'user', 'content' => $agent->task],
                    ],
                    tools: $request->tools,
                    systemPrompt: $request->systemPrompt,
                    temperature: $request->temperature,
                    maxTokens: $request->maxTokens,
                );

                // Start agent — try forking if available, fall back to sync
                $this->startAgent($agent, $agentRequest, $executor);
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
     * Configure stopOnFirstFailure behavior for this pool instance.
     *
     * Called by WorkflowEngine::executeParallelStage() when the workflow has
     * $stopOnFirstFailure=true.  The flag is checked inside executeAll() after
     * each agent result is collected to cancel remaining queued agents on failure.
     *
     * Implementation chain: WorkflowBuilder::stopOnFirstFailure() -> WorkflowEngine
     * reads Workflow::$stopOnFirstFailure -> calls withStopOnFirstFailure() here ->
     * executeAll() checks $this->stopOnFirstFailure when yielding results.
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
        if (!$this->pcntlForkAvailable()) {
            $this->warnSequentialFallback();
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            // Do NOT unset from active here — waitForCompletion's sync-result
            // check (hasResult()) handles removal, same as the customExecutor
            // path above. Removing it here instead would drop the agent from
            // $active before executeAll()'s outer loop ever calls
            // waitForCompletion()/extractResult() for it, silently discarding
            // the result it just stored.
            return;
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            // Fork failed — execute synchronously. Same reasoning as above:
            // leave the agent in $active for waitForCompletion() to reap.
            $result = $executor->execute($agent, $request);
            $this->storeResult($agent->id, $result);
            return;
        }

        if ($pid === 0) {
            // Child process: execute and store result, then exit. A plain
            // exit() is safe here (not ForkedChild::exitNow()) because the
            // one thing that made a bare exit() dangerous - an inherited
            // raw-mode Tty's destructor clobbering the real terminal on the
            // way out - is now fixed at the ROOT in candy-core's
            // PosixBackend::restore() (PID-aware; see #1406). This path is
            // also currently unreachable from bin/sugarcrush's live path
            // (Renderer.php's R20.fix docblock), so there is no live-TUI
            // scenario this protects that candy-core doesn't already cover.
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
            // Nothing completed yet — back off briefly instead of busy-spinning
            // the CPU while waiting for a result file to appear.
            usleep(self::WAIT_POLL_INTERVAL_USEC);
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

        // No child exited and no sync result appeared this cycle — sleep
        // briefly before the caller polls again. Without this, the WNOHANG
        // wait above turns executeAll()'s outer loop into a hot CPU spin
        // while forked children are still running.
        usleep(self::WAIT_POLL_INTERVAL_USEC);

        return null;
    }

    /**
     * Store result to a temp file for inter-process communication.
     *
     * Uses JSON rather than serialize() — the result is a plain DTO (a status
     * enum, scalars, and an optional error message), so there is no need for
     * unserialize()'s arbitrary-class instantiation on the read side, which is
     * a latent security smell even when the file is one we wrote ourselves.
     *
     * If json_encode() still fails despite resultToArray() sanitizing
     * non-finite floats (belt-and-suspenders for any future field that isn't
     * JSON-safe), we must NOT skip the write: hasResult()/waitForCompletion()
     * key entirely off file_exists(), so a skipped write leaves the agent
     * stuck in $active forever and executeAll()'s outer loop never
     * terminates — a silent infinite hang, strictly worse than the busy-spin
     * this IPC mechanism replaced. Fall back to a minimal failure payload
     * (all-scalar, always JSON-safe) so the pool always makes progress.
     */
    protected function storeResult(string $agentId, AgentResult $result): void
    {
        $this->ensureResultDir();
        $file = $this->resultFile($agentId);
        $json = json_encode($this->resultToArray($result), JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            $json = json_encode([
                'agentId' => $agentId,
                'status' => AgentStatus::Failed->value,
                'output' => null,
                'errorMessage' => 'AgentWorkerPool: failed to encode agent result to JSON for IPC.',
                'tokensUsed' => 0,
                'costUsd' => 0.0,
                'startedAt' => null,
                'completedAt' => null,
            ]);
        }

        file_put_contents($file, $json === false ? '' : $json);
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

        if ($data === false || $data === '') {
            return null;
        }

        $decoded = json_decode($data, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $this->arrayToResult($decoded);
    }

    /**
     * Path to the temp result file for an agent, inside this pool instance's
     * private IPC directory.
     *
     * The agent id is hashed rather than passed through basename(): the id is
     * caller-supplied, and basename() was only ever incidental sanitisation —
     * it still lets through names that collide with siblings ('.result') or
     * that are not valid filenames. A hash is total, collision-free in
     * practice, and identical in the parent and in a forked child.
     */
    protected function resultFile(string $agentId): string
    {
        return $this->resultDir . '/' . hash('sha256', $agentId) . '.result';
    }

    /**
     * Build an unpredictable, process-qualified path for a pool's IPC directory.
     *
     * The random suffix is what defeats the symlink/pre-creation attack the old
     * fixed path invited; the PID keeps the name self-describing when a stale
     * directory has to be tracked down by hand.
     */
    private static function makeResultDirPath(): string
    {
        try {
            $suffix = bin2hex(random_bytes(8));
        } catch (\Throwable) {
            // random_bytes() only fails when the CSPRNG is unavailable; a
            // unique-but-guessable name is still better than a shared one.
            $suffix = str_replace('.', '', uniqid('', true));
        }

        return sys_get_temp_dir() . '/sc_pool_' . getmypid() . '_' . $suffix;
    }

    /**
     * Create this pool's private IPC directory on first write.
     *
     * 0700 keeps agent output (which can contain anything the model produced)
     * out of other local users' reach and stops them planting symlinks for us
     * to write through. Called from storeResult() only — the read-side helpers
     * work off the path alone, so merely polling for a result never litters
     * temp with directories.
     */
    private function ensureResultDir(): void
    {
        if (is_dir($this->resultDir)) {
            return;
        }

        // Concurrent forked children race to create the shared directory; the
        // loser's mkdir() fails but the directory it needed now exists.
        if (!@mkdir($this->resultDir, 0700, true) && !is_dir($this->resultDir)) {
            throw new \RuntimeException(
                'AgentWorkerPool: could not create the IPC directory ' . $this->resultDir
            );
        }
    }

    /**
     * Whether pcntl_fork() is available in this process.
     *
     * Factored out (rather than calling function_exists('pcntl_fork') inline)
     * so a test can force the sequential-fallback path deterministically via
     * the forcePcntlUnavailableForTesting seam, without requiring a real
     * environment that lacks the pcntl extension.
     */
    protected function pcntlForkAvailable(): bool
    {
        if ($this->forcePcntlUnavailableForTesting !== null) {
            return !$this->forcePcntlUnavailableForTesting;
        }

        return function_exists('pcntl_fork');
    }

    /**
     * Log a visible warning the first time this pool falls back to
     * sequential (non-parallel) execution because pcntl_fork() is
     * unavailable. Only fires once per pool instance — subsequent agents
     * hitting the same fallback path would otherwise spam the log.
     */
    protected function warnSequentialFallback(): void
    {
        if ($this->sequentialFallbackWarned) {
            return;
        }

        $this->sequentialFallbackWarned = true;
        error_log(
            'AgentWorkerPool: pcntl_fork() is unavailable — falling back to '
            . 'sequential (non-parallel) agent execution. Install/enable the '
            . 'pcntl extension to restore concurrent agent execution.'
        );
    }

    /**
     * Convert an AgentResult to a JSON-safe plain array for IPC.
     *
     * The ?Throwable $error field cannot round-trip through JSON as-is, so
     * only its message survives — sufficient for isFailure() semantics and
     * for surfacing the failure reason, without resurrecting an arbitrary
     * exception class via unserialize().
     *
     * costUsd is sanitized to null when non-finite (NAN/INF/-INF): unlike
     * serialize(), json_encode() has no representation for non-finite floats
     * and returns false for the whole payload if one slips through, which
     * (before this guard) silently skipped the result-file write entirely and
     * hung the pool forever. arrayToResult()'s `?? 0.0` coalesces the null
     * back to a safe default on the read side.
     */
    private function resultToArray(AgentResult $result): array
    {
        return [
            'agentId' => $result->agentId,
            'status' => $result->status->value,
            'output' => $result->output,
            'errorMessage' => $result->error?->getMessage(),
            'tokensUsed' => $result->tokensUsed,
            'costUsd' => is_finite($result->costUsd) ? $result->costUsd : null,
            'startedAt' => $result->startedAt?->format('U.u'),
            'completedAt' => $result->completedAt?->format('U.u'),
        ];
    }

    /**
     * Reconstruct an AgentResult from the array produced by resultToArray().
     *
     * Returns null when the decoded payload is missing a recognizable status
     * — treated the same as a corrupt/unreadable result file.
     */
    private function arrayToResult(array $decoded): ?AgentResult
    {
        $status = is_string($decoded['status'] ?? null) ? AgentStatus::tryFrom($decoded['status']) : null;
        if ($status === null) {
            return null;
        }

        $errorMessage = $decoded['errorMessage'] ?? null;

        return new AgentResult(
            agentId: (string) ($decoded['agentId'] ?? ''),
            status: $status,
            output: $decoded['output'] ?? null,
            error: is_string($errorMessage) ? new \RuntimeException($errorMessage) : null,
            tokensUsed: (int) ($decoded['tokensUsed'] ?? 0),
            costUsd: (float) ($decoded['costUsd'] ?? 0.0),
            startedAt: $this->parseTimestamp($decoded['startedAt'] ?? null),
            completedAt: $this->parseTimestamp($decoded['completedAt'] ?? null),
        );
    }

    /**
     * Parse a 'U.u'-formatted timestamp string back into a DateTimeImmutable.
     */
    private function parseTimestamp(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('U.u', $value);
        return $parsed !== false ? $parsed : null;
    }

    private function createDefaultExecutor(): ExecutorInterface
    {
        return new ProcessExecutor();
    }
}
