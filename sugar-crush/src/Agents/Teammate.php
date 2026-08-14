<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

use SugarCraft\Crush\Support\HomeDirectory;

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
     * Returns the filesystem path to this teammate's inbox directory.
     *
     * Mirrors charmbracelet/charmbracelet.<Teammate>.GetInboxPath.
     * Inboxes are stored at ~/.sugar-crush/teams/{teamId}/inboxes/{teammateId}/
     * following the append-only JSON-lines format described in the mailbox spec.
     */
    public function getInboxPath(): string
    {
        $base = HomeDirectory::path();

        if (str_contains($this->teamId, '..') || str_contains($this->id, '..')) {
            throw new \InvalidArgumentException('Teammate ID and Team ID must not contain path traversal sequences.');
        }

        return sprintf(
            '%s/.sugar-crush/teams/%s/inboxes/%s',
            $base,
            $this->teamId,
            $this->id,
        );
    }

    /**
     * Returns the current operational status of this teammate.
     *
     * Mirrors charmbracelet/charmbracelet.<Teammate>.GetStatus.
     * Since status tracking is managed at the aggregate level (TeamManager),
     * this returns a default Idle status. Runtime status is tracked by the
     * team's aggregate, not on the teammate entity itself.
     */
    public function getStatus(): TeammateStatus
    {
        return TeammateStatus::Idle;
    }

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
