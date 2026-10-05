<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Permissions\PermissionGate;

final class SubAgent
{
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
     * creation would report queue time as work time (crush_feat.md §5 E6).
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
     * Where the run works (roadmap 4.9): {@see Isolation::Worktree} gives it
     * a git worktree of its own ({@see \SugarCraft\Crush\Tools\BuiltIn\TaskTool}).
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
        ];
    }
}
