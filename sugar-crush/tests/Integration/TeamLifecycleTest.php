<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentType;
use SugarCraft\Crush\Agents\Mailbox;
use SugarCraft\Crush\Agents\Task;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Agents\TaskStatus;
use SugarCraft\Crush\Agents\Team;
use SugarCraft\Crush\Agents\TeamMessage;
use SugarCraft\Crush\Agents\TeamConfig;
use SugarCraft\Crush\Agents\TeamManager;
use SugarCraft\Crush\Agents\Teammate;

/**
 * Integration tests for team lifecycle: creation, task assignment,
 * completion, and cleanup across Team, TaskList, and Mailbox.
 */
final class TeamLifecycleTest extends TestCase
{
    private string $tempDir;
    private string $teamManagerBasePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir() . '/sugar-crush-test-' . uniqid('', true);
        $this->teamManagerBasePath = $this->tempDir . '/teams';

        mkdir($this->teamManagerBasePath, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * testCreateTeamWith3Teammates: create team, add 3 teammates,
     * verify getTeammates() returns all 3.
     */
    public function testCreateTeamWith3Teammates(): void
    {
        $manager = new TeamManager($this->teamManagerBasePath);

        $team = $manager->createTeam(
            teamId: 'team-alpha',
            name: 'Alpha Team',
            leadAgentId: 'lead-1',
        );

        $teammate1 = new Teammate(
            id: 'tm-1',
            teamId: 'team-alpha',
            name: 'Alice',
            type: AgentType::Coder,
            model: 'claude-sonnet-4-6',
            tools: ['read', 'write', 'edit'],
        );

        $teammate2 = new Teammate(
            id: 'tm-2',
            teamId: 'team-alpha',
            name: 'Bob',
            type: AgentType::Reviewer,
            model: 'claude-sonnet-4-6',
            tools: ['read', 'grep'],
        );

        $teammate3 = new Teammate(
            id: 'tm-3',
            teamId: 'team-alpha',
            name: 'Carol',
            type: AgentType::Tester,
            model: 'claude-sonnet-4-6',
            tools: ['bash'],
        );

        $team->addTeammate($teammate1);
        $team->addTeammate($teammate2);
        $team->addTeammate($teammate3);

        $teammates = $team->getTeammates();

        self::assertCount(3, $teammates);
        self::assertSame('tm-1', $teammates[0]->id);
        self::assertSame('tm-2', $teammates[1]->id);
        self::assertSame('tm-3', $teammates[2]->id);
    }

    /**
     * testAddAndClaimTasks: add 3 tasks, claim one per teammate,
     * verify atomic claim works (first claimer wins).
     */
    public function testAddAndClaimTasks(): void
    {
        $dbPath = $this->tempDir . '/tasks.sqlite';
        $taskList = new TaskList($dbPath);

        $now = new \DateTimeImmutable();

        $task1 = new Task(
            id: 'task-1',
            teamId: 'team-beta',
            title: 'Implement feature X',
            description: 'Build the feature',
            prompt: 'Implement feature X',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $now,
        );

        $task2 = new Task(
            id: 'task-2',
            teamId: 'team-beta',
            title: 'Review feature X',
            description: 'Review the implementation',
            prompt: 'Review feature X',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $now,
        );

        $task3 = new Task(
            id: 'task-3',
            teamId: 'team-beta',
            title: 'Test feature X',
            description: 'Write tests',
            prompt: 'Test feature X',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $now,
        );

        $taskList->addTask($task1);
        $taskList->addTask($task2);
        $taskList->addTask($task3);

        // First teammate claims task-1
        $claimed1 = $taskList->claimTask('task-1', 'tm-1');
        self::assertTrue($claimed1);

        // Second teammate tries to claim task-1 (already claimed)
        $claimed2 = $taskList->claimTask('task-1', 'tm-2');
        self::assertFalse($claimed2);

        // Verify task-1 is now InProgress and assigned to tm-1
        $retrievedTask1 = $taskList->getTask('task-1');
        self::assertNotNull($retrievedTask1);
        self::assertSame(TaskStatus::InProgress, $retrievedTask1->status);
        self::assertSame('tm-1', $retrievedTask1->assignedTo);

        // tm-2 claims task-2
        $claimed3 = $taskList->claimTask('task-2', 'tm-2');
        self::assertTrue($claimed3);

        $retrievedTask2 = $taskList->getTask('task-2');
        self::assertNotNull($retrievedTask2);
        self::assertSame(TaskStatus::InProgress, $retrievedTask2->status);
        self::assertSame('tm-2', $retrievedTask2->assignedTo);

        // tm-3 claims task-3
        $claimed4 = $taskList->claimTask('task-3', 'tm-3');
        self::assertTrue($claimed4);

        $retrievedTask3 = $taskList->getTask('task-3');
        self::assertNotNull($retrievedTask3);
        self::assertSame(TaskStatus::InProgress, $retrievedTask3->status);
        self::assertSame('tm-3', $retrievedTask3->assignedTo);

        // Verify pending tasks are empty (all claimed)
        $pending = $taskList->getPendingTasks();
        self::assertCount(0, $pending);
    }

    /**
     * testCompleteTasks: teammates complete tasks,
     * verify status becomes TaskStatus::Completed with result.
     */
    public function testCompleteTasks(): void
    {
        $dbPath = $this->tempDir . '/tasks-complete.sqlite';
        $taskList = new TaskList($dbPath);

        $now = new \DateTimeImmutable();

        $task = new Task(
            id: 'task-complete-1',
            teamId: 'team-gamma',
            title: 'Build widget',
            description: 'Implement the widget',
            prompt: 'Build the widget',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $now,
        );

        $taskList->addTask($task);

        // Claim the task
        $claimed = $taskList->claimTask('task-complete-1', 'tm-1');
        self::assertTrue($claimed);

        // Complete the task
        $taskList->completeTask('task-complete-1', 'Widget built successfully');

        // Verify status and result
        $retrieved = $taskList->getTask('task-complete-1');
        self::assertNotNull($retrieved);
        self::assertSame(TaskStatus::Completed, $retrieved->status);
        self::assertSame('Widget built successfully', $retrieved->result);
        self::assertNotNull($retrieved->completedAt);
    }

    /**
     * testTaskResultsReturnedToLead: completed tasks have results
     * visible via getTask().
     */
    public function testTaskResultsReturnedToLead(): void
    {
        $dbPath = $this->tempDir . '/tasks-results.sqlite';
        $taskList = new TaskList($dbPath);

        $now = new \DateTimeImmutable();

        $task1 = new Task(
            id: 'task-result-1',
            teamId: 'team-delta',
            title: 'Write tests',
            description: 'Write unit tests',
            prompt: 'Write tests for the module',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $now,
        );

        $task2 = new Task(
            id: 'task-result-2',
            teamId: 'team-delta',
            title: 'Deploy',
            description: 'Deploy the module',
            prompt: 'Deploy to production',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $now,
        );

        $taskList->addTask($task1);
        $taskList->addTask($task2);

        // Both tasks claimed and completed
        $taskList->claimTask('task-result-1', 'tm-1');
        $taskList->completeTask('task-result-1', 'Tests written: 42 passing');

        $taskList->claimTask('task-result-2', 'tm-2');
        $taskList->completeTask('task-result-2', 'Deployed to production');

        // Lead retrieves results via getTask()
        $result1 = $taskList->getTask('task-result-1');
        self::assertNotNull($result1);
        self::assertSame('Tests written: 42 passing', $result1->result);
        self::assertSame(TaskStatus::Completed, $result1->status);

        $result2 = $taskList->getTask('task-result-2');
        self::assertNotNull($result2);
        self::assertSame('Deployed to production', $result2->result);
        self::assertSame(TaskStatus::Completed, $result2->status);
    }

    /**
     * testCleanupRemovesResources: removeTeam() removes team from registry,
     * team count goes to 0.
     */
    public function testCleanupRemovesResources(): void
    {
        $manager = new TeamManager($this->teamManagerBasePath);

        self::assertSame(0, $manager->teamCount());

        $team = $manager->createTeam(
            teamId: 'team-cleanup',
            name: 'Cleanup Team',
            leadAgentId: 'lead-cleanup',
        );

        self::assertSame(1, $manager->teamCount());
        self::assertTrue($manager->hasTeam('team-cleanup'));
        self::assertSame($team, $manager->getTeam('team-cleanup'));

        $removed = $manager->removeTeam('team-cleanup');

        self::assertNotNull($removed);
        self::assertSame('team-cleanup', $removed->id);
        self::assertSame(0, $manager->teamCount());
        self::assertFalse($manager->hasTeam('team-cleanup'));
        self::assertNull($manager->getTeam('team-cleanup'));
    }

    /**
     * testMultipleTeamsIndependent: create two teams with separate tasks,
     * verify they don't interfere.
     */
    public function testMultipleTeamsIndependent(): void
    {
        $manager = new TeamManager($this->teamManagerBasePath);

        // Create first team
        $team1 = $manager->createTeam(
            teamId: 'team-indep-1',
            name: 'Independent Team One',
            leadAgentId: 'lead-1',
        );

        $tm1 = new Teammate(
            id: 'tm-indep-1',
            teamId: 'team-indep-1',
            name: 'Dev1',
            type: AgentType::Coder,
            model: 'claude-sonnet-4-6',
            tools: ['read', 'write'],
        );
        $team1->addTeammate($tm1);

        // Create second team
        $team2 = $manager->createTeam(
            teamId: 'team-indep-2',
            name: 'Independent Team Two',
            leadAgentId: 'lead-2',
        );

        $tm2 = new Teammate(
            id: 'tm-indep-2',
            teamId: 'team-indep-2',
            name: 'Dev2',
            type: AgentType::Coder,
            model: 'claude-sonnet-4-6',
            tools: ['read', 'write'],
        );
        $team2->addTeammate($tm2);

        self::assertSame(2, $manager->teamCount());

        // Each team has its own TaskList
        $dbPath1 = $this->tempDir . '/team-indep-1/tasks.sqlite';
        $dbPath2 = $this->tempDir . '/team-indep-2/tasks.sqlite';

        mkdir(dirname($dbPath1), 0755, true);
        mkdir(dirname($dbPath2), 0755, true);

        $taskList1 = new TaskList($dbPath1);
        $taskList2 = new TaskList($dbPath2);

        $now = new \DateTimeImmutable();

        // Add task to team 1
        $task1 = new Task(
            id: 'task-team1-only',
            teamId: 'team-indep-1',
            title: 'Team 1 Task',
            description: 'Belongs to team 1',
            prompt: 'Do team 1 work',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $now,
        );
        $taskList1->addTask($task1);

        // Add task to team 2
        $task2 = new Task(
            id: 'task-team2-only',
            teamId: 'team-indep-2',
            title: 'Team 2 Task',
            description: 'Belongs to team 2',
            prompt: 'Do team 2 work',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: $now,
        );
        $taskList2->addTask($task2);

        // Claim and complete task in team 1
        $taskList1->claimTask('task-team1-only', 'tm-indep-1');
        $taskList1->completeTask('task-team1-only', 'Team 1 result');

        // Team 2 task should still be pending
        $team2Task = $taskList2->getTask('task-team2-only');
        self::assertNotNull($team2Task);
        self::assertSame(TaskStatus::Pending, $team2Task->status);

        // Team 1 task is completed with result
        $team1Task = $taskList1->getTask('task-team1-only');
        self::assertNotNull($team1Task);
        self::assertSame(TaskStatus::Completed, $team1Task->status);
        self::assertSame('Team 1 result', $team1Task->result);

        // Verify teammates are isolated
        self::assertCount(1, $team1->getTeammates());
        self::assertSame('tm-indep-1', $team1->getTeammates()[0]->id);

        self::assertCount(1, $team2->getTeammates());
        self::assertSame('tm-indep-2', $team2->getTeammates()[0]->id);

        // Verify teams are registered independently
        self::assertNotNull($manager->getTeam('team-indep-1'));
        self::assertNotNull($manager->getTeam('team-indep-2'));

        $manager->removeTeam('team-indep-1');
        self::assertSame(1, $manager->teamCount());
        self::assertNull($manager->getTeam('team-indep-1'));
        self::assertNotNull($manager->getTeam('team-indep-2'));

        $manager->removeTeam('team-indep-2');
        self::assertSame(0, $manager->teamCount());
    }

    /**
     * testMailboxSendAndReceiveRoundTrip: verify Mailbox::send() from one
     * teammate to another and Mailbox::receive() returns the identical message.
     *
     * This exercises the full team lifecycle requirement of send/receive
     * mailbox messages using real Team/Mailbox instances with a temp directory.
     */
    public function testMailboxSendAndReceiveRoundTrip(): void
    {
        // Team stores its mailbox under $_SERVER['HOME']/.sugar-crush/teams/{teamId}/mailbox
        // Override HOME so the mailbox is created under our temp directory.
        $originalHome = $_SERVER['HOME'] ?? null;
        $_SERVER['HOME'] = $this->tempDir;

        try {
            $manager = new TeamManager($this->teamManagerBasePath);

            $team = $manager->createTeam(
                teamId: 'team-mailbox',
                name: 'Mailbox Test Team',
                leadAgentId: 'lead-mb',
            );

            $tm1 = new Teammate(
                id: 'tm-mb-1',
                teamId: 'team-mailbox',
                name: 'Alice',
                type: AgentType::Coder,
                model: 'claude-sonnet-4-6',
                tools: ['read', 'write'],
            );

            $tm2 = new Teammate(
                id: 'tm-mb-2',
                teamId: 'team-mailbox',
                name: 'Bob',
                type: AgentType::Reviewer,
                model: 'claude-sonnet-4-6',
                tools: ['read', 'grep'],
            );

            $team->addTeammate($tm1);
            $team->addTeammate($tm2);

            $mailbox = $team->getMailbox();
            self::assertInstanceOf(Mailbox::class, $mailbox);

            $sentAt = new \DateTimeImmutable();
            $message = new TeamMessage(
                id: 'msg-001',
                fromTeammateId: 'tm-mb-1',
                toTeammateId: 'tm-mb-2',
                type: 'task_assigned',
                payload: ['taskId' => 'task-42', 'priority' => 'high'],
                sentAt: $sentAt,
            );

            // Send message from Alice (tm-mb-1) to Bob (tm-mb-2)
            $mailbox->send($message->fromTeammateId, $message->toTeammateId, $message);

            // Bob receives the message
            $received = iterator_to_array($mailbox->receive('tm-mb-2'));
            self::assertCount(1, $received);

            $receivedMsg = $received[0];
            self::assertSame('msg-001', $receivedMsg->id);
            self::assertSame('tm-mb-1', $receivedMsg->fromTeammateId);
            self::assertSame('tm-mb-2', $receivedMsg->toTeammateId);
            self::assertSame('task_assigned', $receivedMsg->type);
            self::assertSame(['taskId' => 'task-42', 'priority' => 'high'], $receivedMsg->payload);
            self::assertFalse($receivedMsg->read);

            // peek() also returns the message (without marking it read)
            $peeked = $mailbox->peek('tm-mb-2');
            self::assertCount(1, $peeked);
            self::assertSame('msg-001', $peeked[0]->id);

            // Mark as read and verify getUnreadCount drops to 0
            $mailbox->markRead('tm-mb-2', 'msg-001');
            self::assertSame(0, $mailbox->getUnreadCount('tm-mb-2'));

            // Alice's inbox should still be empty
            self::assertCount(0, $mailbox->peek('tm-mb-1'));
            self::assertSame(0, $mailbox->getUnreadCount('tm-mb-1'));
        } finally {
            if ($originalHome === null) {
                unset($_SERVER['HOME']);
            } else {
                $_SERVER['HOME'] = $originalHome;
            }
        }
    }

    /**
     * Recursively remove a directory and its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
