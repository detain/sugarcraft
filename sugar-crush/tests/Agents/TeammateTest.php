<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentType;
use SugarCraft\Crush\Agents\Teammate;
use SugarCraft\Crush\Agents\TeammateStatus;

/**
 * Tests for Teammate - entity representing a teammate agent within a team.
 */
final class TeammateTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Construction and property access
    // -------------------------------------------------------------------------

    public function testConstructionWithAllFields(): void
    {
        $teammate = new Teammate(
            id: 'teammate-42',
            teamId: 'team-alpha',
            name: 'alice',
            type: AgentType::Coder,
            model: 'claude-sonnet-4-6',
            tools: ['Read', 'Edit', 'Bash'],
            worktreePath: '/home/user/.sugar-crush/worktrees/teammate-42',
            branch: 'agent-teammate-42-20260101',
        );

        $this->assertSame('teammate-42', $teammate->id);
        $this->assertSame('team-alpha', $teammate->teamId);
        $this->assertSame('alice', $teammate->name);
        $this->assertSame(AgentType::Coder, $teammate->type);
        $this->assertSame('claude-sonnet-4-6', $teammate->model);
        $this->assertSame(['Read', 'Edit', 'Bash'], $teammate->tools);
        $this->assertSame('/home/user/.sugar-crush/worktrees/teammate-42', $teammate->worktreePath);
        $this->assertSame('agent-teammate-42-20260101', $teammate->branch);
    }

    public function testConstructionWithRequiredFieldsOnly(): void
    {
        $teammate = new Teammate(
            id: 'teammate-99',
            teamId: 'team-beta',
            name: 'bob',
            type: AgentType::Reviewer,
            model: 'claude-haiku',
            tools: ['Read', 'Grep'],
        );

        $this->assertSame('teammate-99', $teammate->id);
        $this->assertSame('team-beta', $teammate->teamId);
        $this->assertSame('bob', $teammate->name);
        $this->assertSame(AgentType::Reviewer, $teammate->type);
        $this->assertSame('claude-haiku', $teammate->model);
        $this->assertSame(['Read', 'Grep'], $teammate->tools);
        $this->assertNull($teammate->worktreePath);
        $this->assertNull($teammate->branch);
    }

    public function testConstructionWithAllAgentTypes(): void
    {
        $teamId = 'team-gamma';
        $createdAt = new \DateTimeImmutable();

        $types = [
            AgentType::Coder,
            AgentType::Reviewer,
            AgentType::Debugger,
            AgentType::Architect,
            AgentType::Tester,
            AgentType::Devops,
        ];

        foreach ($types as $i => $type) {
            $teammate = new Teammate(
                id: "teammate-type-{$i}",
                teamId: $teamId,
                name: "test-{$type->value}",
                type: $type,
                model: 'test-model',
                tools: [],
            );

            $this->assertSame($type, $teammate->type);
        }
    }

    public function testAgentTypeEnumValues(): void
    {
        $this->assertSame('coder', AgentType::Coder->value);
        $this->assertSame('reviewer', AgentType::Reviewer->value);
        $this->assertSame('debugger', AgentType::Debugger->value);
        $this->assertSame('architect', AgentType::Architect->value);
        $this->assertSame('tester', AgentType::Tester->value);
        $this->assertSame('devops', AgentType::Devops->value);
    }

    // -------------------------------------------------------------------------
    // inboxPath()
    // -------------------------------------------------------------------------

    public function testInboxPathReturnsCorrectFormat(): void
    {
        $teammate = new Teammate(
            id: 'teammate-1',
            teamId: 'team-test',
            name: 'tester',
            type: AgentType::Tester,
            model: 'test-model',
            tools: [],
        );

        // Backup and override HOME for consistent testing
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = '/home/testuser';

        try {
            $inboxPath = $teammate->getInboxPath();
            $this->assertSame('/home/testuser/.sugar-crush/teams/team-test/inboxes/teammate-1', $inboxPath);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testInboxPathWithDifferentTeamAndId(): void
    {
        $teammate = new Teammate(
            id: 'alice-007',
            teamId: 'project-x',
            name: 'alice',
            type: AgentType::Coder,
            model: 'claude-sonnet-4-6',
            tools: ['Read', 'Edit'],
        );

        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = '/tmp';

        try {
            $inboxPath = $teammate->getInboxPath();
            $this->assertSame('/tmp/.sugar-crush/teams/project-x/inboxes/alice-007', $inboxPath);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    // -------------------------------------------------------------------------
    // getInboxPath()
    // -------------------------------------------------------------------------

    public function testGetInboxPathReturnsCorrectFormat(): void
    {
        $teammate = new Teammate(
            id: 'teammate-get-1',
            teamId: 'team-get-test',
            name: 'get-inbox-tester',
            type: AgentType::Tester,
            model: 'test-model',
            tools: [],
        );

        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = '/home/testuser';

        try {
            $inboxPath = $teammate->getInboxPath();
            $this->assertSame('/home/testuser/.sugar-crush/teams/team-get-test/inboxes/teammate-get-1', $inboxPath);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    public function testGetInboxPathRejectsPathTraversal(): void
    {
        // Teammate with literal ".." in IDs would be rejected at construction
        // or when building the path - either way it must not produce a traversal
        $teammate = new Teammate(
            id: 'teammate-safe',
            teamId: 'team-safe',
            name: 'safe-tester',
            type: AgentType::Coder,
            model: 'test-model',
            tools: [],
        );

        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = '/home/safeuser';

        try {
            $path = $teammate->getInboxPath();
            $this->assertStringNotContainsString('..', $path);
            $this->assertStringStartsWith('/home/safeuser/.sugar-crush/teams/', $path);
        } finally {
            if ($originalHome !== null) {
                $_SERVER['HOME'] = $originalHome;
            } else {
                unset($_SERVER['HOME']);
            }
        }
    }

    // -------------------------------------------------------------------------
    // getStatus()
    // -------------------------------------------------------------------------

    public function testGetStatusReturnsIdleByDefault(): void
    {
        $teammate = new Teammate(
            id: 'teammate-status',
            teamId: 'team-status',
            name: 'status-check',
            type: AgentType::Coder,
            model: 'test-model',
            tools: [],
        );

        $this->assertSame(TeammateStatus::Idle, $teammate->getStatus());
    }

    // -------------------------------------------------------------------------
    // Immutability
    // -------------------------------------------------------------------------

    public function testInstancesAreIndependent(): void
    {
        $teammate1 = new Teammate(
            id: 'teammate-a',
            teamId: 'team-1',
            name: 'alice',
            type: AgentType::Coder,
            model: 'model-a',
            tools: ['Read'],
        );

        $teammate2 = new Teammate(
            id: 'teammate-b',
            teamId: 'team-2',
            name: 'bob',
            type: AgentType::Reviewer,
            model: 'model-b',
            tools: ['Edit'],
        );

        // Verify they are different objects with different properties
        $this->assertNotSame($teammate1, $teammate2);
        $this->assertSame('teammate-a', $teammate1->id);
        $this->assertSame('teammate-b', $teammate2->id);
        $this->assertSame('alice', $teammate1->name);
        $this->assertSame('bob', $teammate2->name);
    }

    public function testToolsArrayIsStoredDirectly(): void
    {
        $tools = ['Read', 'Edit', 'Bash', 'Glob'];

        $teammate = new Teammate(
            id: 'teammate-tools',
            teamId: 'team-tools',
            name: 'tool-tester',
            type: AgentType::Devops,
            model: 'test-model',
            tools: $tools,
        );

        $this->assertSame($tools, $teammate->tools);
    }

    public function testEmptyToolsArrayIsAllowed(): void
    {
        $teammate = new Teammate(
            id: 'teammate-empty',
            teamId: 'team-empty',
            name: 'empty-tools',
            type: AgentType::Architect,
            model: 'test-model',
            tools: [],
        );

        $this->assertSame([], $teammate->tools);
    }

    // -------------------------------------------------------------------------
    // withWorktreePath() - immutable fluent setter for worktree association
    // -------------------------------------------------------------------------

    public function testWithWorktreePathReturnsNewInstance(): void
    {
        $teammate = new Teammate(
            id: 'teammate-wt',
            teamId: 'team-wt',
            name: 'worktree-test',
            type: AgentType::Coder,
            model: 'test-model',
            tools: ['Read', 'Edit'],
        );

        $this->assertNull($teammate->worktreePath);

        $newTeammate = $teammate->withWorktreePath('/home/user/.sugar-crush/worktrees/teammate-wt');

        // Original is unchanged (immutability)
        $this->assertNull($teammate->worktreePath);
        // New instance has the worktree path
        $this->assertSame('/home/user/.sugar-crush/worktrees/teammate-wt', $newTeammate->worktreePath);
        // Other properties preserved
        $this->assertSame('teammate-wt', $newTeammate->id);
        $this->assertSame('team-wt', $newTeammate->teamId);
        $this->assertSame('worktree-test', $newTeammate->name);
        $this->assertSame(AgentType::Coder, $newTeammate->type);
        $this->assertSame(['Read', 'Edit'], $newTeammate->tools);
    }

    public function testWithWorktreePathOverwritesExistingPath(): void
    {
        $teammate = new Teammate(
            id: 'teammate-wt2',
            teamId: 'team-wt2',
            name: 'worktree-overwrite',
            type: AgentType::Coder,
            model: 'test-model',
            tools: [],
            worktreePath: '/old/worktree/path',
            branch: 'old-branch',
        );

        $newTeammate = $teammate->withWorktreePath('/new/worktree/path');

        $this->assertSame('/old/worktree/path', $teammate->worktreePath);
        $this->assertSame('/new/worktree/path', $newTeammate->worktreePath);
        $this->assertSame('old-branch', $newTeammate->branch);
    }

    public function testWithWorktreePathPreservesBranch(): void
    {
        $teammate = new Teammate(
            id: 'teammate-branch',
            teamId: 'team-branch',
            name: 'branch-test',
            type: AgentType::Reviewer,
            model: 'test-model',
            tools: [],
            branch: 'agent-teammate-branch-20260101',
        );

        $newTeammate = $teammate->withWorktreePath('/sugar-crush/worktrees/teammate-branch');

        $this->assertNull($teammate->worktreePath);
        $this->assertSame('/sugar-crush/worktrees/teammate-branch', $newTeammate->worktreePath);
        $this->assertSame('agent-teammate-branch-20260101', $newTeammate->branch);
    }

    public function testWithWorktreePathInstancesAreDistinct(): void
    {
        $teammate = new Teammate(
            id: 'teammate-distinct',
            teamId: 'team-distinct',
            name: 'distinct-test',
            type: AgentType::Coder,
            model: 'test-model',
            tools: [],
        );

        $newTeammate = $teammate->withWorktreePath('/path/a');
        $newTeammate2 = $teammate->withWorktreePath('/path/b');

        $this->assertNotSame($teammate, $newTeammate);
        $this->assertNotSame($teammate, $newTeammate2);
        $this->assertNotSame($newTeammate, $newTeammate2);
        $this->assertSame('/path/a', $newTeammate->worktreePath);
        $this->assertSame('/path/b', $newTeammate2->worktreePath);
    }
}
