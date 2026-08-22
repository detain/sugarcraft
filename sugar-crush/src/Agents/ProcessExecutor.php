<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Providers\CompleteRequest;

/**
 * Process-based executor using proc_open() for true parallelism.
 *
 * Spawns separate PHP worker processes for each agent, communicating via
 * stdin/stdout pipes using JSON messages. Each message is a single line
 * terminated by a newline character.
 *
 * Mirrors charmbracelet/charmcrush ProcessExecutor implementation.
 */
final class ProcessExecutor implements ExecutorInterface
{
    /** @var array<string, resource> PID -> process descriptor */
    private array $processes = [];

    /** @var array<string, int> agentId -> last heartbeat timestamp (Unix time) */
    private array $lastHeartbeat = [];

    /** Heartbeat interval the worker sends messages at (seconds). */
    private const HEARTBEAT_INTERVAL_SECS = 5;

    /** How long the parent waits for a heartbeat before declaring worker dead (seconds). */
    private const HEARTBEAT_TIMEOUT_SECS = 15;

    /** Grace period between SIGTERM and SIGKILL (seconds). */
    private const SIGTERM_GRACE_SECS = 5;

    /** Default fraction of available memory above which we pause scheduling (0.0–1.0). */
    private const DEFAULT_MEMORY_THRESHOLD = 0.8;

    public function __construct(
        private readonly string $binaryPath = 'php',
        private readonly ?int $timeoutSeconds = 300,
        /** Memory usage fraction above which new task scheduling is paused (0.0–1.0). */
        private readonly float $memoryPressureThreshold = self::DEFAULT_MEMORY_THRESHOLD,
    ) {}

    /**
     * Execute a single agent to completion and return the result.
     *
     * Spawns a worker process, sends the agent configuration, and waits for
     * the complete message before returning. The worker process is cleaned
     * up after completion.
     */
    public function execute(SubAgent $agent, CompleteRequest $request): AgentResult
    {
        $this->checkBackpressure();

        $process = $this->spawnWorker($agent, $request);

        $buffer = '';
        $startTime = new \DateTimeImmutable();
        $this->lastHeartbeat[$agent->id] = time();

        // Use non-blocking reads so we can enforce timeouts and heartbeats
        stream_set_blocking($process['stdout'], false);

        $timeoutDeadline = $this->timeoutSeconds !== null
            ? time() + $this->timeoutSeconds
            : null;

        // Read until we get a complete or error message
        while (!feof($process['stdout'])) {
            $heartbeatDeadline = $this->lastHeartbeat[$agent->id] + self::HEARTBEAT_TIMEOUT_SECS;
            $checkDeadline = $timeoutDeadline !== null
                ? min($heartbeatDeadline, $timeoutDeadline)
                : $heartbeatDeadline;

            $timeoutUsec = max(100_000, ($checkDeadline - time()) * 1_000_000);

            $read = [$process['stdout']];
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, $timeoutUsec);

            if ($changed === false) {
                $this->closeProcess($process);
                unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Failed,
                    output: $buffer ?: null,
                    error: new \RuntimeException('stream_select interrupted'),
                    startedAt: $startTime,
                    completedAt: new \DateTimeImmutable(),
                );
            }

            if ($changed === 0) {
                $now = time();
                if ($timeoutDeadline !== null && $now >= $timeoutDeadline) {
                    $this->escalateAndKill($process['process'], $agent->id);
                    $this->closeProcess($process);
                    unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                    return new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Failed,
                        output: $buffer ?: null,
                        error: new \RuntimeException('Worker timed out'),
                        startedAt: $startTime,
                        completedAt: new \DateTimeImmutable(),
                    );
                }

                if ($now >= $heartbeatDeadline) {
                    $this->escalateAndKill($process['process'], $agent->id);
                    $this->closeProcess($process);
                    unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                    return new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Failed,
                        output: $buffer ?: null,
                        error: new \RuntimeException('Worker heartbeat timeout — process unresponsive'),
                        startedAt: $startTime,
                        completedAt: new \DateTimeImmutable(),
                    );
                }

                // No data and no deadline expired — loop again with fresh select
                continue;
            }

            // Data is ready — read it
            $line = fgets($process['stdout']);
            if ($line !== false && $line !== '') {
                $buffer .= $line;
                $message = json_decode(trim($line), true);

                if ($message !== null) {
                    if (($message['type'] ?? '') === 'heartbeat') {
                        $this->lastHeartbeat[$agent->id] = time();
                        // After a heartbeat, re-check if overall timeout has already passed
                        if ($timeoutDeadline !== null && time() >= $timeoutDeadline) {
                            $this->escalateAndKill($process['process'], $agent->id);
                            $this->closeProcess($process);
                            unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                            return new AgentResult(
                                agentId: $agent->id,
                                status: AgentStatus::Failed,
                                output: $buffer ?: null,
                                error: new \RuntimeException('Worker timed out'),
                                startedAt: $startTime,
                                completedAt: new \DateTimeImmutable(),
                            );
                        }
                        continue;
                    }

                    if (($message['type'] ?? '') === 'complete') {
                        $this->closeProcess($process);
                        unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                        return $this->buildResult($message, $agent->id, $startTime);
                    }

                    if (($message['type'] ?? '') === 'error') {
                        $this->closeProcess($process);
                        unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                        return new AgentResult(
                            agentId: $agent->id,
                            status: AgentStatus::Failed,
                            error: new \RuntimeException($message['message'] ?? 'Unknown error'),
                            startedAt: $startTime,
                            completedAt: new \DateTimeImmutable(),
                        );
                    }
                }
            }
        }

        // Worker exited without complete/error — check for crash exit code
        $exitCode = $this->getExitCode($process['process']);
        $this->closeProcess($process);
        unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);

        if ($exitCode !== 0) {
            return new AgentResult(
                agentId: $agent->id,
                status: AgentStatus::Failed,
                output: $buffer ?: null,
                error: new \RuntimeException("Worker process exited with code {$exitCode}"),
                startedAt: $startTime,
                completedAt: new \DateTimeImmutable(),
            );
        }

        return new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Failed,
            output: $buffer ?: null,
            error: new \RuntimeException('Worker process ended without complete message'),
            startedAt: $startTime,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Execute a single agent with streaming output, yielding partial results.
     *
     * Spawns a worker process and yields each streaming message as an AgentResult.
     * The caller iterates over the Generator to receive chunks as they arrive.
     */
    public function executeStream(SubAgent $agent, CompleteRequest $request): \Generator
    {
        $this->checkBackpressure();

        $process = $this->spawnWorker($agent, $request);
        $startTime = new \DateTimeImmutable();
        $this->lastHeartbeat[$agent->id] = time();

        // Use non-blocking reads for timeout and heartbeat enforcement
        stream_set_blocking($process['stdout'], false);

        $timeoutDeadline = $this->timeoutSeconds !== null
            ? time() + $this->timeoutSeconds
            : null;

        while (!feof($process['stdout'])) {
            $heartbeatDeadline = $this->lastHeartbeat[$agent->id] + self::HEARTBEAT_TIMEOUT_SECS;
            $checkDeadline = $timeoutDeadline !== null
                ? min($heartbeatDeadline, $timeoutDeadline)
                : $heartbeatDeadline;

            $timeoutUsec = max(100_000, ($checkDeadline - time()) * 1_000_000);

            $read = [$process['stdout']];
            $write = null;
            $except = null;
            $changed = @stream_select($read, $write, $except, 0, $timeoutUsec);

            if ($changed === false || $changed === 0) {
                $now = time();
                if ($timeoutDeadline !== null && $now >= $timeoutDeadline) {
                    $this->escalateAndKill($process['process'], $agent->id);
                    $this->closeProcess($process);
                    unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                    yield new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Failed,
                        error: new \RuntimeException('Worker timed out'),
                        startedAt: $startTime,
                        completedAt: new \DateTimeImmutable(),
                    );
                    return;
                }

                if ($now >= $heartbeatDeadline) {
                    $this->escalateAndKill($process['process'], $agent->id);
                    $this->closeProcess($process);
                    unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                    yield new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Failed,
                        error: new \RuntimeException('Worker heartbeat timeout — process unresponsive'),
                        startedAt: $startTime,
                        completedAt: new \DateTimeImmutable(),
                    );
                    return;
                }

                if ($changed === 0) {
                    // No data, not a timeout — loop again
                    continue;
                }

                // stream_select returned false (error)
                $this->closeProcess($process);
                unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Failed,
                    error: new \RuntimeException('stream_select failed'),
                    startedAt: $startTime,
                    completedAt: new \DateTimeImmutable(),
                );
                return;
            }

            $line = fgets($process['stdout']);
            if ($line === false || $line === '') {
                continue;
            }

            $message = json_decode(trim($line), true);
            if ($message === null) {
                continue;
            }

            $type = $message['type'] ?? '';

            if ($type === 'heartbeat') {
                $this->lastHeartbeat[$agent->id] = time();
                // After a heartbeat, re-check if overall timeout has already passed
                if ($timeoutDeadline !== null && time() >= $timeoutDeadline) {
                    $this->escalateAndKill($process['process'], $agent->id);
                    $this->closeProcess($process);
                    unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                    yield new AgentResult(
                        agentId: $agent->id,
                        status: AgentStatus::Failed,
                        error: new \RuntimeException('Worker timed out'),
                        startedAt: $startTime,
                        completedAt: new \DateTimeImmutable(),
                    );
                    return;
                }
                continue;
            }

            if ($type === 'streaming') {
                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Streaming,
                    output: $message['content'] ?? '',
                    startedAt: $startTime,
                );
                continue;
            }

            if ($type === 'complete') {
                $this->closeProcess($process);
                unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                yield $this->buildResult($message, $agent->id, $startTime);
                return;
            }

            if ($type === 'error') {
                $this->closeProcess($process);
                unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);
                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Failed,
                    error: new \RuntimeException($message['message'] ?? 'Unknown error'),
                    startedAt: $startTime,
                    completedAt: new \DateTimeImmutable(),
                );
                return;
            }
        }

        $exitCode = $this->getExitCode($process['process']);
        $this->closeProcess($process);
        unset($this->lastHeartbeat[$agent->id], $this->processes[$agent->id]);

        if ($exitCode !== 0) {
            yield new AgentResult(
                agentId: $agent->id,
                status: AgentStatus::Failed,
                error: new \RuntimeException("Worker process exited with code {$exitCode}"),
                startedAt: $startTime,
                completedAt: new \DateTimeImmutable(),
            );
            return;
        }

        yield new AgentResult(
            agentId: $agent->id,
            status: AgentStatus::Failed,
            error: new \RuntimeException('Worker process ended without complete message'),
            startedAt: $startTime,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Cancel a specific agent execution by its ID.
     *
     * Sends SIGTERM to the worker process, allowing graceful shutdown.
     * Stub for P1.S5 — full cancellation with SIGKILL escalation comes in P1.S6.
     */
    public function cancel(string $agentId): void
    {
        if (!isset($this->processes[$agentId])) {
            return;
        }

        $process = $this->processes[$agentId];

        // Only terminate if process is still valid (not already closed)
        if (is_resource($process['process'])) {
            proc_terminate($process['process'], SIGTERM);
        }
        $this->closeProcess($process);
        unset($this->processes[$agentId]);
    }

    /**
     * Cancel all currently running agent executions.
     *
     * Sends SIGTERM to all worker processes. Stub for P1.S5.
     */
    public function cancelAll(): void
    {
        foreach ($this->processes as $agentId => $process) {
            proc_terminate($process['process'], SIGTERM);
            $this->closeProcess($process);
        }

        $this->processes = [];
    }

    /**
     * Spawn a worker process for the given agent.
     *
     * Creates a proc_open descriptor with stdin/stdout pipes and starts
     * a PHP worker script that handles the agent execution.
     *
     * @return array{process: resource, stdin: resource, stdout: resource, stderr: resource}
     */
    private function spawnWorker(SubAgent $agent, CompleteRequest $request): array
    {
        $workerScript = $this->createInlineWorkerScript();

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        $process = @proc_open(
            [$this->binaryPath, '-r', $workerScript],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if ($process === false || !is_resource($process)) {
            // Clean up any pipes that were opened before the failure
            if (isset($pipes) && is_array($pipes)) {
                foreach ($pipes as $pipe) {
                    if (is_resource($pipe)) {
                        fclose($pipe);
                    }
                }
            }
            throw new \RuntimeException('Failed to spawn worker process');
        }

        // Disable blocking on stdout to allow non-blocking reads
        stream_set_blocking($pipes[0], false);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        // Send startup message with agent config
        $startupMessage = json_encode([
            'type' => 'startup',
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->agent->name,
                'model' => $agent->agent->model,
                'prompt' => $agent->agent->systemPrompt(),
            ],
            'task' => $agent->task,
            'request' => [
                'model' => $request->model,
                'messages' => $request->messages,
                'tools' => $request->tools,
                'systemPrompt' => $request->systemPrompt,
                'temperature' => $request->temperature,
                'maxTokens' => $request->maxTokens,
            ],
        ]) . "\n";

        fwrite($pipes[0], $startupMessage);
        fflush($pipes[0]);

        // Wait for ready message
        $ready = false;
        $deadline = time() + 5;
        while (!$ready && time() < $deadline) {
            if (feof($pipes[1])) {
                break;
            }
            $line = fgets($pipes[1]);
            if ($line !== false) {
                $msg = json_decode(trim($line), true);
                if (($msg['type'] ?? '') === 'ready') {
                    $ready = true;
                }
            }
        }

        // Send execute message
        $executeMessage = json_encode(['type' => 'execute']) . "\n";
        fwrite($pipes[0], $executeMessage);
        fflush($pipes[0]);

        $processDescriptor = [
            'process' => $process,
            'stdin' => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];

        $this->processes[$agent->id] = $processDescriptor;

        return $processDescriptor;
    }

    /**
     * Create an inline PHP worker script for agent execution.
     *
     * This script runs in the spawned worker process and handles the
     * IPC protocol with the parent ProcessExecutor.
     *
     * ## THIS WORKER IS A SIMULATION, AND THAT IS AN INTENTIONAL SEAM
     *
     * It reads the startup config, answers `ready`, waits for `execute`, then
     * echoes the task back over two `streaming` messages and one `complete`,
     * spacing them with `usleep()` so a caller sees a worker that takes about a
     * second. Every byte it emits is fabricated here. Nothing in it contacts a
     * model.
     *
     * ### WHAT THIS COMMENT USED TO SAY
     *
     * "For P1.S5, this is a simplified simulation that doesn't actually call
     * an LLM — that wiring comes in later phases", repeated inside the script
     * as "Real LLM integration comes in later phases".
     *
     * ### WHAT IS TRUE NOW
     *
     * The phase it deferred to has come and gone and the stub is still here,
     * so "later phases" has stopped being a schedule and become a description
     * of nothing. What is genuinely real around it is worth stating precisely,
     * because a reader who sees "simulation" tends to discount the whole
     * mechanism: {@see spawnWorker()} really does `proc_open()` a second PHP
     * process, the JSON line protocol really is spoken over real pipes,
     * {@see \SugarCraft\Crush\Agents\AgentWorkerPool::pumpProgress()} really
     * does mirror each `streaming` chunk onto the live `SubAgent`, and the
     * split-pane compositor really does paint those bytes mid-run. The
     * transport is production. The MOUTH at the far end is not.
     *
     * ### WHY IT STILL EARNS ITS PLACE
     *
     * Because it is not one edit away from being real, and a half-real worker
     * would be worse than an honestly fake one. A worker that talked to a model
     * needs, at minimum: the composer autoloader bootstrapped inside a `php -r`
     * child that today has no autoloader at all; a provider IDENTITY and its
     * credentials carried across the startup message; and an offline substitute
     * for CI, which has no model to call — so a fake provider has to remain
     * constructible in the child either way, i.e. this simulation does not
     * disappear even then, it moves behind a seam.
     *
     * ⚠️ WHAT THIS USED TO SAY about the second of those: that the startup
     * message "currently ships only
     * `model`/`messages`/`tools`/`systemPrompt`/`temperature`/`maxTokens`".
     * WHAT IS TRUE NOW — read off {@see spawnWorker()}'s `$startupMessage`
     * rather than off this sentence — is that those six are the `request`
     * sub-object, and the line also carries `agent.id`, `agent.name`,
     * `agent.model`, `agent.prompt` and `task`. WHY THE POINT STILL STANDS,
     * and in fact stands harder: none of those eleven fields is a provider
     * identity or a credential. `SugarCraft\Crush\Agents\Agent` even HAS a
     * `provider` field — `spawnWorker()` does not forward it — so the child is
     * told which model to pretend to be and never which service could serve
     * it, let alone with what key. Naming a provider is an addition to the
     * protocol, not a field somebody forgot to read.
     *
     * Until that lands, this is the shipped default: {@see __construct()}'s
     * `$binaryPath` is plain `php`,
     * {@see \SugarCraft\Crush\Agents\AgentWorkerPool::createDefaultExecutor()}
     * builds one of these with no arguments, and
     * {@see \SugarCraft\Crush\Chat::executeAgents()} builds another from
     * `AgentPoolConfig`. Anything a user sees in the agent pane came from the
     * script below.
     *
     * Recorded as E59 in `docs/plans/crush_code_hardening_backlog.md`. Do not
     * delete the simulation to "clean it up" — deleting it removes the only
     * exercise the fork/pipe/pump/compositor chain has.
     */
    private function createInlineWorkerScript(): string
    {
        // NOTE: When using `php -r`, the code is executed directly without
        // an opening `<?php` tag, so we omit it here.
        return <<<'PHP'
declare(strict_types=1);

// Worker process: reads config from stdin, sends ready, processes task, streams output.

$agentConfig = null;
$task = null;

// ---- Read startup message from parent ----
while (!feof(STDIN)) {
    $line = fgets(STDIN);
    if ($line === false) {
        break;
    }
    $msg = json_decode(trim($line), true);
    if (($msg['type'] ?? '') === 'startup') {
        $agentConfig = $msg['agent'] ?? [];
        $task = $msg['task'] ?? '';
        break;
    }
}

// ---- Send ready message ----
$readyMsg = json_encode(['type' => 'ready']) . "\n";
fwrite(STDOUT, $readyMsg);
fflush(STDOUT);

// ---- Wait for execute message ----
$executeReceived = false;
$deadline = time() + 5;
while (!$executeReceived && time() < $deadline) {
    if (feof(STDIN)) {
        break;
    }
    $line = fgets(STDIN);
    if ($line !== false) {
        $msg = json_decode(trim($line), true);
        if (($msg['type'] ?? '') === 'execute') {
            $executeReceived = true;
        } elseif (($msg['type'] ?? '') === 'cancel') {
            // Handle cancel during startup
            $cancelMsg = json_encode(['type' => 'complete', 'status' => 'stopped']) . "\n";
            fwrite(STDOUT, $cancelMsg);
            fflush(STDOUT);
            exit(0);
        }
    }
}

if (!$executeReceived) {
    $errMsg = json_encode(['type' => 'error', 'message' => 'Timeout waiting for execute']) . "\n";
    fwrite(STDOUT, $errMsg);
    fflush(STDOUT);
    exit(1);
}

// ---- Send heartbeat every 500ms while working ----
$heartbeatIntervalUsec = 500_000; // 500ms
$lastHeartbeat = time();

// ---- Simulate agent work and stream results ----
// Fabricated output, on purpose. See createInlineWorkerScript()'s docblock in
// the parent for what is real around it and what a real worker would need;
// "Real LLM integration comes in later phases", which this comment used to
// say, named a phase that has since passed without it.

// Phase 1: Initial work burst
usleep(20000); // 20ms delay to simulate work

// Send streaming message
$streamingMsg = json_encode([
    'type' => 'streaming',
    'content' => "[{$agentConfig['name']}] Processing: {$task}",
]) . "\n";
fwrite(STDOUT, $streamingMsg);
fflush(STDOUT);

// Send heartbeat
$heartbeatMsg = json_encode(['type' => 'heartbeat']) . "\n";
fwrite(STDOUT, $heartbeatMsg);
fflush(STDOUT);
$lastHeartbeat = time();

// Continue working
usleep(20000);

// Send another streaming message with simulated response
$streamingMsg2 = json_encode([
    'type' => 'streaming',
    'content' => "[{$agentConfig['name']}] Completed task successfully.",
]) . "\n";
fwrite(STDOUT, $streamingMsg2);
fflush(STDOUT);

// Send heartbeat
$heartbeatMsg = json_encode(['type' => 'heartbeat']) . "\n";
fwrite(STDOUT, $heartbeatMsg);
fflush(STDOUT);

// Long-running task simulation: keep sending heartbeats until done
// Simulate a slightly longer-running task with periodic heartbeats
usleep($heartbeatIntervalUsec);
$heartbeatMsg = json_encode(['type' => 'heartbeat']) . "\n";
fwrite(STDOUT, $heartbeatMsg);
fflush(STDOUT);

usleep($heartbeatIntervalUsec);
$heartbeatMsg = json_encode(['type' => 'heartbeat']) . "\n";
fwrite(STDOUT, $heartbeatMsg);
fflush(STDOUT);

// Send complete message
$completeMsg = json_encode([
    'type' => 'complete',
    'status' => 'completed',
    'output' => "[{$agentConfig['name']}] Task finished: {$task}",
    'tokensUsed' => 0,
    'costUsd' => 0.0,
]) . "\n";
fwrite(STDOUT, $completeMsg);
fflush(STDOUT);

exit(0);
PHP;
    }

    /**
     * Build an AgentResult from a complete message.
     */
    private function buildResult(array $message, string $agentId, \DateTimeImmutable $startedAt): AgentResult
    {
        $statusMap = [
            'completed' => AgentStatus::Completed,
            'stopped' => AgentStatus::Stopped,
            'failed' => AgentStatus::Failed,
        ];

        $status = $statusMap[$message['status'] ?? ''] ?? AgentStatus::Completed;

        return new AgentResult(
            agentId: $agentId,
            status: $status,
            output: $message['output'] ?? null,
            tokensUsed: $message['tokensUsed'] ?? 0,
            costUsd: (float) ($message['costUsd'] ?? 0.0),
            startedAt: $startedAt,
            completedAt: new \DateTimeImmutable(),
        );
    }

    /**
     * Properly close a process and its pipes.
     */
    private function closeProcess(array $processDescriptor): void
    {
        foreach (['stdin', 'stdout', 'stderr'] as $pipe) {
            if (isset($processDescriptor[$pipe]) && is_resource($processDescriptor[$pipe])) {
                fclose($processDescriptor[$pipe]);
            }
        }

        if (isset($processDescriptor['process']) && is_resource($processDescriptor['process'])) {
            proc_close($processDescriptor['process']);
        }
    }

    /**
     * Send SIGTERM and escalate to SIGKILL if the process does not exit within
     * the configured grace period.
     *
     * This two-phase approach allows agents to flush checkpoint data before
     * being forcefully killed.
     */
    private function escalateAndKill($process, string $agentId): void
    {
        // Guard: process may already be dead
        if (!is_resource($process)) {
            return;
        }

        proc_terminate($process, SIGTERM);

        $deadline = time() + self::SIGTERM_GRACE_SECS;
        while (time() < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return; // Exited gracefully within grace period
            }
            usleep(100_000); // 100ms
        }

        // Still running — SIGKILL
        if (is_resource($process)) {
            proc_terminate($process, SIGKILL);
        }
    }

    /**
     * Returns true when system memory pressure exceeds the configured threshold,
     * indicating the pool should pause new task scheduling.
     *
     * Uses PHP's memory usage and an approximation of total available memory.
     * On Linux, reads /proc/meminfo for accurate total memory; falls back to
     * a conservative estimate on other platforms.
     */
    private function isMemoryPressure(): bool
    {
        $used = memory_get_usage(false);
        $total = $this->getTotalMemoryBytes();

        if ($total <= 0) {
            return false; // Cannot determine — allow scheduling
        }

        return ($used / $total) >= $this->memoryPressureThreshold;
    }

    /**
     * Throws if memory pressure or queue overflow would make scheduling unsafe.
     *
     * @throws \RuntimeException if backpressure conditions are met
     */
    private function checkBackpressure(): void
    {
        if ($this->isMemoryPressure()) {
            throw new \RuntimeException(
                'Memory pressure threshold exceeded — pausing task scheduling'
            );
        }
    }

    /**
     * Get the total physical memory in bytes.
     *
     * On Linux reads /proc/meminfo; returns 0 on unknown platforms.
     */
    private function getTotalMemoryBytes(): int
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        if (PHP_OS !== 'Linux' || !file_exists('/proc/meminfo')) {
            $cached = 0;
            return $cached;
        }

        $content = @file_get_contents('/proc/meminfo');
        if ($content === false) {
            $cached = 0;
            return $cached;
        }

        // "MemTotal:       16384084 kB"
        if (preg_match('/^MemTotal:\s+(\d+)\s+kB/m', $content, $matches)) {
            $cached = (int) $matches[1] * 1024;
        } else {
            $cached = 0;
        }

        return $cached;
    }

    /**
     * Get the exit code of a process that has already terminated.
     *
     * proc_get_status() reports 'running' => false after the process exits,
     * and the exit code is in the 'exitcode' field. Returns 0 if unavailable.
     */
    private function getExitCode($process): int
    {
        if (!is_resource($process)) {
            return -1;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            return $status['exitcode'] ?? -1;
        }

        return 0;
    }
}
