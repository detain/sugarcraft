<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Messages\Message;
use SugarCraft\Crush\Providers\CompleteRequest;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Support\ProcessContainment;
use SugarCraft\Crush\Support\ProcessReaper;
use SugarCraft\Crush\Tools\Tool;

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

    /**
     * agentId -> Unix time until which the worker has leased the heartbeat
     * window (E646). A `lease` frame extends the effective heartbeat deadline
     * past {@see HEARTBEAT_TIMEOUT_SECS} without capping the provider call:
     * the lease is the worker's signed promise that it is working on a
     * request it cannot speak during — see createLiveWorkerScript()'s
     * non-streaming branch. A dead worker still closes stdout and is reaped
     * on EOF immediately, lease or none; a wedged lease-holder is reaped when
     * the lease expires.
     *
     * @var array<string, int>
     */
    private array $heartbeatLeaseUntil = [];

    /** Heartbeat interval the worker sends messages at (seconds). */
    private const HEARTBEAT_INTERVAL_SECS = 5;

    /** How long the parent waits for a heartbeat before declaring worker dead (seconds). */
    private const HEARTBEAT_TIMEOUT_SECS = 15;

    /** Ceiling on an accepted lease: a worker-supplied `seconds` is attack surface, and an unclamped value defers heartbeat reaping indefinitely. */
    private const LEASE_MAX_SECS = 3600;

    /** Grace period between SIGTERM and SIGKILL (seconds). */
    private const SIGTERM_GRACE_SECS = 5;

    /** Default fraction of available memory above which we pause scheduling (0.0–1.0). */
    private const DEFAULT_MEMORY_THRESHOLD = 0.8;

    public function __construct(
        private readonly string $binaryPath = 'php',
        private readonly ?int $timeoutSeconds = 300,
        /** Memory usage fraction above which new task scheduling is paused (0.0–1.0). */
        private readonly float $memoryPressureThreshold = self::DEFAULT_MEMORY_THRESHOLD,
        /**
         * The provider the WORKER consults, as a {@see \SugarCraft\Crush\Providers\ProviderFactory}
         * config array — or the one extra spelling `['type' => 'echo']`, see
         * {@see createLiveWorkerScript()}.
         *
         * Null is not a fallback and never selects a canned answer: a worker
         * that receives no provider spec emits an `error` frame naming the
         * absence and exits non-zero ({@see createLiveWorkerScript()}). That is
         * the deliberate shape — the alternative, degrading to text when no
         * model is reachable, is exactly the defect this parameter exists to
         * close, and it would be strictly worse than the honest simulation it
         * replaced because nothing downstream could tell the two apart.
         *
         * A serialisable ARRAY rather than a constructed ProviderInterface
         * because the consumer lives in another process: `spawnWorker()` writes
         * this straight into the startup line, and a provider object does not
         * survive `json_encode()` any better than a Message does
         * ({@see encodeMessages()}).
         *
         * ## E649 — WHAT SAID: NOTHING IN `src/` SUPPLIES IT YET. E652 CLOSED
         * THE CHAT PATH; E663 CLOSED THE WORKFLOW-ENGINE SEAM.
         *
         * WHAT THIS PARAMETER CLAIMS to be is the provider the worker consults.
         * WHAT THIS SAID: no construction site in `src/` passed one, so on
         * every shipped path a sub-agent worker reached
         * {@see createLiveWorkerScript()}'s provider check, refused, and the
         * pool reported a FAILED agent. WHAT IS TRUE NOW (E652):
         * `AgentPoolConfig::$workerProvider` + `withWorkerProvider()` exist;
         * {@see \SugarCraft\Crush\Cli\Bootstrap::agentPoolConfig()} feeds the
         * session's serializable provider spec into Chat's fallback pool via
         * `new ProcessExecutor(timeoutSeconds: ..., workerProvider: ...)` and
         * `new AgentWorkerPool(..., workerProvider: ...)` — that construction
         * site IS `src/`, and the Chat sub-agent path is live. WHAT THIS SAID
         * (E652): {@see \SugarCraft\Crush\Cli\Bootstrap::workflowEngine()}
         * built its `WorkflowEngine` without a `pool:` argument, so the
         * engine's default `AgentWorkerPool` carried no spec and every
         * `/workflow run` stage still reached this refusal. WHAT IS TRUE NOW
         * (E663): the same method builds the pool through `agentPoolConfig()`
         * and hands it to the engine as `pool:`, so engine-dispatched
         * sub-agents consult the launch's provider — the seam is closed and
         * the operator-facing wording lives in `docs/WORKFLOWS.md` ("Running
         * one"). WHY THIS NOTE EARNS ITS PLACE: a null spec still means
         * refusal, and an honest failure beats an indistinguishable lie (see
         * the parameter's own null paragraph and the E59 notes on the
         * simulation); {@see
         * \SugarCraft\Crush\Workflows\WorkflowEngine::executeParallelStage()}
         * carries whatever spec its stage pool holds across rebuilds, so both
         * feeds are live code paths now, not mechanisms in waiting.
         */
        private readonly ?array $workerProvider = null,
        /**
         * Opt in to the FABRICATING worker script, {@see createInlineWorkerScript()}.
         *
         * TEST-ONLY, and the default is what enforces that: nothing in `src/`
         * passes it, which {@see \SugarCraft\Crush\Tests\Agents\ProcessExecutorTest}
         * pins with a scanner over the whole of `src/` (plus a known-positive
         * fixture, so an assertion of "no occurrences" cannot be satisfied by a
         * dead scanner). Production therefore reaches the live script or it
         * reaches an error frame; there is no third outcome and no path from
         * one to the other.
         */
        private readonly bool $simulatedWorker = false,
    ) {}

    /**
     * The worker-provider spec this executor hands to every child, or null
     * when none was configured (E649 — the shipped default today; see the
     * constructor's E649 paragraph for the seam that closes it).
     *
     * Introspection, not mutation: the pool's stage-pool rebuilds and any
     * future `withWorkerProvider()` caller need to READ which provider a
     * prebuilt executor carries — `AgentWorkerPool::workerProvider()` answers
     * for the POOL's parameter only, and an executor configured directly is
     * invisible to it.
     *
     * @return ?array<string, mixed>
     */
    public function workerProvider(): ?array
    {
        return $this->workerProvider;
    }

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

        // E650: the child died during spawn/handshake; the pipes are already
        // reaped and the condition arrives as a failed AgentResult, never as
        // a warning.
        if (isset($process['fatal'])) {
            return new AgentResult(
                agentId: $agent->id,
                status: AgentStatus::Failed,
                error: new \RuntimeException($process['fatal']),
                startedAt: new \DateTimeImmutable(),
                completedAt: new \DateTimeImmutable(),
            );
        }

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
            $heartbeatDeadline = $this->effectiveHeartbeatDeadline($agent->id);
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
                $this->stopTracking($agent->id);
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
                    $this->stopTracking($agent->id);
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
                    $this->stopTracking($agent->id);
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
                            $this->stopTracking($agent->id);
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

                    if (($message['type'] ?? '') === 'lease') {
                        // E646: the worker's promise that a provider call it
                        // cannot speak during is in flight. The heartbeat
                        // deadline moves out to the lease; the provider call
                        // itself is NOT capped by anything here.
                        $leaseSeconds = (int) ($message['seconds'] ?? 0);
                        if ($leaseSeconds > 0) {
                            // `seconds` arrives over an untrusted pipe: clamp it, a forged lease is a DoS on reaping.
                            $this->heartbeatLeaseUntil[$agent->id] = time() + min($leaseSeconds, self::LEASE_MAX_SECS);
                        }
                        continue;
                    }

                    if (($message['type'] ?? '') === 'complete') {
                        $this->closeProcess($process);
                        $this->stopTracking($agent->id);
                        return $this->buildResult($message, $agent->id, $startTime);
                    }

                    if (($message['type'] ?? '') === 'error') {
                        $this->closeProcess($process);
                        $this->stopTracking($agent->id);
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
        $this->stopTracking($agent->id);

        // E688: null is UNKNOWN (a child the bounded reap could not land),
        // never a 0 dressed as a crash — only a measured non-zero attributes
        // a code to the worker.
        if ($exitCode !== null && $exitCode !== 0) {
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

        // E650: same dead-child contract as execute() — a failed AgentResult
        // through the generator, never a warning at the pipe.
        if (isset($process['fatal'])) {
            yield new AgentResult(
                agentId: $agent->id,
                status: AgentStatus::Failed,
                error: new \RuntimeException($process['fatal']),
                startedAt: new \DateTimeImmutable(),
                completedAt: new \DateTimeImmutable(),
            );

            return;
        }

        $startTime = new \DateTimeImmutable();
        $this->lastHeartbeat[$agent->id] = time();

        // Use non-blocking reads for timeout and heartbeat enforcement
        stream_set_blocking($process['stdout'], false);

        $timeoutDeadline = $this->timeoutSeconds !== null
            ? time() + $this->timeoutSeconds
            : null;

        while (!feof($process['stdout'])) {
            $heartbeatDeadline = $this->effectiveHeartbeatDeadline($agent->id);
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
                    $this->stopTracking($agent->id);
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
                    $this->stopTracking($agent->id);
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
                $this->stopTracking($agent->id);
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
                    $this->stopTracking($agent->id);
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

            if ($type === 'lease') {
                // E646: same lease contract as execute()'s read loop.
                $leaseSeconds = (int) ($message['seconds'] ?? 0);
                if ($leaseSeconds > 0) {
                    // `seconds` arrives over an untrusted pipe: clamp it, a forged lease is a DoS on reaping.
                    $this->heartbeatLeaseUntil[$agent->id] = time() + min($leaseSeconds, self::LEASE_MAX_SECS);
                }
                continue;
            }

            if ($type === 'complete') {
                $this->closeProcess($process);
                $this->stopTracking($agent->id);
                yield $this->buildResult($message, $agent->id, $startTime);
                return;
            }

            if ($type === 'error') {
                $this->closeProcess($process);
                $this->stopTracking($agent->id);
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
        $this->stopTracking($agent->id);

        // E688: see execute()'s twin — null stays unknown, never 0.
        if ($exitCode !== null && $exitCode !== 0) {
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

        // Only terminate if process is still valid (not already closed).
        // E673: ProcessContainment::terminate() group-kills the setsid-wrapped
        // worker, so anything the worker itself spawned dies with it instead
        // of orphaning onto the session leader.
        if (is_resource($process['process'])) {
            ProcessContainment::terminate($process['process']);
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
            ProcessContainment::terminate($process['process']);
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
        $workerScript = $this->simulatedWorker
            ? $this->createInlineWorkerScript()
            : $this->createLiveWorkerScript();

        // BEFORE proc_open(), because encodeMessages() THROWS on a message it
        // cannot serialise. Built after the spawn — which is where this used to
        // be — that throw escaped with a live `php -r` child and three open
        // pipes already in hand and nothing recorded in $this->processes, so
        // neither cancel() nor cancelAll() could ever reap it: the refusal that
        // exists to stop a silently-wrong request leaked a process every time
        // it fired. The proc_open()-failed branch below fcloses every pipe it
        // opened, so this is the file's own convention, not a new one.
        $startupMessage = json_encode([
            'type' => 'startup',
            'autoload' => self::autoloadPath(),
            'provider' => $this->workerProvider,
            'agent' => [
                'id' => $agent->id,
                'name' => $agent->agent->name,
                'model' => $agent->agent->model,
                'prompt' => $agent->agent->systemPrompt(),
            ],
            'task' => $agent->task,
            'request' => [
                'model' => $request->model,
                'messages' => self::encodeMessages($request->messages),
                'tools' => self::encodeTools($request->tools),
                'toolSpecs' => self::encodeToolSpecs($request->tools),
                'systemPrompt' => $request->systemPrompt,
                'temperature' => $request->temperature,
                'maxTokens' => $request->maxTokens,
            ],
        ]) . "\n";

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['pipe', 'w'],  // stdout
            2 => ['pipe', 'w'],  // stderr
        ];

        // E672: once the containment wrapper fronts the spawn, a bogus
        // binary NO LONGER fails inside posix_spawn() — `setsid` itself
        // starts, and the exec failure surfaces only as the child's exit
        // status. The pre-check keeps the "failed to spawn" contract on the
        // call that guarded it before routing — needed only where a wrapper
        // actually fronts it, so the unwrapped fallback behaves byte-identically.
        if (ProcessContainment::detachedSpawnBinary() !== ''
            && !(str_contains($this->binaryPath, '/')
                ? is_executable($this->binaryPath)
                : ProcessContainment::locateOnPath($this->binaryPath) !== '')
        ) {
            throw new \RuntimeException('Failed to spawn worker process');
        }

        // E672/E674: the worker rides the ONE choke point — `setsid -w`
        // detach (which also makes the worker GROUP-ownable, so cancel and
        // the escalation below reach a worker that spawned children of its
        // own) and the fail-fast env block over the inherited one. The
        // worker needs no site keys, so there are no overrides.
        $process = @proc_open(
            ProcessContainment::spawnSpec([$this->binaryPath, '-r', $workerScript]),
            $descriptors,
            $pipes,
            null,
            ProcessContainment::env(),
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

        $processDescriptor = [
            'process' => $process,
            'stdin' => $pipes[0],
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
        ];

        // Send the startup message built above, before the spawn.
        //
        // E650: every write into the child below is liveness-guarded, and a
        // failed write is a DEAD CHILD, not a transport error to raise. A bare
        // `fwrite()` into a pipe whose read end has closed emits an E_WARNING
        // ("Broken pipe") that PHPUnit's failOnWarning converts into a red
        // suite blaming the transport, while the actual condition — the worker
        // died — arrives nowhere at all. Detection comes BEFORE the write
        // where cheap (proc_get_status during the handshake wait) and AT the
        // write where not (a short write means the reader is gone); either
        // way the outcome is the same honest shape: the pipes are reaped and
        // spawnWorker returns a `fatal` descriptor that {@see execute()} and
        // {@see executeStream()} translate into a failed AgentResult naming
        // the death. No warning surfaces; the condition is reported.
        if (!self::writeFrame($pipes[0], $startupMessage)) {
            return self::deadWorker($processDescriptor, 'before its startup frame could be written');
        }

        // Wait for ready message
        $ready = false;
        $deadline = time() + 5;
        while (!$ready && time() < $deadline) {
            if (feof($pipes[1])) {
                break;
            }
            if (!self::childIsAlive($process)) {
                // Died between the startup write and the handshake: stop
                // waiting on a corpse. The execute write below then fails its
                // own guard and reports the death at the right granularity.
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

        if (!$ready) {
            // Either the 5s handshake expired or the child died mid-wait. A
            // dead child must not be written `execute` blindly: that is the
            // exact fwrite-into-a-closed-pipe the doc above refuses to let
            // happen silently. The liveness probe distinguishes the two — a
            // live-but-silent child still gets its instruction frame (and its
            // own 'Timeout waiting for execute' error frame back), a dead one
            // is reaped here.
            if (!self::childIsAlive($process)) {
                return self::deadWorker($processDescriptor, 'during the ready handshake, before it ever reported ready');
            }
        }

        // Send execute message
        $executeMessage = json_encode(['type' => 'execute']) . "\n";
        if (!self::writeFrame($pipes[0], $executeMessage)) {
            return self::deadWorker($processDescriptor, 'before its execute frame could be written');
        }

        $this->processes[$agent->id] = $processDescriptor;

        return $processDescriptor;
    }

    /**
     * The moment the parent will declare this agent's worker dead: the plain
     * heartbeat window, moved out by any lease the worker has signed (E646).
     */
    private function effectiveHeartbeatDeadline(string $agentId): int
    {
        return max(
            ($this->lastHeartbeat[$agentId] ?? time()) + self::HEARTBEAT_TIMEOUT_SECS,
            $this->heartbeatLeaseUntil[$agentId] ?? 0,
        );
    }

    /**
     * Drop every per-agent liveness record: heartbeat clock, process
     * descriptor, lease. The three are armed together at spawn and must be
     * cleared together at every terminal — a stale lease keyed to an id a
     * later agent reuses would silently extend that one's grace.
     */
    private function stopTracking(string $agentId): void
    {
        unset($this->lastHeartbeat[$agentId], $this->processes[$agentId], $this->heartbeatLeaseUntil[$agentId]);
    }

    /**
     * Write exactly one frame into the child's stdin, reporting delivery.
     *
     * `@fwrite` suppresses the broken-pipe warning ONLY as the last resort of
     * a race the liveness probes cannot close — a child killed in the
     * microseconds between `proc_get_status()` and the write. The short-write
     * comparison is the actual detection; suppression just keeps the honest
     * failure an AgentResult can carry from degrading into an E_WARNING that
     * reddens the suite for the wrong reason (E650).
     *
     * @param resource $stdin
     */
    private static function writeFrame($stdin, string $frame): bool
    {
        $written = @fwrite($stdin, $frame);

        return $written === strlen($frame) && @fflush($stdin);
    }

    /**
     * Whether the worker process is still running, defensively.
     *
     * proc_get_status() reports `running => false` for both exited and
     * never-waitable states, which is exactly the answer the E650 guards want;
     * a non-resource handle (already closed) is not alive either.
     *
     * @param resource|false $process
     */
    private static function childIsAlive($process): bool
    {
        return is_resource($process) && (bool) (proc_get_status($process)['running'] ?? false);
    }

    /**
     * The spawn-failed-halway descriptor: reap everything, mark it fatal.
     *
     * `spawnWorker()` cannot return an AgentResult itself — it serves both
     * {@see execute()} and {@see executeStream()} — so a dead child comes back
     * as a descriptor carrying a `fatal` message and no live resources: the
     * pipes are closed and the process reaped HERE, so the leak this prevents
     * (a registered-but-dead entry cancel()/cancelAll() could never clean)
     * cannot re-form at either caller. The descriptor is deliberately absent
     * from {@see $processes}: there is nothing left to cancel.
     *
     * @param array{process: resource, stdin: resource, stdout: resource, stderr: resource} $processDescriptor
     * @return array{fatal: string}
     */
    private static function deadWorker(array $processDescriptor, string $stage): array
    {
        self::closeProcessStatic($processDescriptor);

        return [
            'fatal' => sprintf(
                'Worker process died %s: the child exited before it could be instructed to run, '
                . 'so the agent cannot report anything further.',
                $stage,
            ),
        ];
    }

    /**
     * closeProcess() as a static — the method body touches no instance state,
     * and the dead-child path runs inside static helpers.
     *
     * @param array<string, mixed> $processDescriptor
     */
    private static function closeProcessStatic(array $processDescriptor): void
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
     * Absolute path to the APPLICATION's Composer autoloader, for the child.
     *
     * Computed in the PARENT and shipped over the wire rather than derived in
     * the child, because the child is a `php -r` process: it has no `__DIR__`
     * pointing anywhere useful, no autoloader, and no way to find one. This is
     * the single fact that makes a real provider constructible in there at all.
     *
     * ## WHAT THIS USED TO SAY, AND WHY IT WAS WRONG
     *
     * "TWO climbs, not three: `src/Agents` -> `src` -> package root, the same
     * count and the same reasoning as
     * {@see \SugarCraft\Crush\Providers\ProviderFactory::packageRoot()}."
     * The count was right for what that sentence described and the REASONING
     * was inverted, which is why the arithmetic went unquestioned:
     * `packageRoot()` locates THIS PACKAGE's own root, where two climbs is
     * correct in every layout. This method needs the APPLICATION's autoloader,
     * which in an installed layout is not under the package root at all.
     *
     * MEASURED on PHP 8.3.6 against a synthetic install
     * (`app/vendor/sugarcraft/sugar-crush/src/Agents`), the two-climb form
     * yields `app/vendor/sugarcraft/sugar-crush/vendor/autoload.php`, which
     * `is_file()` says does not exist — so in any Composer consumer of this
     * package EVERY sub-agent hit the child's "Worker autoloader is not
     * readable" refusal. The monorepo checkout is the one layout where the
     * old form happened to work, and it is the only layout this suite runs in.
     *
     * ## WHAT IS TRUE NOW
     *
     * {@see \SugarCraft\Crush\Sessions\BackgroundSupervisor::autoloadPath()}
     * already solved exactly this for the session daemon — it is `public
     * static` for reuse, it asks the LIVE `\Composer\Autoload\ClassLoader`
     * for the autoloader this process is actually running under, and only
     * falls back to path arithmetic (both the root-package and the
     * installed-under-vendor spellings) when no ClassLoader is registered.
     * Delegating is the whole fix; a second copy of the arithmetic here is how
     * the two would drift.
     *
     * Null is a legitimate answer — no autoloader was found — and travels as
     * `null` on the wire, where the child's own `is_file()` gate turns it into
     * a named `error` frame rather than a `require` of nothing.
     */
    private static function autoloadPath(): ?string
    {
        return BackgroundSupervisor::autoloadPath();
    }

    /**
     * Flatten a CompleteRequest's messages into JSON-encodable arrays.
     *
     * ## THIS IS A BUG FIX, NOT A TIDY-UP
     *
     * MEASURED on PHP 8.3.6: `json_encode(new UserMessage('hello world'))` is
     * `{}`. Every `Message` in this package keeps its state in PRIVATE
     * properties and none of them implements `JsonSerializable`, so the
     * startup line used to carry `"messages":[{},{}]` — the sub-agent's entire
     * conversation destroyed crossing the pipe, silently, with no warning and
     * no error. `Message::toArray()` is the serialiser those classes ship for
     * exactly this, and this is the seam that was not using it.
     *
     * It was invisible for as long as the worker fabricated its answer: a
     * script that never reads `request.messages` cannot notice that they are
     * empty. It becomes load-bearing the moment {@see createLiveWorkerScript()}
     * hands them to a provider, which is why it is fixed in the same change.
     *
     * ## AND IT REFUSES WHAT IT CANNOT ENCODE
     *
     * A third shape throws rather than being dropped. Silently skipping is how
     * the defect above survived: an under-full `messages` array looks exactly
     * like a short conversation, so the failure would once again be a request
     * that is quietly wrong rather than a call that stops.
     *
     * @param array<mixed> $messages
     * @return list<array<string, mixed>>
     * @throws \InvalidArgumentException when an entry is neither a Message nor an array
     */
    private static function encodeMessages(array $messages): array
    {
        $encoded = [];

        foreach ($messages as $message) {
            if ($message instanceof Message) {
                $encoded[] = $message->toArray();
                continue;
            }

            // Both shapes are live in this tree: Chat builds Message objects,
            // while ProcessExecutorTest and dispatchSkill() build plain
            // ['role' => ..., 'content' => ...] arrays. Neither is wrong; the
            // wire format is the array one.
            if (\is_array($message)) {
                $encoded[] = $message;
                continue;
            }

            throw new \InvalidArgumentException(
                'Cannot send message of type ' . get_debug_type($message)
                . ' to a worker: expected ' . Message::class . ' or array.'
            );
        }

        return $encoded;
    }

    /**
     * Flatten a CompleteRequest's tools to their NAMES for the wire.
     *
     * Same measured defect as {@see encodeMessages()} — a `Tool` is an
     * interface over objects with private state, so `json_encode()` of one is
     * `{}` — but a DIFFERENT resolution, because the fix that works for
     * messages does not work here.
     *
     * A `Message` is data and round-trips. A {@see Tool} is data plus an
     * `execute()` implementation, and that half does not cross a process
     * boundary: rehydrating one in the child needs the registry that built it,
     * which needs the session it belongs to. So the live worker deliberately
     * sends `tools: null` into its CompleteRequest and a sub-agent worker
     * currently runs WITHOUT TOOLS.
     *
     * ## WHAT THIS USED TO SAY, AND WHY IT WAS FALSE
     *
     * "Names are sent anyway ... so the gap is legible to anyone reading a
     * TRANSCRIPT." There is no transcript. MEASURED at the tree this sentence
     * was written in: `request.tools` is read by NEITHER worker script (the
     * live one's only `tools` occurrence is the literal `tools: null` it passes
     * to its own CompleteRequest; the simulation has none) and the startup line
     * is not logged anywhere — `$startupMessage` occurs at its `json_encode`,
     * at its `fwrite`, and in prose. A justification that names a reader which
     * does not exist buys the method its place with a fiction, and the price
     * showed: mutating this whole function to `return null` SURVIVED the entire
     * suite, 10293 tests green.
     *
     * ## WHAT IS TRUE NOW, AND WHY THE NAMES STILL GO OUT
     *
     * The names are on the WIRE, and the wire is the thing a future reader
     * actually has: the startup frame is the sub-agent protocol's only record
     * of what the parent believed it was granting, and it is what the first
     * consumer — a worker that can rehydrate tools, or a parent-side dump of
     * the frame — will read. That is a claim about the FRAME, which this
     * package controls, not about a log nobody writes.
     *
     * Sending `null` instead would be lossy in a way nothing could recover:
     * a request that granted twelve tools and one that granted none would be
     * byte-identical on the wire. So the names are pinned by a test that
     * decodes the startup line and asserts they are in it
     * ({@see \SugarCraft\Crush\Tests\Agents\ProcessExecutorTest}), which is
     * what makes the paragraph above falsifiable rather than decorative.
     *
     * The gap this paragraph deferred — a sub-agent worker runs WITHOUT
     * TOOLS — has since been closed from the provider's side (E647): the
     * same startup frame now also carries `toolSpecs` (name+description+
     * inputSchema, {@see encodeToolSpecs()}), and the live worker rehydrates
     * those into data-only {@see Tool} grants for its provider request
     * ({@see rehydrateTools()}). WHAT IS TRUE NOW of this roster: it remains
     * the frame's human-readable record — every grant, including the shapes
     * `toolSpecs` cannot carry (the `<unencodable X>` entries), is named
     * here — while `toolSpecs` is the machine-usable sibling. The half that
     * still does not cross is `execute()`, deliberately: tool execution is a
     * parent-side act and the rehydrated object throws if anything in the
     * child asks it for one.
     *
     * @param ?array<mixed> $tools
     * @return ?list<string>
     */
    private static function encodeTools(?array $tools): ?array
    {
        if ($tools === null) {
            return null;
        }

        $names = [];

        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $names[] = $tool->name();
                continue;
            }

            if (\is_array($tool) && \is_string($tool['name'] ?? null)) {
                $names[] = $tool['name'];
                continue;
            }

            if (\is_string($tool)) {
                $names[] = $tool;
                continue;
            }

            // Rule: a serialiser that cannot express something says so rather
            // than dropping it, for the same reason encodeMessages() throws.
            $names[] = '<unencodable ' . get_debug_type($tool) . '>';
        }

        return $names;
    }

    /**
     * Serialize a tool grant into the shape a child can rehydrate (E647).
     *
     * THE DECISION THIS RECORDS, taken when the brief offered two wire
     * formats: (i) serialize name+schema and rehydrate child-side, versus
     * (ii) RPC to the parent for resolution. (i) won because the half of a
     * {@see Tool} a provider request consumes — name, description, input
     * schema — is static at spawn time and the startup frame already exists,
     * while an RPC would need a second duplex channel and parent-side server
     * plumbing inside a stream_select loop that cannot block, trading the
     * frame's spawn-time determinism for a runtime dependency on the parent
     * answering mid-call. The half that CANNOT cross — `execute()` — stays
     * parent-side in either design; the rehydrated object refuses to run it
     * loudly ({@see rehydrateTools()}).
     *
     * The `tools` name roster ({@see encodeTools()}) stays exactly as the
     * frame test pins it: this key is ADDITIVE, and it answers the roster's
     * open question ("which tools did the parent believe it was granting?")
     * with the machine-usable half. An entry too strange to describe — the
     * `<unencodable X>` cases — gets no spec but keeps its roster slot, so
     * the frame still names what the parent saw; the child fails loud on
     * whatever spec it cannot parse, never on a name it was only told about.
     *
     * Absent-or-empty grants travel as null, never as `[]` — the same
     * normalization {@see \SugarCraft\Crush\Workflows\WorkflowEngine::resolveRequestTools()}
     * pins parent-side: every provider gates its tool block on `!== null`,
     * so `[]` is a different request than none, and the wire keeps that fact
     * out of existence.
     *
     * @param ?array<mixed> $tools
     * @return ?list<array{name: ?string, description: ?string, inputSchema: ?array}>
     */
    private static function encodeToolSpecs(?array $tools): ?array
    {
        if ($tools === null || $tools === []) {
            return null;
        }

        $specs = [];

        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $specs[] = [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'inputSchema' => $tool->inputSchema(),
                ];
                continue;
            }

            if (is_array($tool) && is_string($tool['name'] ?? null)) {
                $specs[] = [
                    'name' => $tool['name'],
                    'description' => is_string($tool['description'] ?? null) ? $tool['description'] : null,
                    'inputSchema' => is_array($tool['inputSchema'] ?? $tool['input_schema'] ?? null)
                        ? ($tool['inputSchema'] ?? $tool['input_schema'])
                        : null,
                ];
                continue;
            }

            if (is_string($tool)) {
                // A bare name carries no schema to ship; the child's
                // completeness rule (rehydrateTools) is what decides whether
                // such a grant can be honored.
                $specs[] = ['name' => $tool, 'description' => null, 'inputSchema' => null];
                continue;
            }

            // Unencodable entries are the roster's to name and not the
            // schema's to invent; skipped here by design, see the doc above.
        }

        return $specs === [] ? null : $specs;
    }

    /**
     * Rebuild the child-side view of a parent's tool grant (E647).
     *
     * PUBLIC STATIC because its only caller is the forked worker: the live
     * script (`createLiveWorkerScript()`) requires the application autoloader
     * before it calls this, so the child constructs real typed grants with
     * zero extra protocol. Each result is a DATA-ONLY {@see Tool}: name,
     * description and inputSchema answer the provider's advertise path
     * (`formatTools()`, `->name()`); `execute()` throws, because a tool's
     * implementation — registry, session, permissions, path jail — never
     * crossed the pipe and never will. Tool execution stays parent-side.
     *
     * FAILS LOUD ON EVERYTHING HALF-PARSED. A spec missing its description
     * or schema throws rather than advertising a schema-less tool: the model
     * would see a callable with no defined arguments, which is the same
     * "roster smaller or stranger than the parent believed" defect E641
     * closed, wearing the wire's clothes.
     *
     * @param ?list<array<string, mixed>> $specs the startup frame's `toolSpecs`;
     *        null and `[]` both mean "no grant travelled" (the parent's own
     *        normalization guarantees `[]` never crosses).
     * @return ?list<Tool> null when nothing was granted; otherwise one data Tool
     *         per spec, in wire order (the parent's registry order).
     * @throws \InvalidArgumentException when the list holds a non-array entry,
     *         or an entry whose name, description or inputSchema is missing
     *         or of the wrong type.
     */
    public static function rehydrateTools(?array $specs): ?array
    {
        if ($specs === null || $specs === []) {
            return null;
        }

        $tools = [];

        foreach ($specs as $index => $spec) {
            if (!is_array($spec)) {
                throw new \InvalidArgumentException(sprintf(
                    'toolSpecs entry #%d is %s, expected a tool spec array.',
                    $index,
                    get_debug_type($spec),
                ));
            }

            $name = $spec['name'] ?? null;
            $description = $spec['description'] ?? null;
            $inputSchema = $spec['inputSchema'] ?? null;

            if (!is_string($name) || $name === '') {
                throw new \InvalidArgumentException(sprintf(
                    'toolSpecs entry #%d carries no non-empty name; a tool grant must be complete.',
                    $index,
                ));
            }

            if (!is_string($description)) {
                throw new \InvalidArgumentException(sprintf(
                    'Tool grant "%s" crossed the wire without its description; refusing to advertise a tool the parent only half-described.',
                    $name,
                ));
            }

            if (!is_array($inputSchema)) {
                throw new \InvalidArgumentException(sprintf(
                    'Tool grant "%s" crossed the wire without its input schema; refusing to advertise a tool with no defined arguments.',
                    $name,
                ));
            }

            $tools[] = new class ($name, $description, $inputSchema) implements Tool {
                /** @param array<mixed> $schema */
                public function __construct(
                    private readonly string $wireName,
                    private readonly string $wireDescription,
                    private readonly array $wireSchema,
                ) {}

                public function name(): string
                {
                    return $this->wireName;
                }

                public function description(): string
                {
                    return $this->wireDescription;
                }

                /** @return array<mixed> */
                public function inputSchema(): array
                {
                    return $this->wireSchema;
                }

                public function execute(array $args): \SugarCraft\Crush\Tools\ToolResult
                {
                    throw new \LogicException(sprintf(
                        'The worker rehydrated tool "%s" for the provider request only; '
                        . 'tool execution is a parent-side act and was never sent across the wire.',
                        $this->wireName,
                    ));
                }
            };
        }

        return $tools;
    }

    /**
     * The LIVE worker script: consults a real provider and streams its answer.
     *
     * This is the default script — {@see __construct()}'s `$simulatedWorker`
     * defaults to false — so every production construction site
     * ({@see \SugarCraft\Crush\Agents\AgentWorkerPool::createDefaultExecutor()},
     * {@see \SugarCraft\Crush\Chat::executeAgents()}) reaches this one.
     *
     * ## IT HAS NO CANNED OUTPUT AND NO FALLBACK
     *
     * Read the script below for the property that matters: there is no string
     * in it that could become a sub-agent's answer. Every prerequisite it
     * cannot satisfy — no startup line, no readable autoloader, no provider
     * spec, a provider that will not construct, a provider that throws or
     * reports an error — ends in an `error` frame and `exit(1)`. None of them
     * degrades to text.
     *
     * That asymmetry is deliberate and is the whole point of the change. A
     * worker that fabricated on error would be strictly worse than the honest
     * simulation it replaced: the simulation at least announced itself in a
     * comment, whereas a fallback produces a plausible answer that no caller,
     * test or transcript can distinguish from a real one.
     *
     * ## WHAT THE SHIPPED PATHS ACTUALLY DO TODAY (E652/E663 REWRITE)
     *
     * WHAT THIS SAID: nothing in `src/` passes `workerProvider`, so on the
     * shipped paths this script reaches its provider check, refuses, and the
     * pool reports a FAILED agent naming the absence. WHAT IS TRUE NOW: on
     * the CHAT sub-agent path `Bootstrap::agentPoolConfig()` supplies the
     * session's provider spec through `Chat::executeAgents()`'s fallback pool
     * ({@see \SugarCraft\Crush\Agents\AgentPoolConfig::$workerProvider}), so a
     * configured launch's forked worker constructs its provider child-side and
     * answers. WHAT THIS SAID (E652): on the `/workflow run` path the check
     * still fired — `Bootstrap::workflowEngine()` passed no `pool:`. WHAT IS
     * TRUE NOW (E663): the same method builds the pool through
     * `agentPoolConfig()` and passes it as `pool:`, closing the seam named in
     * {@see \SugarCraft\Crush\Cli\Bootstrap::workflowEngine()} and
     * `docs/WORKFLOWS.md`; absence still refuses FAILED on both paths. WHY
     * THE SENTENCE EARNS ITS PLACE: the second half
     * is why the refusal was minted — an honest failure beats an
     * indistinguishable lie — and the claim "this sub-agent's prompt reached a
     * model" stays falsifiable on both paths: before, neither could be tested
     * at all, because a green suite over a fabricating worker measures the
     * fabrication.
     *
     * ## `['type' => 'echo']`
     *
     * One provider spelling that {@see \SugarCraft\Crush\Providers\ProviderFactory}
     * does not accept is accepted here, and it is not a special case for
     * tests: {@see \SugarCraft\Crush\Providers\EchoProvider} is a real
     * `ProviderInterface` that this application already ships as its offline
     * default ({@see \SugarCraft\Crush\Cli\Bootstrap::backend()}), and its
     * `name()` is `'echo'`. The factory has no such type because the factory
     * builds CONFIGURED providers and this one has nothing to configure.
     *
     * It is what makes the seam testable without a network, and — measured,
     * not asserted — it is a genuine round trip: the bytes that come back are
     * `EchoProvider`'s Markdown blockquote of the request's own last user turn,
     * assembled inside the provider, in whitespace-delimited stream pieces this
     * script never chose. Change the task and the output changes with it. That
     * is the difference from the simulation, which interpolated the task into a
     * sentence of its own.
     *
     * ## WHY THE SIMULATION SURVIVES ANYWAY
     *
     * {@see createInlineWorkerScript()} is kept, not deleted. It is the only
     * exercise the fork/pipe/pump/compositor chain has with a fixed, known
     * timing shape, and out-of-lane suites assert on that shape. It is now
     * reachable only by asking for it in the constructor.
     */
    private function createLiveWorkerScript(): string
    {
        // NOTE: `php -r` executes the code without an opening tag, so there is
        // none here — same as createInlineWorkerScript().
        return <<<'PHP'
declare(strict_types=1);

// Live worker: reads config from stdin, constructs a REAL provider, and
// streams that provider's answer back over the JSON line protocol.
//
// There is no canned output anywhere below. Every path that cannot reach a
// provider emits an `error` frame and exits non-zero.

$emit = static function (array $frame): void {
    fwrite(STDOUT, json_encode($frame) . "\n");
    fflush(STDOUT);
};

$fail = static function (string $message) use ($emit): void {
    $emit(['type' => 'error', 'message' => $message]);
    exit(1);
};

$agentConfig = null;
$task = '';
$requestSpec = [];
$providerSpec = null;
$autoload = null;

while (!feof(STDIN)) {
    $line = fgets(STDIN);
    if ($line === false) {
        break;
    }
    $msg = json_decode(trim($line), true);
    if (is_array($msg) && ($msg['type'] ?? '') === 'startup') {
        $agentConfig = is_array($msg['agent'] ?? null) ? $msg['agent'] : [];
        $task = (string) ($msg['task'] ?? '');
        $requestSpec = is_array($msg['request'] ?? null) ? $msg['request'] : [];
        $providerSpec = $msg['provider'] ?? null;
        $autoload = $msg['autoload'] ?? null;
        break;
    }
}

// The ready/execute handshake runs BEFORE the prerequisite checks on purpose.
// spawnWorker() writes `execute` into stdin unconditionally once its ready
// wait ends, so a child that exited during startup would leave the parent
// writing into a closed pipe. Answering ready first puts every refusal below
// onto the normal read path, where the parent is already listening for frames.
$emit(['type' => 'ready']);

$executeReceived = false;
$deadline = time() + 5;
while (!$executeReceived && time() < $deadline) {
    if (feof(STDIN)) {
        break;
    }
    $line = fgets(STDIN);
    if ($line === false) {
        continue;
    }
    $msg = json_decode(trim($line), true);
    if (($msg['type'] ?? '') === 'execute') {
        $executeReceived = true;
    } elseif (($msg['type'] ?? '') === 'cancel') {
        $emit(['type' => 'complete', 'status' => 'stopped']);
        exit(0);
    }
}

// ORDER MATTERS, and getting it wrong made a real refusal unreachable.
//
// The first version tested $executeReceived first and reported 'Timeout
// waiting for execute' — but the startup loop above consumes lines until it
// finds a `startup` frame, so a parent that sent a MALFORMED startup line has
// already had its `execute` eaten by that loop. Both conditions are then true
// at once and the timeout branch won, naming the wrong cause: the operator was
// told the parent never asked for execution when in fact the parent's config
// frame was unreadable. Measured by driving this script directly (see
// ProcessExecutorTest::testTheWorkerNamesAMissingStartupFrameRatherThanTheExecuteTimeout).
//
// The startup check therefore comes FIRST. It stays AFTER the ready/execute
// handshake — see the comment above it — because spawnWorker() writes
// `execute` into stdin unconditionally once its ready wait ends, and a child
// that exited before that write would leave the parent writing into a closed
// pipe.
if ($agentConfig === null) {
    $fail(
        'Worker received no startup message, so it has no agent to run. The '
        . 'parent either sent nothing or sent a line this worker could not '
        . 'decode as a startup frame.'
    );
}

if (!$executeReceived) {
    $fail('Timeout waiting for execute');
}

if (!is_string($autoload) || $autoload === '' || !is_file($autoload)) {
    $fail(
        'Worker autoloader is not readable: the parent named '
        . var_export($autoload, true)
        . '. A php -r child has no autoloader of its own, so no provider class '
        . 'is loadable here and this worker will not invent an answer.'
    );
}

require $autoload;

if (!is_array($providerSpec) || $providerSpec === []) {
    $fail(
        'No provider configured for this worker: ProcessExecutor was '
        . 'constructed without a workerProvider spec, so there is no model to '
        . 'consult. Refusing to fabricate a result.'
    );
}

try {
    $provider = ($providerSpec['type'] ?? null) === 'echo'
        ? new SugarCraft\Crush\Providers\EchoProvider()
        : (new SugarCraft\Crush\Providers\ProviderFactory())->create($providerSpec);
} catch (Throwable $e) {
    $fail('Provider construction failed: ' . get_class($e) . ': ' . $e->getMessage());
}

// Rebuild each turn with EVERY field Message::toArray() put on the wire, not
// just role+content. The first version of this loop dropped tool_calls,
// reasoning, is_error and attachments on the floor: an errored tool result
// arrived at the model as a successful one, which is the same class of silent
// conversion loss encodeMessages() was written to stop one seam further out.
$messages = [];
foreach ((is_array($requestSpec['messages'] ?? null) ? $requestSpec['messages'] : []) as $entry) {
    if (!is_array($entry)) {
        continue;
    }
    $content = (string) ($entry['content'] ?? '');
    $messages[] = match ((string) ($entry['role'] ?? 'user')) {
        'assistant' => new SugarCraft\Crush\Messages\AssistantMessage(
            $content,
            is_array($entry['tool_calls'] ?? null) ? $entry['tool_calls'] : null,
            isset($entry['reasoning']) ? (string) $entry['reasoning'] : null,
        ),
        'system' => new SugarCraft\Crush\Messages\SystemMessage($content),
        'tool' => new SugarCraft\Crush\Messages\ToolResultMessage(
            (string) ($entry['tool_call_id'] ?? $entry['toolCallId'] ?? ''),
            $content,
            (bool) ($entry['is_error'] ?? false),
        ),
        default => new SugarCraft\Crush\Messages\UserMessage(
            $content,
            array_values(array_filter(array_map(
                static function ($a) {
                    if (!is_array($a) || !is_string($a['path'] ?? null)) {
                        return null;
                    }
                    $type = (string) ($a['type'] ?? 'File');
                    // Attachment carries an enum; toArray() sent its case NAME.
                    // An unknown name becomes File rather than a fatal: a worker
                    // must not die over an attachment label it does not know.
                    return new SugarCraft\Crush\Attachment(
                        $a['path'],
                        $type === 'Image'
                            ? SugarCraft\Crush\AttachmentType::Image
                            : SugarCraft\Crush\AttachmentType::File,
                    );
                },
                is_array($entry['attachments'] ?? null) ? $entry['attachments'] : [],
            ))),
        ),
    };
}

// A sub-agent is dispatched with a TASK; a provider is called with MESSAGES.
// When the caller supplied no turns at all, the task IS the user turn — the
// same assembly App::dispatchSkill() already does in the parent. Without this
// the provider would be asked to answer an empty conversation.
if ($messages === [] && $task !== '') {
    $messages[] = new SugarCraft\Crush\Messages\UserMessage($task);
}

// E647: the grant crosses the wire as name+description+schema (the parent's
// encodeToolSpecs()) and is rebuilt here as data-only Tools the provider can
// advertise. A frame without the toolSpecs key is a PRE-E647 parent: the
// child keeps running toolless exactly as it did, so the protocol widens
// without breaking either side. A HALF-PARSED grant fails loud — a tool the
// parent only partly described must not reach the model at all.
$tools = null;
if (array_key_exists('toolSpecs', $requestSpec)) {
    try {
        $tools = SugarCraft\Crush\Agents\ProcessExecutor::rehydrateTools($requestSpec['toolSpecs']);
    } catch (Throwable $e) {
        $fail('Worker tool grant is unusable: ' . $e->getMessage());
    }
}

$request = new SugarCraft\Crush\Providers\CompleteRequest(
    model: (string) ($requestSpec['model'] ?? ($agentConfig['model'] ?? '')),
    // Tool EXECUTION stays parent-side — rehydrateTools() says why an
    // implementation cannot cross a fork; this is the advertiseable half.
    tools: $tools,
    messages: $messages,
    systemPrompt: $requestSpec['systemPrompt'] ?? ($agentConfig['prompt'] ?? null),
    temperature: isset($requestSpec['temperature']) ? (float) $requestSpec['temperature'] : null,
    maxTokens: isset($requestSpec['maxTokens']) ? (int) $requestSpec['maxTokens'] : null,
);

// One heartbeat before the call, so the parent's 15s heartbeat deadline is
// measured from the moment the provider work actually starts rather than from
// spawn. The non-streaming path below cannot heartbeat while blocked, so it
// signs a lease instead — E646, resolved here rather than deferred.
$emit(['type' => 'heartbeat']);

$output = '';
$tokensUsed = 0;
$costUsd = 0.0;
$lastHeartbeat = time();

try {
    if ($provider->supportsStreaming()) {
        foreach ($provider->completeStream($request) as $chunk) {
            if ($chunk->isError) {
                $fail('Provider reported an error: ' . ($chunk->errorMessage ?? 'unknown'));
            }
            $tokensUsed += $chunk->tokensUsed;
            $costUsd += $chunk->costUsd;
            if ($chunk->content !== '') {
                $output .= $chunk->content;
                $emit(['type' => 'streaming', 'content' => $chunk->content]);
            }
            if (time() - $lastHeartbeat >= 5) {
                $emit(['type' => 'heartbeat']);
                $lastHeartbeat = time();
            }
        }
    } else {
        // E646: a non-streaming complete() gives this process no chance to
        // speak while the provider call runs — the parent's 15s heartbeat
        // window would SIGKILL a healthy worker mid-completion. The fix is a
        // bounded LEASE, not a capped request: completions may legitimately
        // run for tens of minutes and no total-request timeout is imposed
        // here or by the parent. The lease says "alive, blocked, working";
        // a worker that dies holding it still closes STDOUT and is reaped on
        // EOF immediately, and a lease that expires without the call
        // finishing reaps a wedged child on the old terms. 3600s bounds the
        // worst case between the two polarities.
        $emit(['type' => 'lease', 'seconds' => 3600]);
        $response = $provider->complete($request);
        if ($response->isError) {
            $fail('Provider reported an error: ' . ($response->errorMessage ?? 'unknown'));
        }
        $tokensUsed = $response->tokensUsed;
        $costUsd = $response->costUsd;
        $output = $response->content;
        if ($output !== '') {
            $emit(['type' => 'streaming', 'content' => $output]);
        }
    }
} catch (Throwable $e) {
    $fail('Provider call failed: ' . get_class($e) . ': ' . $e->getMessage());
}

$emit([
    'type' => 'complete',
    'status' => 'completed',
    'output' => $output,
    'tokensUsed' => $tokensUsed,
    'costUsd' => $costUsd,
]);

exit(0);
PHP;
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
     * ⚠️ WHAT THIS USED TO SAY, and it was true when it was written: "Until
     * that lands, this is the SHIPPED DEFAULT ... anything a user sees in the
     * agent pane came from the script below."
     *
     * WHAT IS TRUE NOW: that landing happened in the same change that added
     * {@see createLiveWorkerScript()}. The autoloader is computed in the parent
     * and shipped over the startup frame, the provider identity travels as
     * {@see __construct()}'s `$workerProvider`, and the offline substitute for
     * CI is `['type' => 'echo']` — the three prerequisites that paragraph
     * listed, in the order it listed them. `$simulatedWorker` defaults to
     * FALSE, so neither
     * {@see \SugarCraft\Crush\Agents\AgentWorkerPool::createDefaultExecutor()}
     * nor {@see \SugarCraft\Crush\Chat::executeAgents()} reaches this script
     * any more; nothing a user sees comes from it.
     *
     * WHY IT STILL EARNS ITS PLACE — and the reason is now stronger, not
     * weaker. Its predecessor paragraph guessed that "this simulation does not
     * disappear even then, it moves behind a seam", and that is exactly what
     * happened. It is the only worker in the tree with a FIXED, KNOWN TIMING
     * SHAPE (two `streaming` frames spaced by `usleep()`, about a second end to
     * end), and a live-pane test needs a live phase that spans many repaints.
     * MEASURED round 60: moving the workflow live-pane suite off this script
     * and onto a real provider relay made it fail 7 runs in 20, because
     * EchoProvider's whole answer arrives inside one 20ms sampling tick. The
     * simulation is not a leftover; it is the clock.
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
        self::closeProcessStatic($processDescriptor);
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

        // E673: group-aware TERM (ProcessContainment answers a group kill only
        // when the child measurably LEADS the group, never against our own).
        ProcessContainment::terminate($process);

        $deadline = time() + self::SIGTERM_GRACE_SECS;
        while (time() < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                return; // Exited gracefully within grace period
            }
            usleep(100_000); // 100ms
        }

        // Still running — SIGKILL (literal 9, per the ProcessReaper/
        // ProcessContainment rule: the pcntl constant is optional on this path).
        if (is_resource($process)) {
            ProcessContainment::terminate($process, 9);
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
     * The exit code of a worker, or null when it is UNKNOWN (E688).
     *
     * WHAT THIS USED TO DO, AND WHY IT WAS A LIE: it returned 0 whenever
     * proc_get_status() still said running=true. Worker pipes can EOF while
     * the child lives — or while its reap is merely unscheduled under CPU
     * pressure — and both tail callers read that fabricated 0 through
     * `!== 0` as "ended without a complete message", laundering a real crash
     * code (measured red in the aa lane: a worker that died with code 5 was
     * reported as a clean EOF).
     *
     * The honest instrument: a BOUNDED reap first (ProcessReaper's polled
     * wait — the exit is imminent if the EOF is true, so a short budget
     * resolves the overwhelmingly common delayed-reap case), then the status
     * read; null when the budget lands with the child still running. Null
     * keeps the generic branch as the outcome — an unknown must not claim a
     * code either way. The budget is deliberately NOT raised past
     * {@see self::EXIT_REAP_BUDGET_SECONDS}: this wait sits on the result
     * path of every worker turn, and the aa lesson (verbatim in ci.yml) says
     * stretched reap budgets trade a wrong answer for a slow one.
     */
    private function getExitCode($process): ?int
    {
        if (!is_resource($process)) {
            // E688: no handle means NO measured code — fabricating -1 would
            // let a caller attribute a crash that was never observed.
            return null;
        }

        $status = proc_get_status($process);
        if (!$status['running']) {
            return $status['exitcode'] ?? -1;
        }

        if (!ProcessReaper::waitForExit($process, self::EXIT_REAP_BUDGET_SECONDS)) {
            return null;
        }

        $status = proc_get_status($process);

        return $status['exitcode'] ?? -1;
    }

    /** E688 — the bounded reap budget {@see getExitCode()} gives a pipe-EOF'd child. */
    private const EXIT_REAP_BUDGET_SECONDS = 2.0;
}
