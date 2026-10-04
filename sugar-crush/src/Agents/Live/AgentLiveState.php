<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents\Live;

use SugarCraft\Crush\Events\SubAgentActivity;

/**
 * What the parent knows about one delegated run right now: the fold of every
 * v2 {@see SubAgentActivity} frame it received for that run (Appendix P
 * §4.5, step P-B2). It is what one live agent line under a Task row is drawn
 * from ({@see \SugarCraft\Crush\Tui\AgentActivityLine}).
 *
 * A value: {@see apply()} returns a new state and never touches this one. The
 * mutable seam is {@see AgentLiveRegistry}, which holds the current state per
 * run the way `Chat`'s live tool-event inbox holds pending events.
 *
 * WHAT THE LINE READS. A run's "latest item" is, in order:
 *  1. the newest tool call that has started and not finished — what the run
 *     is doing at this moment;
 *  2. otherwise the most recent of: the last finished call (with its ✓/✗),
 *     a thinking burst, or a prose fragment;
 *  3. otherwise nothing yet ("starting…").
 * Recency, not the fixed kind order Appendix P §4.1 sketches, decides among
 * the second group: under the fixed order a thought that follows a finished
 * call could never show, and "the last thing it did" is the question the line
 * answers.
 *
 * STALE FRAMES DROP. Frames carry a per-run `seq`; one at or below the last
 * applied is a reordered or replayed beat and changes nothing, so a late
 * progress frame cannot reopen a finished run.
 *
 * v1 frames (no items, no stats) still fold: their running token and cost
 * totals stand in for the v2 stats, and the line shows "starting…" until an
 * item arrives — no liveness is invented for a frame that carried none.
 */
final readonly class AgentLiveState
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_FINISHED = 'finished';

    /** Started-but-unfinished calls remembered per run; a run never has more in flight than this matters for. */
    public const MAX_RUNNING_CALLS = 16;

    /**
     * @param array<string, ActivityItem> $running started tool calls not yet
     *        finished, keyed by call id, oldest first
     * @param ?ActivityItem $latest the most recent non-inbox item applied
     * @param string $latestSummary the summary of the call $latest finished,
     *        carried over from its tool_started item ('' when unknown)
     */
    private function __construct(
        public string $id,
        public string $parentCallId,
        public ?string $parentAgentId,
        public string $name,
        public string $description,
        public string $status,
        public int $seq,
        public array $running,
        public ?ActivityItem $latest,
        public string $latestSummary,
        public int $toolCount,
        public int $tokensIn,
        public int $tokensOut,
        public int $tokensUsed,
        public float $costUsd,
        public int $step,
        public int $maxSteps,
        public ?float $startedAt,
        public ?float $finishedAt,
        public string $outcome,
        public ?string $error,
        public ?string $resumeId,
        /**
         * The run's own JSONL transcript ({@see SubAgentTranscriptLog}), as
         * its `started` frame announced it (P-C1) — what the Agent View tails
         * while the run is live (P-C2). Null for a run that announced none (no
         * session, a v1 frame).
         */
        public ?string $transcriptLog = null,
        /**
         * The `subagent` child session the finished run was stored as (P-C1),
         * which the Agent View reads once the log is gone. Only the parent sets
         * it, on the finished frame it projected.
         */
        public ?string $childSessionId = null,
    ) {
    }

    /**
     * The state a run's first frame makes, whichever op it is — a frame can
     * be the first one seen (the queued placeholder, or a started frame that
     * was lost before a progress one).
     *
     * @param float $now the parent's clock, seconds — stamps a run that
     *        reports no start time of its own, and a finish
     */
    public static function fromActivity(SubAgentActivity $activity, float $now): self
    {
        $blank = new self(
            id: $activity->id,
            parentCallId: $activity->parentCallId,
            parentAgentId: $activity->parentAgentId,
            name: $activity->name,
            description: $activity->description,
            status: self::STATUS_QUEUED,
            seq: 0,
            running: [],
            latest: null,
            latestSummary: '',
            toolCount: 0,
            tokensIn: 0,
            tokensOut: 0,
            tokensUsed: 0,
            costUsd: 0.0,
            step: 0,
            maxSteps: 0,
            startedAt: null,
            finishedAt: null,
            outcome: '',
            error: null,
            resumeId: null,
        );

        return $blank->apply($activity, $now);
    }

    /**
     * This run with one more frame folded in, or this very state when the
     * frame is stale (its seq is not newer) or belongs to another run.
     */
    public function apply(SubAgentActivity $activity, float $now): self
    {
        if ($activity->id !== $this->id || $activity->seq <= $this->seq) {
            return $this;
        }
        // A finished run stays finished: nothing after its terminal frame is
        // news about it.
        if ($this->status === self::STATUS_FINISHED) {
            return $this;
        }

        $running = $this->running;
        $latest = $this->latest;
        $latestSummary = $this->latestSummary;
        $toolsSeen = 0;
        foreach ($activity->items as $item) {
            switch ($item->type) {
                case ActivityItem::TOOL_STARTED:
                    unset($running[$item->callId]);
                    $running[$item->callId] = $item;
                    if (count($running) > self::MAX_RUNNING_CALLS) {
                        array_shift($running);
                    }
                    $toolsSeen++;
                    break;
                case ActivityItem::TOOL_FINISHED:
                    $latestSummary = isset($running[$item->callId]) ? $running[$item->callId]->summary : '';
                    unset($running[$item->callId]);
                    $latest = $item;
                    break;
                case ActivityItem::THINKING:
                case ActivityItem::TEXT:
                    $latest = $item;
                    break;
                default:
                    // An inbox delivery (§5) is not something the run did.
                    break;
            }
        }

        $stats = $activity->stats;
        $finished = $activity->op === SubAgentActivity::OP_FINISHED;

        return $this->mutate([
            'parentCallId' => $activity->parentCallId !== '' ? $activity->parentCallId : $this->parentCallId,
            'parentAgentId' => $activity->parentAgentId ?? $this->parentAgentId,
            'name' => $activity->name,
            'description' => $activity->description !== '' ? $activity->description : $this->description,
            'status' => match ($activity->op) {
                SubAgentActivity::OP_QUEUED => self::STATUS_QUEUED,
                SubAgentActivity::OP_FINISHED => self::STATUS_FINISHED,
                default => self::STATUS_RUNNING,
            },
            'seq' => $activity->seq,
            'running' => $finished ? [] : $running,
            'latest' => $latest,
            'latestSummary' => $latestSummary,
            // The child's own count wins; a v1 frame has none, so the parent
            // counts the starts it saw.
            'toolCount' => $stats['tools'] ?? ($this->toolCount + $toolsSeen),
            'tokensIn' => $stats['tokensIn'] ?? $this->tokensIn,
            'tokensOut' => $stats['tokensOut'] ?? $this->tokensOut,
            'tokensUsed' => max($this->tokensUsed, $activity->tokensUsed),
            'costUsd' => max($stats['costUsd'] ?? 0.0, $activity->costUsd, $this->costUsd),
            'step' => $stats['step'] ?? $this->step,
            'maxSteps' => $stats['maxSteps'] ?? $this->maxSteps,
            'startedAt' => $stats['startedAt'] ?? $this->startedAt ?? ($activity->op === SubAgentActivity::OP_QUEUED ? null : $now),
            'finishedAt' => $finished ? $now : null,
            'outcome' => $finished ? $activity->outcome : '',
            'error' => $finished ? $activity->error : null,
            'resumeId' => $activity->resumeId ?? $this->resumeId,
            'transcriptLog' => $activity->transcriptLog ?? $this->transcriptLog,
            'childSessionId' => $activity->childSessionId ?? $this->childSessionId,
        ]);
    }

    public function isQueued(): bool
    {
        return $this->status === self::STATUS_QUEUED;
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isFinished(): bool
    {
        return $this->status === self::STATUS_FINISHED;
    }

    /**
     * The tool call the run is in right now — the newest started call that
     * has not finished — or null.
     */
    public function currentCall(): ?ActivityItem
    {
        if ($this->running === []) {
            return null;
        }

        return $this->running[array_key_last($this->running)];
    }

    /**
     * Tokens the run has spent: its prompt plus reply counts when the child
     * reported them, else its v1 running total.
     */
    public function tokens(): int
    {
        return max($this->tokensIn + $this->tokensOut, $this->tokensUsed);
    }

    /**
     * Seconds the run has been going at $now, or ran for once finished; null
     * while it is queued or reported no start.
     */
    public function elapsed(float $now): ?float
    {
        if ($this->startedAt === null) {
            return null;
        }

        return max(0.0, ($this->finishedAt ?? $now) - $this->startedAt);
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function mutate(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
