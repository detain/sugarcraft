<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Represents the result of a complete workflow execution.
 *
 * Aggregates stage-level results, timing, and cost metrics for the
 * entire workflow run. Returned by WorkflowEngine upon completion.
 */
final readonly class WorkflowResult
{
    /**
     * @param string            $workflowId    Unique identifier of the executed workflow.
     * @param WorkflowStatus    $status        Final execution status of the workflow.
     * @param StageResult[]     $stageResults  Ordered list of stage results.
     * @param array             $context       Final workflow context after all stages.
     * @param int               $totalTokens    Cumulative tokens used across all stages.
     * @param float             $totalCost     Cumulative cost in USD across all stages.
     * @param \DateTimeImmutable $startedAt     Wall-clock time the workflow began.
     * @param \DateTimeImmutable|null $completedAt Wall-clock time the workflow finished, or null if still running.
     */
    public function __construct(
        public string                $workflowId,
        public WorkflowStatus        $status,
        public array                 $stageResults = [],
        public array                 $context = [],
        public int                   $totalTokens = 0,
        public float                 $totalCost = 0.0,
        public \DateTimeImmutable    $startedAt = new \DateTimeImmutable(),
        public ?\DateTimeImmutable   $completedAt = null,
    ) {}

    /**
     * Whether the workflow completed successfully (terminal status is Completed).
     */
    public function isSuccess(): bool
    {
        return $this->status === WorkflowStatus::Completed;
    }

    /**
     * Whether the workflow failed (terminal status is Failed or Cancelled).
     */
    public function isFailure(): bool
    {
        return $this->status === WorkflowStatus::Failed
            || $this->status === WorkflowStatus::Cancelled;
    }

    /**
     * Elapsed time in milliseconds for this workflow.
     * Returns 0 if completedAt is null (workflow is still running).
     */
    public function durationMs(): int
    {
        if ($this->completedAt === null) {
            return 0;
        }

        $startMs = (float) $this->startedAt->format('U.u');
        $finishMs = (float) $this->completedAt->format('U.u');

        return (int) (($finishMs - $startMs) * 1000);
    }

    /**
     * Total elapsed time in milliseconds across all stageResults.
     * Sums the individual stage durations, not the workflow-level time span.
     * Returns 0 if no stages have completed.
     */
    public function totalDurationMs(): int
    {
        $total = 0;

        foreach ($this->stageResults as $stage) {
            $total += $stage->durationMs();
        }

        return $total;
    }
}
