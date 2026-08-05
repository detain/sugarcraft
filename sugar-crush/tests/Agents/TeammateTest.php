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
            $inboxPath = $teammate->inboxPath();
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
            $inboxPath = $teammate->inboxPath();
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
    // status()
    // -------------------------------------------------------------------------

    public function testStatusReturnsIdleByDefault(): void
    {
        $teammate = new Teammate(
            id: 'teammate-status',
            teamId: 'team-status',
            name: 'status-check',
            type: AgentType::Coder,
            model: 'test-model',
            tools: [],
        );

        $this->assertSame(TeammateStatus::Idle, $teammate->status());
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
}
