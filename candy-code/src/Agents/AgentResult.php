<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

/**
 * Represents the result of an agent execution within the worker pool.
 *
 * Mirrors upstream result structure: captures output, error, metrics, and timing
 * for each agent run in the parallel execution engine.
 */
final class AgentResult
{
    /**
     * @param int $attempts How many times the agent was run to produce this
     *        result: 1 unless {@see AgentWorkerPool} retried it after a
     *        failure ({@see SubAgent::$maxRetries}, audit WF-1(b)). A retried
     *        agent yields ONE result, its last attempt's, and that result's
     *        $tokensUsed/$costUsd/$startedAt cover every attempt — a failed
     *        attempt's spend is real spend.
     * @param bool $stalled Set only by {@see AgentWorkerPool}'s stall expiry:
     *        a FORKED worker that went silent past its
     *        {@see SubAgent::$stallTimeout} without publishing progress. It
     *        keeps the cause apart from a wall-clock timeout so the restart
     *        budget can be judged independently; the status stays
     *        {@see AgentStatus::TimedOut} for every existing reader.
     * @param ?bool $requestedWrite M-11 (cc-fix-1111) per-stage write signal,
     *        the register's option (a): "thread per-stage write-signal through
     *        AgentResult/complete frame OR take the constant-false hoist for
     *        tool-less workers". A SELF-REPORT from whoever ran the agent, not
     *        an inference from what shape the worker has. Tri-state, and the
     *        caller may suppress the two git diff sections of a later render
     *        ONLY on a positive false: null means NO REPORT and the render
     *        stays exactly five subprocess polls; true means a tool round ran
     *        (a write may have happened) and suppression is off; false is the
     *        ProcessExecutor worker's honest structural report — it makes one
     *        provider call and executes no tools, so nothing could have been
     *        written — and {@see EngineExecutor}'s zero-tools leg, taken from
     *        its own ToolFinished record. The freshness law this serves: "a
     *        model reading a stale git status after its own edits is worse
     *        than either cost" — the day a worker script grows a tool loop,
     *        that loop's execute site is where this becomes true, and
     *        AgentTest's parameter-list pin forces the caller threading to be
     *        re-read in the same commit.
     */
    public function __construct(
        public readonly string $agentId,
        public readonly AgentStatus $status,
        public readonly ?string $output = null,
        public readonly ?\Throwable $error = null,
        public readonly int $tokensUsed = 0,
        public readonly float $costUsd = 0.0,
        public readonly ?\DateTimeImmutable $startedAt = null,
        public readonly ?\DateTimeImmutable $completedAt = null,
        public readonly int $attempts = 1,
        public readonly bool $stalled = false,
        public readonly ?bool $requestedWrite = null,
    ) {}

    /**
     * Whether the agent completed successfully (no error, status is Completed).
     */
    public function isSuccess(): bool
    {
        return $this->status === AgentStatus::Completed && $this->error === null;
    }

    /**
     * Whether the agent failed (error present or status is Failed/Stopped/TimedOut).
     */
    public function isFailure(): bool
    {
        return $this->error !== null
            || $this->status === AgentStatus::Failed
            || $this->status === AgentStatus::Stopped
            || $this->status === AgentStatus::TimedOut;
    }

    /**
     * Elapsed time in milliseconds between startedAt and completedAt.
     * Returns 0 if either timestamp is null (result is incomplete).
     */
    public function durationMs(): int
    {
        if ($this->startedAt === null || $this->completedAt === null) {
            return 0;
        }

        $startMs = (float) $this->startedAt->format('U.u');
        $finishMs = (float) $this->completedAt->format('U.u');

        return (int) (($finishMs - $startMs) * 1000);
    }
}
