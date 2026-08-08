<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentType;
use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Agents\Teammate;
use SugarCraft\Crush\Agents\Team;
use SugarCraft\Crush\Agents\WorktreeConfig;
use SugarCraft\Crush\Agents\WorktreeManager;

/**
 * Tests for Team - aggregate root for lead + teammates coordination.
 */
final class TeamTest extends TestCase
{
    /** @var list<string> temp dirs created by createRealWorktreeManager(), cleaned up in tearDown() */
    private array $tmpDirsToClean = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirsToClean as $dir) {
            if (is_dir($dir)) {
                $this->removeDirectory($dir);
            }
        }
        parent::tearDown();
    }

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

    public function testDefaultMaxTeammatesIsFive(): void
    {
        $team = new Team(
            id: 'team-default-cap',
            name: 'Default Cap Team',
            leadAgentId: 'lead-default-cap',
            createdAt: new \DateTimeImmutable(),
        );

        $this->assertSame(5, $team->maxTeammates);
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
    // addTeammate() — maxTeammates cap (R6)
    // -------------------------------------------------------------------------

    public function testAddTeammateThrowsWhenAtMaxCapacity(): void
    {
        $team = $this->createTeam('team-capped', maxTeammates: 2);

        $team->addTeammate($this->createTeammate('tm-cap-1', 'team-capped', 'One', AgentType::Coder));
        $team->addTeammate($this->createTeammate('tm-cap-2', 'team-capped', 'Two', AgentType::Reviewer));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('team-capped');

        // Third teammate must be rejected — this is the original bug: without
        // enforcement, this call previously always succeeded regardless of maxTeammates.
        $team->addTeammate($this->createTeammate('tm-cap-3', 'team-capped', 'Three', AgentType::Tester));
    }

    public function testAddTeammateStaysAtCapacityAfterRejectedAdd(): void
    {
        $team = $this->createTeam('team-capped-count', maxTeammates: 1);
        $team->addTeammate($this->createTeammate('tm-only', 'team-capped-count', 'Only', AgentType::Coder));

        try {
            $team->addTeammate($this->createTeammate('tm-extra', 'team-capped-count', 'Extra', AgentType::Coder));
            $this->fail('Expected addTeammate() to throw once at capacity.');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('tm-only', $team->getTeammates()[0]->id);
    }

    public function testAddTeammateAllowsReplacementWhileAtCapacity(): void
    {
        $team = $this->createTeam('team-capped-replace', maxTeammates: 1);
        $team->addTeammate($this->createTeammate('tm-slot', 'team-capped-replace', 'First', AgentType::Coder));

        $replacement = new Teammate(
            id: 'tm-slot',
            teamId: 'team-capped-replace',
            name: 'Second',
            type: AgentType::Reviewer,
            model: 'claude-sonnet-4-6',
            tools: ['Read'],
        );

        // Re-adding the SAME id does not grow the team, so it must still be allowed.
        $team->addTeammate($replacement);

        $this->assertCount(1, $team->getTeammates());
        $this->assertSame('Second', $team->getTeammates()[0]->name);
    }

    public function testAddTeammateUpToDefaultCapacitySucceeds(): void
    {
        $team = $this->createTeam('team-default-fill');

        for ($i = 1; $i <= 5; $i++) {
            $team->addTeammate($this->createTeammate("tm-{$i}", 'team-default-fill', "Member {$i}", AgentType::Coder));
        }

        $this->assertCount(5, $team->getTeammates());

        $this->expectException(\RuntimeException::class);
        $team->addTeammate($this->createTeammate('tm-6', 'team-default-fill', 'Member 6', AgentType::Coder));
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
    // claimTask()
    // -------------------------------------------------------------------------

    public function testClaimTaskReturnsFalseWhenTeammateNotFound(): void
    {
        $team = $this->createTeam('team-claim-no-tm-' . uniqid());

        // No teammates added — claimTask must return false.
        $wm = $this->createRealWorktreeManager();

        $this->assertFalse($team->claimTask('task-1', 'nonexistent', $wm));
    }

    public function testClaimTaskReturnsFalseWhenTaskAlreadyClaimed(): void
    {
        $team = $this->createTeam('team-claim-taken-' . uniqid());

        $teammate = $this->createTeammate('tm-1', $team->id, 'Alice', AgentType::Coder);
        $team->addTeammate($teammate);

        // Pre-add a task that is already in-progress (simulating already-claimed)
        $task = new \SugarCraft\Crush\Agents\Task(
            id: 'task-taken-' . uniqid(),
            teamId: $team->id,
            title: 'Already Claimed',
            description: '',
            prompt: '',
            assignedTo: 'tm-other',
            status: \SugarCraft\Crush\Agents\TaskStatus::InProgress,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: new \DateTimeImmutable(),
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $team->getTaskList()->addTask($task);

        $wm = $this->createRealWorktreeManager();

        // The task is already claimed by someone else — must return false
        $this->assertFalse($team->claimTask($task->id, 'tm-1', $wm));
    }

    public function testClaimTaskReturnsTrueAndWiresWorktreePathOnSuccess(): void
    {
        $team = $this->createTeam('team-claim-ok-' . uniqid());

        $teammate = $this->createTeammate('tm-claim', $team->id, 'Bob', AgentType::Coder);
        $team->addTeammate($teammate);

        $taskId = 'task-ok-' . uniqid();
        $task = new \SugarCraft\Crush\Agents\Task(
            id: $taskId,
            teamId: $team->id,
            title: 'Good Task',
            description: '',
            prompt: '',
            assignedTo: null,
            status: \SugarCraft\Crush\Agents\TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: null,
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $team->getTaskList()->addTask($task);

        $wm = $this->createRealWorktreeManager();

        $result = $team->claimTask($taskId, 'tm-claim', $wm);

        $this->assertTrue($result);

        // Teammate's worktreePath must be updated to what createWorktree returned
        $updatedTeammate = $team->getTeammate('tm-claim');
        $this->assertNotNull($updatedTeammate);
        $this->assertNotNull($updatedTeammate->worktreePath);
        $this->assertStringEndsWith('/tm-claim', $updatedTeammate->worktreePath);
        $this->assertTrue(is_dir($updatedTeammate->worktreePath));
    }

    public function testClaimTaskRunsWorktreeSweep(): void
    {
        // Proxy repro for "sweepIfDue() has a real caller": claimTask() must
        // invoke WorktreeManager::sweepIfDue(), whose only observable side
        // effect is writing the .last-sweep throttle marker file.
        $team = $this->createTeam('team-claim-sweep-' . uniqid());

        $teammate = $this->createTeammate('tm-sweep', $team->id, 'Sweeper', AgentType::Coder);
        $team->addTeammate($teammate);

        $taskId = 'task-sweep-' . uniqid();
        $task = new \SugarCraft\Crush\Agents\Task(
            id: $taskId,
            teamId: $team->id,
            title: 'Sweep Task',
            description: '',
            prompt: '',
            assignedTo: null,
            status: \SugarCraft\Crush\Agents\TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: null,
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $team->getTaskList()->addTask($task);

        $wm = $this->createRealWorktreeManager();

        $reflection = new \ReflectionClass($wm);
        $expandedBasePathProp = $reflection->getProperty('expandedBasePath');
        $expandedBasePathProp->setAccessible(true);
        $marker = $expandedBasePathProp->getValue($wm) . '/.last-sweep';

        $this->assertFileDoesNotExist($marker);

        $team->claimTask($taskId, 'tm-sweep', $wm);

        $this->assertFileExists($marker, 'claimTask() must call WorktreeManager::sweepIfDue()');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private function createTeam(string $id, int $maxTeammates = 5): Team
    {
        return new Team(
            id: $id,
            name: "Team {$id}",
            leadAgentId: "lead-{$id}",
            createdAt: new \DateTimeImmutable(),
            maxTeammates: $maxTeammates,
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

    /**
     * Build a real WorktreeManager backed by a throwaway git repo, since
     * Team::claimTask() now requires the concrete WorktreeManager type
     * (previously an untyped `object`, which a hand-rolled test double could
     * satisfy without exercising any real git/worktree behavior).
     */
    private function createRealWorktreeManager(): WorktreeManager
    {
        $tmpRoot = sys_get_temp_dir() . '/sugar-crush-team-test-' . uniqid('', true);
        mkdir($tmpRoot, 0755, true);
        $this->tmpDirsToClean[] = $tmpRoot;

        $repoRoot = $tmpRoot . '/repo.git';
        shell_exec('git init --bare ' . escapeshellarg($repoRoot) . ' 2>&1');

        $repoRoot = $tmpRoot . '/repo';
        shell_exec('git clone ' . escapeshellarg($repoRoot) . ' ' . escapeshellarg($repoRoot) . ' 2>&1');

        $config = new WorktreeConfig(basePath: $tmpRoot . '/worktrees/');

        return new WorktreeManager($config, $repoRoot);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = array_diff(scandir($path) ?: [], ['.', '..']);
        foreach ($items as $item) {
            $itemPath = $path . '/' . $item;
            if (is_dir($itemPath)) {
                $this->removeDirectory($itemPath);
            } else {
                unlink($itemPath);
            }
        }
        rmdir($path);
    }
}
