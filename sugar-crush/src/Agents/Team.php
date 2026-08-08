<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Agents;

/**
 * Team aggregate root — the central coordination point for a lead + teammates unit.
 *
 * A Team is created by the lead agent and defines the boundary for all teammate
 * activity: shared task list, shared mailbox, and the set of teammate agents
 * belonging to this team. The team ID is the namespace for all persisted state
 * (stored under ~/.sugar-crush/teams/{teamId}/).
 */
final class Team
{
    /** @var array<string, Teammate> */
    private array $teammates = [];

    private readonly TaskList $taskList;
    private readonly Mailbox $mailbox;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $leadAgentId,
        public readonly \DateTimeImmutable $createdAt,
        /**
         * Maximum number of teammates allowed in this team (excluding the lead).
         * Mirrors TeamConfig::$maxTeammates — passed through by TeamManager at
         * construction time so addTeammate() can enforce the cap directly.
         */
        public readonly int $maxTeammates = 5,
    ) {
        $this->taskList = new TaskList($this->basePath() . '/tasks.sqlite');
        $this->mailbox = new Mailbox($this->basePath() . '/mailbox');
    }

    /**
     * @return Teammate[]
     */
    public function getTeammates(): array
    {
        return array_values($this->teammates);
    }

    /**
     * Get a teammate by their ID.
     */
    public function getTeammate(string $id): ?Teammate
    {
        return $this->teammates[$id] ?? null;
    }

    /**
     * Add a teammate to this team.
     *
     * Enforces the $maxTeammates cap for genuinely new teammates; re-adding a
     * teammate that already occupies a slot (e.g. an immutable withXxx()
     * replacement of the same id) is always allowed since it does not grow
     * the team.
     *
     * @throws \InvalidArgumentException When the teammate's teamId does not match this team's id.
     * @throws \RuntimeException When the team is already at its maxTeammates capacity.
     */
    public function addTeammate(Teammate $teammate): void
    {
        if ($teammate->teamId !== $this->id) {
            throw new \InvalidArgumentException(sprintf(
                'Teammate %s does not belong to team %s',
                $teammate->id,
                $this->id,
            ));
        }

        $isNewSlot = !isset($this->teammates[$teammate->id]);
        if ($isNewSlot && count($this->teammates) >= $this->maxTeammates) {
            throw new \RuntimeException(sprintf(
                'Team "%s" has reached its maximum of %d teammates.',
                $this->id,
                $this->maxTeammates,
            ));
        }

        $this->teammates[$teammate->id] = $teammate;
    }

    /**
     * Remove a teammate from this team by their ID.
     *
     * No-op if the teammate is not in this team.
     */
    public function removeTeammate(string $teammateId): void
    {
        unset($this->teammates[$teammateId]);
    }

    /**
     * Atomically claim a task for a teammate and create their worktree.
     *
     * This satisfies the "atomic task claiming also claims the worktree"
     * plan requirement. Uses per-task flock locking from TaskList to ensure
     * no two teammates can claim the same task simultaneously.
     *
     * Steps:
     *  1. Claims the task via TaskList::claimTask()
     *  2. Runs WorktreeManager::sweepIfDue() so stale worktree cleanup gets a
     *     real caller instead of sitting dormant
     *  3. Creates the worktree via WorktreeManager::createWorktree()
     *  4. Updates the Teammate's worktreePath via withWorktreePath()
     *
     * If the task claim succeeds but worktree creation fails, the task claim
     * is NOT rolled back (the task will be in 'in-progress' state).
     *
     * @return bool true if both the task claim and worktree creation succeeded,
     *              false if the task was not claimable or the teammate was not found
     * @throws \RuntimeException When the worktree already exists for this teammate
     */
    public function claimTask(string $taskId, string $teammateId, WorktreeManager $wm): bool
    {
        $teammate = $this->teammates[$teammateId] ?? null;
        if ($teammate === null) {
            return false;
        }

        // Step 1: Atomically claim the task (per-task flock)
        if (!$this->taskList->claimTask($taskId, $teammateId)) {
            return false;
        }

        // Step 2: Cheap periodic sweep so stale worktrees actually get cleaned up
        $wm->sweepIfDue();

        // Step 3: Create the worktree
        $worktreePath = $wm->createWorktree($teammateId);

        // Step 4: Wire the teammate to the worktree (immutable replacement)
        $updatedTeammate = $teammate->withWorktreePath($worktreePath);
        $this->teammates[$teammateId] = $updatedTeammate;

        return true;
    }

    public function getTaskList(): TaskList
    {
        return $this->taskList;
    }

    public function getMailbox(): Mailbox
    {
        return $this->mailbox;
    }

    private function basePath(): string
    {
        $base = $_SERVER['HOME'] ?? '/tmp';

        if (str_contains($this->id, '..')) {
            throw new \InvalidArgumentException('Team ID must not contain path traversal sequences.');
        }

        return $base . '/.sugar-crush/teams/' . $this->id;
    }
}
