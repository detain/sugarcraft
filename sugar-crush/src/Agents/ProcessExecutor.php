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

    public function __construct(
        private readonly string $binaryPath = 'php',
        private readonly ?int $timeoutSeconds = 300,
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
        $process = $this->spawnWorker($agent, $request);

        $buffer = '';
        $startTime = new \DateTimeImmutable();

        // Use blocking mode for stdout reading so fgets() waits for data
        stream_set_blocking($process['stdout'], true);

        // Read until we get a complete or error message
        while (!feof($process['stdout'])) {
            $line = fgets($process['stdout']);
            if ($line === false) {
                break;
            }

            $buffer .= $line;
            $message = json_decode(trim($line), true);

            if ($message === null) {
                continue;
            }

            if (($message['type'] ?? '') === 'complete') {
                $this->closeProcess($process);
                return $this->buildResult($message, $agent->id, $startTime);
            }

            if (($message['type'] ?? '') === 'error') {
                $this->closeProcess($process);
                return new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Failed,
                    error: new \RuntimeException($message['message'] ?? 'Unknown error'),
                    startedAt: $startTime,
                    completedAt: new \DateTimeImmutable(),
                );
            }
        }

        $this->closeProcess($process);

        // If we exit the loop without complete/error, treat as failure
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
        $process = $this->spawnWorker($agent, $request);
        $startTime = new \DateTimeImmutable();

        // Use blocking mode for stdout reading so fgets() waits for data
        stream_set_blocking($process['stdout'], true);

        // Read streaming messages and yield them
        while (!feof($process['stdout'])) {
            $line = fgets($process['stdout']);
            if ($line === false) {
                break;
            }

            $message = json_decode(trim($line), true);
            if ($message === null) {
                continue;
            }

            $type = $message['type'] ?? '';

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
                yield $this->buildResult($message, $agent->id, $startTime);
                return;
            }

            if ($type === 'error') {
                $this->closeProcess($process);
                yield new AgentResult(
                    agentId: $agent->id,
                    status: AgentStatus::Failed,
                    error: new \RuntimeException($message['message'] ?? 'Unknown error'),
                    startedAt: $startTime,
                    completedAt: new \DateTimeImmutable(),
                );
                return;
            }

            // Handle other message types silently for now
            // (tool_call, progress, etc. will be handled in P1.S6)
        }

        $this->closeProcess($process);

        // Exit without complete - yield failure
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

        $process = proc_open(
            [$this->binaryPath, '-r', $workerScript],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );

        if (!is_resource($process)) {
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
     */
    private function createInlineWorkerScript(): string
    {
        // The worker reads startup config, sends ready, waits for execute,
        // then simulates agent work and sends streaming + complete messages.
        // For P1.S5, this is a simplified simulation that doesn't actually
        // call an LLM — that wiring comes in later phases.
        //
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

// ---- Simulate agent work and stream results ----
// For P1.S5: simple simulation that echoes the task after a brief delay.
// Real LLM integration comes in later phases.

usleep(50000); // 50ms delay to simulate work

// Send streaming message
$streamingMsg = json_encode([
    'type' => 'streaming',
    'content' => "[{$agentConfig['name']}] Processing: {$task}",
]) . "\n";
fwrite(STDOUT, $streamingMsg);
fflush(STDOUT);

// Send another streaming message with simulated response
usleep(50000);
$streamingMsg2 = json_encode([
    'type' => 'streaming',
    'content' => "[{$agentConfig['name']}] Completed task successfully.",
]) . "\n";
fwrite(STDOUT, $streamingMsg2);
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
}
