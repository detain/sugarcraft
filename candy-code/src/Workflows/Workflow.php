<?php

declare(strict_types=1);

namespace SugarCraft\Code\Workflows;

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
     * The stage shapes a workflow may declare.
     *
     * WHY one list: the engine's walker dispatches on these, the linter
     * diagnoses against them, and (C1) the YAML parser admits exactly them —
     * three surfaces that silently diverge if each keeps its own copy.
     * Order is the engine refusal sentence's order, kept byte-stable.
     *
     * @var list<string>
     */
    public const STAGE_TYPES = ['stage', 'parallel', 'pipeline', 'verification', 'map'];

    /**
     * P0-3: the run-wide dispatch backstop when `config.maxAgents` is unset.
     * Every agent dispatch — retries, stall restarts, loop rounds and the
     * agents of an included workflow — counts against it.
     */
    public const DEFAULT_MAX_AGENTS = 1000;

    /**
     * P0-3: the most items ONE fan-out stage may dispatch. A `from:` that
     * expands past it fails the stage loudly; it is never truncated to fit
     * (only an explicit `from.max` truncates, and that records its drop).
     */
    public const FAN_OUT_CEILING = 4096;

    /** P0-3: the upper bound of the computed default width. */
    public const DEFAULT_WIDTH_CAP = 16;

    /**
     * The width an unset `maxConcurrent` runs at: `min(16, max(1, cpus - 2))`.
     *
     * WHY a computed default rather than "no cap": a `from:` glob over a real
     * tree used to fork every match at once (1,370 workers for candy-code's
     * own `src/`). Two CPUs are left for the session and the provider I/O;
     * sixteen is the ceiling past which provider rate limits, not cores, are
     * the bottleneck. An explicit `maxConcurrent: 0` still means no cap.
     */
    public static function defaultConcurrency(int $cpus): int
    {
        return min(self::DEFAULT_WIDTH_CAP, max(1, $cpus - 2));
    }

    /**
     * @param string                $name                Human-readable workflow name.
     * @param string                $description         Brief description of what the workflow does.
     * @param array                 $stages              Ordered list of raw stage-task arrays built by WorkflowBuilder.
     * @param ?int                  $maxConcurrent       Maximum number of agents a parallel stage runs at once. Null (the
     *                                                    default) means UNSET: the engine runs {@see defaultConcurrency()}
     *                                                    of the host's CPU count. 0 is the explicit "no cap" (P0-3).
     * @param int                   $timeout             Per-stage timeout in seconds (default 3600 = 1 hour).
     * @param WorkflowStatus        $workflowStatus      Current lifecycle status (default Draft).
     * @param bool                  $stopOnFirstFailure When true, a parallel stage stops on first agent failure.
     * @param int                   $maxAgents           Run-wide agent-dispatch ceiling (P0-3, `config.maxAgents`).
     * @param ?int                  $maxTokens           Hard run-wide token ceiling (P1-7, `config.maxTokens`); null = none.
     * @param ?float                $maxCost             Hard run-wide USD ceiling (P1-7, `config.maxCost`); null = none.
     */
    public function __construct(
        public string         $name,
        public string         $description,
        public array          $stages = [],
        public ?int           $maxConcurrent = null,
        public int            $timeout = 3600,
        public WorkflowStatus $workflowStatus = WorkflowStatus::Draft,
        public bool           $stopOnFirstFailure = false,
        public int            $maxAgents = self::DEFAULT_MAX_AGENTS,
        public ?int           $maxTokens = null,
        public ?float         $maxCost = null,
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
     * A copy with its stage list replaced — the engine's P1-6 preset merge
     * runs on a copy, never on the registry's value.
     *
     * @param array<array-key, mixed> $stages
     */
    public function withStages(array $stages): self
    {
        return $this->mutate(stages: $stages);
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
     * House idiom (AGENTS.md "Immutable + fluent") with no upstream analogue
     * to cite — the WHY is the sentinel paragraph below, not a port.
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
        ?array $stages = null,
        ?bool $stopOnFirstFailure = null,
        bool $stopOnFirstFailureSet = false,
    ): self {
        return new self(
            name: $this->name,
            description: $this->description,
            stages: $stages ?? $this->stages,
            maxConcurrent: $this->maxConcurrent,
            timeout: $this->timeout,
            workflowStatus: $workflowStatus ?? $this->workflowStatus,
            stopOnFirstFailure: $stopOnFirstFailureSet ? $stopOnFirstFailure : $this->stopOnFirstFailure,
            maxAgents: $this->maxAgents,
            maxTokens: $this->maxTokens,
            maxCost: $this->maxCost,
        );
    }
}
