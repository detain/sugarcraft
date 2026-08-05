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
     * Add a teammate to this team.
     *
     * @throws \InvalidArgumentException When the teammate's teamId does not match this team's id.
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
