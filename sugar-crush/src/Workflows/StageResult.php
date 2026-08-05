<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Represents the result of a single stage within a workflow execution.
 *
 * Mirrors the stage-level result shape used in WorkflowResult::stageResults[].
 * Each stage aggregates the outcomes of its constituent agents along with
 * timing and status information for the stage as a whole.
 */
final readonly class StageResult
{
    /**
     * @param string            $stageName     Unique name of this stage within the workflow.
     * @param WorkflowStatus    $status       Current execution status of the stage.
     * @param string|null       $output       Stage-level output string, if any.
     * @param string|null       $error        Stage-level error message, if any.
     * @param AgentResult[]     $agents       Results for each agent executed within this stage.
     * @param \DateTimeImmutable $startedAt    Wall-clock time the stage began.
     * @param \DateTimeImmutable|null $completedAt Wall-clock time the stage finished, or null if still running.
     */
    public function __construct(
        public string                $stageName,
        public WorkflowStatus        $status,
        public ?string               $output = null,
        public ?string               $error = null,
        public array                 $agents = [],
        public \DateTimeImmutable    $startedAt = new \DateTimeImmutable(),
        public ?\DateTimeImmutable   $completedAt = null,
    ) {}

    /**
     * Whether the stage completed successfully (no error, status is Completed).
     */
    public function isSuccess(): bool
    {
        return $this->status === WorkflowStatus::Completed && $this->error === null;
    }

    /**
     * Whether the stage failed (error present or status is Failed/Cancelled).
     */
    public function isFailure(): bool
    {
        return $this->error !== null
            || $this->status === WorkflowStatus::Failed
            || $this->status === WorkflowStatus::Cancelled;
    }

    /**
     * Elapsed time in milliseconds between startedAt and completedAt.
     * Returns 0 if completedAt is null (stage is still running).
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
}
