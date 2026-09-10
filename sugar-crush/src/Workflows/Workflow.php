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
     * Returns a new Workflow instance that stops (or keeps running) a
     * parallel stage after its first agent failure.
     *
     * Both directions are first-class: this field's whole point is the
     * choice, and a wither that could only ever set `true` would strand
     * half of it (E568 — see {@see mutate()}'s sentinel).
     */
    public function withStopOnFirstFailure(bool $stopOnFirstFailure): self
    {
        return $this->mutate(stopOnFirstFailure: $stopOnFirstFailure, stopOnFirstFailureSet: true);
    }

    /**
     * Clone-with-changes helper using named arguments.
     *
     * Mirrors charmbracelet/whalershark.Crunchy/mutate.
     *
     * `$stopOnFirstFailure` pairs with a `bool $stopOnFirstFailureSet`
     * sentinel, the same shape Usage.php's nullable counters use: a plain
     * `?? $this->stopOnFirstFailure` cannot tell "set it to false" from
     * "change nothing", and for THIS field those are the two halves of the
     * decision (E568). While the sentinel is unset the value is ignored
     * even if passed; while set, a null value reaches the constructor's
     * non-nullable bool and fails loudly there.
     */
    private function mutate(
        ?WorkflowStatus $workflowStatus = null,
        ?bool $stopOnFirstFailure = null,
        bool $stopOnFirstFailureSet = false,
    ): self {
        return new self(
            name: $this->name,
            description: $this->description,
            stages: $this->stages,
            maxConcurrent: $this->maxConcurrent,
            timeout: $this->timeout,
            workflowStatus: $workflowStatus ?? $this->workflowStatus,
            stopOnFirstFailure: $stopOnFirstFailureSet ? $stopOnFirstFailure : $this->stopOnFirstFailure,
        );
    }
}
