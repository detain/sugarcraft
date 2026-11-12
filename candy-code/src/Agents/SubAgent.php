<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Permissions\PermissionGate;

final class SubAgent
{
    /**
     * The public string spelling of {@see SubAgentStatus} (v4-F15): the
     * enum's cases are the canonical vocabulary and a lockstep pin in
     * SubAgentTest asserts these constants stay exactly its backing
     * values, because $status below is read and written as a string by
     * consumer files outside this refactor's reach.
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_STREAMING = 'streaming';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_STOPPED = 'stopped';
    public const STATUS_FAILED = 'failed';

    /** Status of the subagent task. */
    public string $status;
    /** Output accumulated during execution. */
    public string $output;
    /**
     * When execution actually began, as distinct from {@see $createdAt}.
     * A sub-agent can sit `pending` in the pool for a long time, so elapsed
     * *work* time has to be measured from the moment
     * {@see AgentManager::executeSubAgent()} started it — measuring from
     * creation would report queue time as work time (§5 E6).
     */
    public ?\DateTimeImmutable $startedAt = null;
    /**
     * When the task reached a terminal state — success, failure or stop.
     * Every terminal transition stamps it (see {@see AgentManager}), because
     * {@see elapsedSeconds()} uses it to freeze the work span; a null here on
     * a dead sub-agent would report an ever-growing elapsed time.
     */
    public ?\DateTimeImmutable $completedAt = null;
    /**
     * Tokens consumed so far, accumulated per streaming chunk by
     * {@see AgentManager::executeSubAgent()} rather than only at completion,
     * so a still-running sub-agent reports real usage instead of 0.
     */
    public int $tokensUsed = 0;
    /** Dollar cost accumulated alongside {@see $tokensUsed}. */
    public float $costUsd = 0.0;
    /** Error message if the task failed. */
    public ?string $error = null;
    /**
     * Lines of output produced so far when that is known to exceed what
     * {@see $output} still holds — a delegated run's trail is clipped to a
     * tail before it crosses the wire, so its mirror row is told the real
     * count. 0 means "count {@see $output}", which is right for every row
     * whose buffer is whole. Read through {@see outputLineCount()}.
     */
    public int $outputLines = 0;
    /**
     * The model this run really executes on, when that differs from what its
     * preset asked for: a Task run always uses the session's engine, so a
     * preset's `model: sonnet` is a request it never honours. '' means
     * "the preset's model is the truth". Read through {@see model()}.
     */
    public string $runModel = '';
    /**
     * The run's CURRENT context in tokens — its latest request's size — as
     * opposed to the running {@see $tokensUsed}. 0 when unknown.
     */
    public int $contextTokens = 0;
    /**
     * The run's most recent tool calls, newest last — what the Tools pane
     * lists for it beside the session's own calls.
     *
     * @var list<array{id: string, label: string, state: string, at: int}>
     */
    public array $recentCalls = [];

    /**
     * Parent-conversation rows a conversation-inheriting fork launched this
     * run with, oldest first (opt-in via the Task tool's `fork` argument;
     * {@see ConversationFork}). Always empty for a plain launch — then, and
     * only then, is the child's request byte-identical to the pre-fork
     * shape.
     *
     * @var list<\SugarCraft\Code\Messages\Message>
     */
    public array $inheritedContext = [];

    /**
     * Dispatch priority when a width cap queues launches (see
     * {@see AgentWorkerPool::dequeueNext()}): a higher value takes a freed
     * seat ahead of lower-priority waiters, equal priorities keep submission
     * order, and the field is inert without a cap — no cap means no
     * contention, every submission dispatches immediately as before. It
     * never queues anything that today would be refused: the refuse-vs-queue
     * door and a halted batch are untouched by priority. 0 is normal, the
     * pre-priority default that keeps every existing dispatch byte-identical.
     */
    public int $priority = 0;

    /**
     * Where the run works (roadmap 4.9; honored by workflow stages since 4.10):
     * {@see Isolation::Worktree} gives it a git worktree of its own —
     * {@see \SugarCraft\Code\Tools\BuiltIn\TaskTool} for delegated runs and
     * {@see EngineExecutor} for workflow stage runs, both through the shared
     * {@see WorktreeIsolation} machinery: refusal-not-degrade, and the result
     * text names the tree (and branch) when work was kept.
     * The constructor's argument when one is given, else the agent's preset
     * `isolation:`, else {@see Isolation::None} — so a roster row built from
     * an `isolation: worktree` preset is isolated wherever it is turned into
     * a run, without each construction site having to copy the field over.
     */
    public readonly Isolation $isolation;

    /**
     * @param int $timeout    Wall-clock bound in seconds, enforced by
     *                        {@see AgentWorkerPool} on its forking path: the
     *                        forked worker and every process it started are
     *                        killed when it expires, and the agent settles
     *                        {@see AgentStatus::TimedOut} (audit WF-1). Zero or
     *                        less means no per-agent bound. The pool cannot
     *                        interrupt its synchronous dispatch paths, so
     *                        there the executor enforces it:
     *                        {@see ProcessExecutor} kills its worker at the
     *                        earlier of this and its own bound, and
     *                        {@see EngineExecutor} stops the run at the next
     *                        progress event past it (audit WF-1-rem).
     * @param int $maxRetries How many times {@see AgentWorkerPool} re-runs
     *                        this agent after a failed or timed-out attempt
     *                        (never after a cancellation), within the run's
     *                        time budget; the pool's own floor
     *                        ({@see AgentPoolConfig::$maxRetries}) applies
     *                        when it is higher (audit WF-1(b)).
     * @param int $stallTimeout Idle bound in seconds, re-armable: the pool's
     *                          forking path expires the run when the forked
     *                          worker goes SILENT — publishing no new progress
     *                          bytes for this many seconds — even while its
     *                          wall-clock $timeout has not passed
     *                          ({@see AgentWorkerPool::pumpProgress()} re-arms
     *                          the deadline on every growth). Zero or less
     *                          means no stall bound: the default, fully inert,
     *                          so every existing construction keeps today's
     *                          bytes. Fork path only — the synchronous and
     *                          inline dispatch paths never publish progress,
     *                          so there is nothing to re-arm; those stay
     *                          wall-clock-only. Option A of the C7' decision
     *                          (/tmp/opencode/cc-eval-1106/C7PRIME-DECISION.md).
     * @param int $stallRestarts How many times a stall-expiry may re-dispatch
     *                           this agent. A SEPARATE budget from
     *                           $maxRetries: a silent worker consumes neither
     *                           model retries, and a failed model attempt
     *                           consumes no restarts, so neither counter can
     *                           starve the other. Zero (default) disables
     *                           stall re-dispatch: a stall expires exactly as
     *                           an un-restarted timeout does today.
     */
    public function __construct(
        public readonly string $id,
        public readonly Agent $agent,
        public readonly string $task,
        public readonly \DateTimeImmutable $createdAt = new \DateTimeImmutable(),
        public readonly int $timeout = 300,
        public readonly int $maxRetries = 0,
        ?Isolation $isolation = null,
        public readonly ?PermissionGate $permissionGate = null,
        public readonly ?string $teamId = null,
        public readonly ?string $teammateId = null,
        // G3: decoded-trusted JSON-schema document constraining this run's terminal turn; null = free-text report.
        public readonly ?array $outputSchema = null,
        // S2: the model THIS stage asked to dial on (the ladder in ModelChoice
        // judges it at dispatch; a stage ask that cannot be honoured refuses).
        // Distinct from $runModel (what actually ran) and $agent->model (preset
        // ask); null keeps today's session-default path byte-identical.
        public readonly ?string $model = null,
        public readonly int $stallTimeout = 0,
        public readonly int $stallRestarts = 0,
        // P1-6 (wf-impl): the reasoning effort THIS run asks for — a workflow
        // stage's `effort:` (or its preset's). {@see EngineExecutor} sends it
        // as the request's reasoning effort, the way an active skill's
        // `effort` rides App::$reasoningEffort. Null leaves the request's
        // effort untouched, so every existing construction is byte-identical.
        public readonly ?Effort $effort = null,
    ) {
        $this->isolation = $isolation ?? $agent->isolation ?? Isolation::None;
        $this->status = self::STATUS_PENDING;
        $this->output = '';
    }

    /**
     * How many lines this run has produced: {@see $outputLines} when it
     * reports more than the buffer holds, else the buffer's own count — what
     * an "N more lines" figure has to read, since the buffer may be a tail.
     */
    public function outputLineCount(): int
    {
        $held = $this->output === '' ? 0 : substr_count(rtrim($this->output, "\n"), "\n") + 1;

        return max($held, $this->outputLines);
    }

    /** The model to show for this run — see {@see $runModel}. */
    public function model(): string
    {
        return $this->runModel !== '' ? $this->runModel : $this->agent->model;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING
            || $this->status === self::STATUS_STREAMING;
    }

    /**
     * This run's status as the parsed case (v4-F15): the enum entry point
     * for code that wants typed state instead of the wire string. Throws
     * on an unknown value on purpose — a status outside the vocabulary is
     * a bug worth a loud one, and every production writer uses one of the
     * six constants {@see SubAgentStatus} mirrors.
     */
    public function statusCase(): SubAgentStatus
    {
        return SubAgentStatus::from($this->status);
    }

    public function isComplete(): bool
    {
        return $this->status === self::STATUS_COMPLETE;
    }

    public function isStopped(): bool
    {
        return $this->status === self::STATUS_STOPPED
            || $this->status === self::STATUS_FAILED;
    }

    public function durationMs(): ?int
    {
        if ($this->completedAt === null) {
            return null;
        }

        return (int) (($this->completedAt->getTimestamp() - $this->createdAt->getTimestamp()) * 1000);
    }

    /**
     * Wall-clock seconds this sub-agent has been (or was) executing.
     *
     * Returns 0 while the sub-agent is still `pending`: it has not started,
     * so there is no elapsed work time to report — 0 there is the honest
     * value, not a placeholder. Once complete/stopped the span freezes at
     * startedAt -> completedAt; while running it keeps counting against the
     * current time so a long-running agent's status line ticks up.
     */
    public function elapsedSeconds(): int
    {
        if ($this->startedAt === null) {
            return 0;
        }

        $end = $this->completedAt?->getTimestamp() ?? time();

        return max(0, $end - $this->startedAt->getTimestamp());
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'agent' => $this->agent->name,
            'task' => $this->task,
            'status' => $this->status,
            'output' => $this->output,
            'created_at' => $this->createdAt->format('c'),
            'started_at' => $this->startedAt?->format('c'),
            'completed_at' => $this->completedAt?->format('c'),
            'elapsed_seconds' => $this->elapsedSeconds(),
            'tokens_used' => $this->tokensUsed,
            'cost_usd' => $this->costUsd,
            'error' => $this->error,
            'timeout' => $this->timeout,
            'max_retries' => $this->maxRetries,
            'isolation' => $this->isolation->value,
            'has_permission_gate' => $this->permissionGate !== null,
            'team_id' => $this->teamId,
            'teammate_id' => $this->teammateId,
            'output_schema' => $this->outputSchema,
            'model' => $this->model,
            'stall_timeout' => $this->stallTimeout,
            'stall_restarts' => $this->stallRestarts,
            'effort' => $this->effort?->value,
        ];
    }
}
