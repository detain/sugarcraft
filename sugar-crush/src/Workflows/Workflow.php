<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Workflows;

/**
 * Immutable value object representing a workflow definition.
 *
 * Contains the structural blueprint of a workflow: its name, description,
 * ordered list of stage definitions, concurrency limit, and overall timeout.
 * Status transitions are handled via withStatus(), which returns a new instance.
 *
 * @see WorkflowBuilder For the builder that populates the stages array.
 */
final readonly class Workflow
{
    /**
     * @param string                $name                Human-readable workflow name.
     * @param string                $description         Brief description of what the workflow does.
     * @param array                 $stages              Ordered list of raw stage-task arrays built by WorkflowBuilder.
     * @param int                   $maxConcurrent       Maximum number of stages that may run concurrently (default 5).
     * @param int                   $timeout             Per-stage timeout in seconds (default 3600 = 1 hour).
     * @param WorkflowStatus        $workflowStatus      Current lifecycle status (default Draft).
     * @param bool                  $stopOnFirstFailure When true, a parallel stage stops on first agent failure.
     */
    public function __construct(
        public string         $name,
        public string         $description,
        public array          $stages = [],
        public int            $maxConcurrent = 5,
        public int            $timeout = 3600,
        public WorkflowStatus $workflowStatus = WorkflowStatus::Draft,
        public bool           $stopOnFirstFailure = false,
    ) {}

    /**
     * Returns a new Workflow instance with the given status applied.
     *
     * Enables immutable status transitions without modifying the original.
     */
    public function withStatus(WorkflowStatus $status): self
    {
        return $this->mutate(workflowStatus: $status);
    }

    /**
     * Clone-with-changes helper using named arguments.
     *
     * Mirrors charmbracelet/whalershark.Crunchy/mutate.
     */
    private function mutate(
        WorkflowStatus $workflowStatus = null,
        bool $stopOnFirstFailure = null,
    ): self {
        return new self(
            name: $this->name,
            description: $this->description,
            stages: $this->stages,
            maxConcurrent: $this->maxConcurrent,
            timeout: $this->timeout,
            workflowStatus: $workflowStatus ?? $this->workflowStatus,
            stopOnFirstFailure: $stopOnFirstFailure ?? $this->stopOnFirstFailure,
        );
    }
}
