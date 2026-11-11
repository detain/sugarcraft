<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

/**
 * Represents a teammate agent within a team.
 *
 * A teammate is an independent agent spawned by the team lead to execute
 * tasks in parallel. Each teammate has its own model, tools, and optional
 * worktree isolation for conflict-free parallel file operations.
 */
final readonly class Teammate
{
    /**
     * @param list<string> $tools
     */
    public function __construct(
        public string $id,
        public string $teamId,
        public string $name,
        public AgentType $type,
        public string $model,
        public array $tools,
        public ?string $worktreePath = null,
        public ?string $branch = null,
    ) {}

    /**
     * Returns the current operational status of this teammate.
     *
     * There is no upstream analogue for this accessor. Since status tracking
     * is managed at the aggregate level (TeamManager),
     * this returns a default Idle status. Runtime status is tracked by the
     * team's aggregate, not on the teammate entity itself.
     */
    public function status(): TeammateStatus
    {
        return TeammateStatus::Idle;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::status()} instead. REMOVED-IN: 2.0.0.
     */
    public function getStatus(): TeammateStatus { return $this->status(); }

    /**
     * Returns a new Teammate instance with the given worktree path associated.
     *
     * This wires the teammate to a specific worktree for atomic task claiming:
     * when a teammate claims a task in a team with worktree isolation enabled,
     * the worktree is atomically associated with that claim.
     *
     * @param string $worktreePath Absolute filesystem path to the teammate's worktree.
     * @return self A new Teammate instance with the worktree path set.
     */
    public function withWorktreePath(string $worktreePath): self
    {
        return new self(
            id: $this->id,
            teamId: $this->teamId,
            name: $this->name,
            type: $this->type,
            model: $this->model,
            tools: $this->tools,
            worktreePath: $worktreePath,
            branch: $this->branch,
        );
    }
}
