<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentType;
use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Agents\Teammate;
use SugarCraft\Crush\Agents\Team;

/**
 * Tests for Team - aggregate root for lead + teammates coordination.
 */
final class TeamTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction and property access
    // -------------------------------------------------------------------------

    public function testConstructionWithAllFields(): void
    {
        $createdAt = new \DateTimeImmutable('2026-01-15T10:00:00Z');
        $team = new Team(
            id: 'team-alpha',
            name: 'Alpha Squad',
            leadAgentId: 'lead-001',
            createdAt: $createdAt,
        );

        $this->assertSame('team-alpha', $team->id);
        $this->assertSame('Alpha Squad', $team->name);
        $this->assertSame('lead-001', $team->leadAgentId);
        $this->assertSame($createdAt, $team->createdAt);
    }

    public function testConstructionWithMinimalFields(): void
    {
        $createdAt = new \DateTimeImmutable();
        $team = new Team(
            id: 'team-beta',
            name: 'Beta Team',
            leadAgentId: 'lead-002',
            createdAt: $createdAt,
        );

        $this->assertSame('team-beta', $team->id);
        $this->assertSame('Beta Team', $team->name);
        $this->assertSame('lead-002', $team->leadAgentId);
        $this->assertSame($createdAt, $team->createdAt);
    }

    // -------------------------------------------------------------------------
    // getTeammates()
    // -------------------------------------------------------------------------

    public function testGetTeammatesInitiallyEmpty(): void
    {
        $team = new Team(
            id: 'team-empty',
            name: 'Empty Team',
            leadAgentId: 'lead-empty',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame([], $team->getTeammates());
    }

    public function testGetTeammatesReturnsAllAddedTeammates(): void
    {
        $team = $this->createTeam('team-multi');

        $teammate1 = $this->createTeammate('tm-1', 'team-multi', 'Alice', AgentType::Coder);
        $teammate2 = $this->createTeammate('tm-2', 'team-multi', 'Bob', AgentType::Reviewer);

        $team->addTeammate($teammate1);
        $team->addTeammate($teammate2);

        $teammates = $team->getTeammates();
        $this->assertCount(2, $teammates);
        $this->assertSame($teammate1, $teammates[0]);
        $this->assertSame($teammate2, $teammates[1]);
    }

    // -------------------------------------------------------------------------
    // addTeammate()
    // -------------------------------------------------------------------------

    public function testAddTeammateSucceedsForMatchingTeamId(): void
    {
        $team = $this->createTeam('team-add');
        $teammate = $this->createTeammate('tm-add-1', 'team-add', 'Carol', AgentType::Tester);

        $team->addTeammate($teammate);

        $this->assertCount(1, $team->getTeammates());
        $this->assertSame($teammate, $team->getTeammates()[0]);
    }

    public function testAddTeammateThrowsForMismatchedTeamId(): void
    {
        $team = $this->createTeam('team-a');
        $teammate = $this->createTeammate('tm-wrong', 'team-b', 'Dan', AgentType::Coder);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Teammate tm-wrong does not belong to team team-a');

        $team->addTeammate($teammate);
    }

    public function testAddTeammateOverwritesExistingWithSameId(): void
    {
        $team = $this->createTeam('team-overwrite');

        // Two different objects with the same identity
        $original = $this->createTeammate('tm-same', 'team-overwrite', 'Original', AgentType::Coder);
        $replacement = new Teammate(
            id: 'tm-same',
            teamId: 'team-overwrite',
            name: 'Replacement',
            type: AgentType::Reviewer,
            model: 'claude-sonnet-4-6',
            tools: ['Read', 'Edit', 'Bash'],
        );

        $team->addTeammate($original);
        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('Original', $team->getTeammates()[0]->name);

        $team->addTeammate($replacement);
        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('Replacement', $team->getTeammates()[0]->name);
    }

    // -------------------------------------------------------------------------
    // removeTeammate()
    // -------------------------------------------------------------------------

    public function testRemoveTeammateSucceeds(): void
    {
        $team = $this->createTeam('team-remove');
        $teammate = $this->createTeammate('tm-remove', 'team-remove', 'Eve', AgentType::Devops);

        $team->addTeammate($teammate);
        $this->assertCount(1, $team->getTeammates());

        $team->removeTeammate('tm-remove');
        $this->assertCount(0, $team->getTeammates());
    }

    public function testRemoveTeammateIsIdempotent(): void
    {
        $team = $this->createTeam('team-remove-idempotent');
        $teammate = $this->createTeammate('tm-gone', 'team-remove-idempotent', 'Frank', AgentType::Architect);

        $team->addTeammate($teammate);
        $team->removeTeammate('tm-gone');
        $team->removeTeammate('tm-gone'); // second call must not throw

        $this->assertCount(0, $team->getTeammates());
    }

    public function testRemoveTeammateFromEmptyTeamIsIdempotent(): void
    {
        $team = $this->createTeam('team-remove-empty');

        // Must not throw
        $team->removeTeammate('nonexistent');

        $this->assertCount(0, $team->getTeammates());
    }

    public function testRemoveTeammateOnlyRemovesSpecifiedId(): void
    {
        $team = $this->createTeam('team-remove-selective');

        $alice = $this->createTeammate('tm-alice', 'team-remove-selective', 'Alice', AgentType::Coder);
        $bob = $this->createTeammate('tm-bob', 'team-remove-selective', 'Bob', AgentType::Reviewer);

        $team->addTeammate($alice);
        $team->addTeammate($bob);

        $team->removeTeammate('tm-alice');

        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('tm-bob', $team->getTeammates()[0]->id);
    }

    // -------------------------------------------------------------------------
    // getTaskList()
    // -------------------------------------------------------------------------

    public function testGetTaskListReturnsTaskListInstance(): void
    {
        $team = new Team(
            id: 'team-tasklist-' . uniqid(),
            name: 'Task List Test',
            leadAgentId: 'lead-tasklist',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertInstanceOf(TaskList::class, $team->getTaskList());
    }

    public function testGetTaskListReturnsSameInstance(): void
    {
        $team = new Team(
            id: 'team-tasklist-same-' . uniqid(),
            name: 'Task List Same Test',
            leadAgentId: 'lead-tasklist-same',
            createdAt: new \DateTimeImmutable(),
        );

        $first = $team->getTaskList();
        $second = $team->getTaskList();

        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // getMailbox()
    // -------------------------------------------------------------------------

    public function testGetMailboxReturnsMailboxInstance(): void
    {
        $team = new Team(
            id: 'team-mailbox-' . uniqid(),
            name: 'Mailbox Test',
            leadAgentId: 'lead-mailbox',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertInstanceOf(Mailbox::class, $team->getMailbox());
    }

    public function testGetMailboxReturnsSameInstance(): void
    {
        $team = new Team(
            id: 'team-mailbox-same-' . uniqid(),
            name: 'Mailbox Same Test',
            leadAgentId: 'lead-mailbox-same',
            createdAt: new \DateTimeImmutable(),
        );

        $first = $team->getMailbox();
        $second = $team->getMailbox();

        $this->assertSame($first, $second);
    }

    // -------------------------------------------------------------------------
    // Path traversal guard
    // -------------------------------------------------------------------------

    public function testTeamIdWithPathTraversalThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal');

        new Team(
            id: '../etc/passwd',
            name: 'Bad Team',
            leadAgentId: 'lead-bad',
            createdAt: new \DateTimeImmutable(),
        );
    }

    public function testTeamIdWithDoubleDotsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal');

        new Team(
            id: 'team/../../secrets',
            name: 'Evil Team',
            leadAgentId: 'lead-evil',
            createdAt: new \DateTimeImmutable(),
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTeam(string $id): Team
    {
        return new Team(
            id: $id,
            name: "Team {$id}",
            leadAgentId: "lead-{$id}",
            createdAt: new \DateTimeImmutable(),
        );
    }

    private function createTeammate(
        string $id,
        string $teamId,
        string $name,
        AgentType $type,
    ): Teammate {
        return new Teammate(
            id: $id,
            teamId: $teamId,
            name: $name,
            type: $type,
            model: 'claude-sonnet-4-6',
            tools: ['Read', 'Edit', 'Bash'],
        );
    }
}
