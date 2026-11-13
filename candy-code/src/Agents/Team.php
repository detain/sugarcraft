<?php

declare(strict_types=1);

namespace SugarCraft\Code\Agents;

use SugarCraft\Code\Hooks\HookDispatcher;
use SugarCraft\Code\Support\HomeDirectory;

/**
 * Team aggregate root — the central coordination point for a lead + teammates unit.
 *
 * A Team is created by the lead agent and defines the boundary for all teammate
 * activity: shared task list, shared mailbox, and the set of teammate agents
 * belonging to this team. The team ID is the namespace for all persisted state
 * (stored under ~/.candy-code/teams/{teamId}/).
 *
 * The registry is per-USER, so teams from every project a user has ever run
 * land in one file. A Team therefore carries the workspace scope its creating
 * tool instance stamped ({@see TeamTool::$scope}); teams written before the
 * stamp exists carry null and are treated as legacy by the tool gate.
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
         * Maximum number of teammates allowed in this team (excluding the lead),
         * or null for no cap. Mirrors TeamConfig::$maxTeammates — passed through
         * by TeamManager at construction time so addTeammate() can enforce the
         * cap directly.
         */
        public readonly ?int $maxTeammates = null,
        /**
         * The chain the task list raises `TaskCreated`, `TaskCompleted` and
         * `TeammateIdle` through (roadmap 4.6-2) — the launch's, handed down
         * by {@see TeamManager}. Null raises nothing.
         */
        ?HookDispatcher $hookDispatcher = null,
        /** Where those hooks run ({@see TaskList}'s `$projectRoot`); null is the process directory. */
        ?string $projectRoot = null,
        /**
         * The workspace fingerprint the creating {@see TeamTool} stamped at
         * launch, or null for a team written before scoping existed. The tool
         * gate refuses to drive a team whose stamp differs from its own —
         * team ids are model-chosen and visible through `list`, so without a
         * stamp one project's agent could read or claim another project's
         * mailbox and task rows.
         */
        public readonly ?string $scopeKey = null,
    ) {
        $this->taskList = new TaskList($this->basePath() . '/tasks.sqlite', $hookDispatcher, $projectRoot);
        $this->mailbox = new Mailbox($this->basePath() . '/mailbox');
    }

    /**
     * @return Teammate[]
     */
    public function teammates(): array
    {
        return array_values($this->teammates);
    }

    /**
     * Get a teammate by their ID.
     */
    public function teammate(string $id): ?Teammate
    {
        return $this->teammates[$id] ?? null;
    }

    /**
     * Add a teammate to this team.
     *
     * Enforces the $maxTeammates cap — when there is one — for genuinely new
     * teammates; re-adding a teammate that already occupies a slot (e.g. an
     * immutable withXxx() replacement of the same id) is always allowed since
     * it does not grow the team.
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
        if ($isNewSlot && $this->maxTeammates !== null && count($this->teammates) >= $this->maxTeammates) {
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
     * If the task claim succeeds but worktree creation fails, the claim is
     * released (E136 — {@see \SugarCraft\Code\Agents\TaskList::releaseTask()})
     * and the worktree failure propagates, so the task stays claimable.
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

        // Steps 2-3, guarded (E136): everything that can fail AFTER the claim
        // is on the table used to strand the task `in_progress` and assigned
        // to a teammate with no worktree — unretryable, because
        // TaskList::claimTask() only accepts Pending tasks, so the failure
        // the caller saw as an exception became permanent bookkeeping. The
        // claim is now released when these steps throw, leaving the task
        // claimable again once the cause is fixed. The original failure is
        // rethrown untouched: the rollback explains the bookkeeping, it does
        // not replace the diagnosis.
        try {
            // Step 2: Cheap periodic sweep so stale worktrees actually get cleaned up
            $wm->sweepIfDue();

            // Step 3: Create the worktree
            $worktreePath = $wm->createWorktree($teammateId);
        } catch (\Throwable $failure) {
            $this->taskList->releaseTask($taskId, $teammateId);

            throw $failure;
        }

        // Step 4: Wire the teammate to the worktree (immutable replacement)
        $updatedTeammate = $teammate->withWorktreePath($worktreePath);
        $this->teammates[$teammateId] = $updatedTeammate;

        return true;
    }

    public function taskList(): TaskList
    {
        return $this->taskList;
    }

    public function mailbox(): Mailbox
    {
        return $this->mailbox;
    }

    /**
     * Rebuild-path choke for every persisted Team ID.
     *
     * Registry rows can predate the create-time guards (or be hand-tampered),
     * so this sink re-faces the same separator set {@see TeamManager::createTeam()}
     * checks — a bare '..' test alone let a stored 'a/b' key mint nested
     * directories under the teams root instead of beside the other teams.
     */
    private function basePath(): string
    {
        $base = HomeDirectory::path();

        if (str_contains($this->id, '..') || str_contains($this->id, '/') || str_contains($this->id, '\\')) {
            throw new \InvalidArgumentException('Team ID must not contain path traversal sequences, slashes, or backslashes.');
        }

        return $base . '/.candy-code/teams/' . $this->id;
    }
}
