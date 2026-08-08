<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Task;
use SugarCraft\Crush\Agents\TaskStatus;
use SugarCraft\Crush\Agents\Team;
use SugarCraft\Crush\Agents\TeamConfig;
use SugarCraft\Crush\Agents\TeamManager;

/**
 * Tests for TeamManager - creates and manages Team aggregate roots.
 */
final class TeamManagerTest extends TestCase
{
    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    public function testCreateTeam(): void
    {
        $tm = $this->createTeamManager();
        $team = $tm->createTeam(
            teamId: 'test-team',
            name: 'Test Team',
            leadAgentId: 'lead-1',
        );

        $this->assertInstanceOf(Team::class, $team);
        $this->assertSame('test-team', $team->id);
        $this->assertSame('Test Team', $team->name);
        $this->assertSame('lead-1', $team->leadAgentId);
        $this->assertNotNull($team->createdAt);
    }

    public function testCreateTeamDuplicateThrows(): void
    {
        $tm = $this->createTeamManager();
        $tm->createTeam(
            teamId: 'dup-team',
            name: 'First Team',
            leadAgentId: 'lead-1',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Team "dup-team" already exists.');

        $tm->createTeam(
            teamId: 'dup-team',
            name: 'Second Team',
            leadAgentId: 'lead-2',
        );
    }

    public function testCreateTeamPathTraversalBlocked(): void
    {
        $tm = $this->createTeamManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain path traversal sequences or slashes');

        $tm->createTeam(
            teamId: '../etc/passwd',
            name: 'Bad Team',
            leadAgentId: 'lead-1',
        );
    }

    public function testCreateTeamSlashBlocked(): void
    {
        $tm = $this->createTeamManager();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not contain path traversal sequences or slashes');

        $tm->createTeam(
            teamId: 'team/slash',
            name: 'Bad Team',
            leadAgentId: 'lead-1',
        );
    }

    public function testCreateTeamPassesMaxTeammatesThroughToTeam(): void
    {
        $tm = $this->createTeamManager();
        $team = $tm->createTeam(
            teamId: 'capped-team',
            name: 'Capped Team',
            leadAgentId: 'lead-1',
            config: new TeamConfig(maxTeammates: 2),
        );

        $this->assertSame(2, $team->maxTeammates);
    }

    // -------------------------------------------------------------------------
    // Registry accessors
    // -------------------------------------------------------------------------

    public function testGetTeamsReturnsAllTeams(): void
    {
        $tm = $this->createTeamManager();
        $tm->createTeam(teamId: 'team-1', name: 'Team 1', leadAgentId: 'lead-1');
        $tm->createTeam(teamId: 'team-2', name: 'Team 2', leadAgentId: 'lead-2');

        $teams = $tm->getTeams();

        $this->assertCount(2, $teams);
        $this->assertSame('team-1', $teams[0]->id);
        $this->assertSame('team-2', $teams[1]->id);
    }

    public function testGetTeamReturnsTeam(): void
    {
        $tm = $this->createTeamManager();
        $created = $tm->createTeam(
            teamId: 'get-team',
            name: 'Get Me',
            leadAgentId: 'lead-1',
        );

        $retrieved = $tm->getTeam('get-team');

        $this->assertNotNull($retrieved);
        $this->assertSame($created->id, $retrieved->id);
        $this->assertSame($created->name, $retrieved->name);
    }

    public function testGetTeamReturnsNullForNonexistent(): void
    {
        $tm = $this->createTeamManager();

        $result = $tm->getTeam('nonexistent');

        $this->assertNull($result);
    }

    public function testHasTeamTrueFalse(): void
    {
        $tm = $this->createTeamManager();
        $tm->createTeam(teamId: 'exists', name: 'Exists', leadAgentId: 'lead-1');

        $this->assertTrue($tm->hasTeam('exists'));
        $this->assertFalse($tm->hasTeam('does-not-exist'));
    }

    // -------------------------------------------------------------------------
    // handleTeammateIdle() — real consumer for TaskList::dispatchTeammateIdle()
    // -------------------------------------------------------------------------

    public function testHandleTeammateIdleClaimsNextUnblockedTask(): void
    {
        // Team::basePath() persists tasks.sqlite under the REAL $HOME
        // (~/.sugar-crush/teams/{id}/), independent of the TeamManager's own
        // temp registry dir — so the team id must be unique per test run to
        // avoid state bleeding across repeated invocations of this suite.
        $teamId = 'idle-team-' . uniqid('', true);
        $tm = $this->createTeamManager();
        $team = $tm->createTeam(teamId: $teamId, name: 'Idle Team', leadAgentId: 'lead-1');

        $task = new Task(
            id: 'idle-task-1',
            teamId: $teamId,
            title: 'Pending work',
            description: '',
            prompt: '',
            assignedTo: null,
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: null,
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $team->getTaskList()->addTask($task);

        // Before the fix, TaskList::dispatchTeammateIdle() had no caller at all,
        // so a teammate going idle never resulted in real reassignment.
        $claimedTaskId = $tm->handleTeammateIdle($teamId, 'teammate-idle-1');

        $this->assertSame('idle-task-1', $claimedTaskId);

        $reloadedTask = $team->getTaskList()->getTask('idle-task-1');
        $this->assertNotNull($reloadedTask);
        $this->assertSame(TaskStatus::InProgress, $reloadedTask->status);
        $this->assertSame('teammate-idle-1', $reloadedTask->assignedTo);
    }

    public function testHandleTeammateIdleReturnsNullWhenNoUnblockedTasks(): void
    {
        $teamId = 'idle-empty-team-' . uniqid('', true);
        $tm = $this->createTeamManager();
        $tm->createTeam(teamId: $teamId, name: 'Idle Empty Team', leadAgentId: 'lead-1');

        $result = $tm->handleTeammateIdle($teamId, 'teammate-idle-2');

        $this->assertNull($result);
    }

    public function testHandleTeammateIdleReturnsNullForNonexistentTeam(): void
    {
        $tm = $this->createTeamManager();

        $result = $tm->handleTeammateIdle('nonexistent-team-' . uniqid('', true), 'teammate-idle-3');

        $this->assertNull($result);
    }

    public function testHandleTeammateIdleSkipsTasksAssignedToOtherTeammates(): void
    {
        $teamId = 'idle-assigned-team-' . uniqid('', true);
        $tm = $this->createTeamManager();
        $team = $tm->createTeam(teamId: $teamId, name: 'Idle Assigned Team', leadAgentId: 'lead-1');

        $task = new Task(
            id: 'idle-task-assigned',
            teamId: $teamId,
            title: 'Someone else\'s work',
            description: '',
            prompt: '',
            assignedTo: 'someone-else',
            status: TaskStatus::Pending,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            claimedAt: null,
            completedAt: null,
            dependsOn: [],
            isContested: false,
        );
        $team->getTaskList()->addTask($task);

        $result = $tm->handleTeammateIdle($teamId, 'teammate-idle-4');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Lifecycle
    // -------------------------------------------------------------------------

    public function testRemoveTeamReturnsAndUnregisters(): void
    {
        $tm = $this->createTeamManager();
        $created = $tm->createTeam(
            teamId: 'remove-me',
            name: 'Remove Me',
            leadAgentId: 'lead-1',
        );

        $removed = $tm->removeTeam('remove-me');

        $this->assertNotNull($removed);
        $this->assertSame($created->id, $removed->id);
        $this->assertFalse($tm->hasTeam('remove-me'));
        $this->assertNull($tm->getTeam('remove-me'));
    }

    public function testRemoveTeamReturnsNullForNonexistent(): void
    {
        $tm = $this->createTeamManager();

        $result = $tm->removeTeam('nonexistent');

        $this->assertNull($result);
    }

    public function testTeamCount(): void
    {
        $tm = $this->createTeamManager();
        $this->assertSame(0, $tm->teamCount());

        $tm->createTeam(teamId: 'team-1', name: 'Team 1', leadAgentId: 'lead-1');
        $tm->createTeam(teamId: 'team-2', name: 'Team 2', leadAgentId: 'lead-2');
        $tm->createTeam(teamId: 'team-3', name: 'Team 3', leadAgentId: 'lead-3');
        $this->assertSame(3, $tm->teamCount());

        $tm->removeTeam('team-2');
        $this->assertSame(2, $tm->teamCount());
    }

    public function testReloadTeamRehydrates(): void
    {
        // Ensure same temp dir is used for both managers
        $this->tempDir = $this->createTempDir();
        $tm = new TeamManager($this->tempDir . '/teams');
        $tm->createTeam(
            teamId: 'reload-team',
            name: 'Reload Team',
            leadAgentId: 'lead-1',
        );

        // Create a new manager to simulate session resume (same path)
        $tm2 = new TeamManager($this->tempDir . '/teams');
        $reloaded = $tm2->reloadTeam('reload-team');

        $this->assertNotNull($reloaded);
        $this->assertSame('reload-team', $reloaded->id);
        $this->assertSame('Reload Team', $reloaded->name);
        $this->assertSame('lead-1', $reloaded->leadAgentId);
    }

    public function testReloadTeamReturnsNullForNonexistent(): void
    {
        $tm = $this->createTeamManager();

        $result = $tm->reloadTeam('nonexistent');

        $this->assertNull($result);
    }

    public function testGetTeamConfig(): void
    {
        $config = new TeamConfig(
            maxTeammates: 10,
            defaultTimeoutSeconds: 300,
            allowPeerMessaging: false,
            autoAssignTasks: false,
            inboxPath: '~/my/inbox',
        );

        $tm = $this->createTeamManager();
        $tm->createTeam(
            teamId: 'config-team',
            name: 'Config Team',
            leadAgentId: 'lead-1',
            config: $config,
        );

        $retrievedConfig = $tm->getTeamConfig('config-team');

        $this->assertNotNull($retrievedConfig);
        $this->assertSame(10, $retrievedConfig->maxTeammates);
        $this->assertSame(300, $retrievedConfig->defaultTimeoutSeconds);
        $this->assertFalse($retrievedConfig->allowPeerMessaging);
        $this->assertFalse($retrievedConfig->autoAssignTasks);
        $this->assertSame('~/my/inbox', $retrievedConfig->inboxPath);
    }

    public function testGetTeamConfigReturnsNullForNonexistent(): void
    {
        $tm = $this->createTeamManager();

        $result = $tm->getTeamConfig('nonexistent');

        $this->assertNull($result);
    }

    // -------------------------------------------------------------------------
    // Persistence
    // -------------------------------------------------------------------------

    public function testRegistryPersistsToDisk(): void
    {
        $tempDir = $this->createTempDir();

        // Create team with first manager
        $tm1 = new TeamManager($tempDir . '/teams');
        $tm1->createTeam(
            teamId: 'persist-team',
            name: 'Persisted Team',
            leadAgentId: 'lead-1',
        );

        // Create new manager with same path - should load existing team
        $tm2 = new TeamManager($tempDir . '/teams');
        $this->assertTrue($tm2->hasTeam('persist-team'));

        $team = $tm2->getTeam('persist-team');
        $this->assertNotNull($team);
        $this->assertSame('persist-team', $team->id);
        $this->assertSame('Persisted Team', $team->name);
    }

    public function testExpandPathExpandsTilde(): void
    {
        $tm = $this->createTeamManager();
        $team = $tm->createTeam(
            teamId: 'tilde-test',
            name: 'Tilde Test',
            leadAgentId: 'lead-1',
        );

        $this->assertNotNull($team);
        $this->assertSame('tilde-test', $team->id);
    }

    public function testExpandPathWithNestedDirs(): void
    {
        // Ensure temp dir is initialized first
        $this->tempDir = $this->createTempDir();
        // Create a manager with a base path under temp dir (simulates nested structure)
        $tm = $this->createTeamManager($this->tempDir . '/some/nested/path');
        $team = $tm->createTeam(
            teamId: 'nested-test',
            name: 'Nested Test',
            leadAgentId: 'lead-1',
        );

        $this->assertNotNull($team);
        $this->assertSame('nested-test', $team->id);
    }

    public function testExpandPathBlocksPathTraversal(): void
    {
        $tm = $this->createTeamManager();

        // Use reflection to call private expandPath method
        $reflection = new \ReflectionClass($tm);
        $method = $reflection->getMethod('expandPath');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path must not contain ".."');

        $method->invoke($tm, '../etc/passwd');
    }

    public function testExpandPathBlocksDotDot(): void
    {
        $tm = $this->createTeamManager();

        $reflection = new \ReflectionClass($tm);
        $method = $reflection->getMethod('expandPath');
        $method->setAccessible(true);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Path must not contain "..": ');

        $method->invoke($tm, 'foo/../bar');
    }

    public function testExpandPathAcceptsAbsolutePaths(): void
    {
        $tm = $this->createTeamManager();

        $reflection = new \ReflectionClass($tm);
        $method = $reflection->getMethod('expandPath');
        $method->setAccessible(true);

        $result = $method->invoke($tm, '/tmp/some/path');

        $this->assertSame('/tmp/some/path', $result);
    }

    public function testSaveRegistryThrowsOnWriteFailure(): void
    {
        $this->tempDir = $this->createTempDir();
        $tm = new TeamManager($this->tempDir . '/teams');
        $tm->createTeam(
            teamId: 'write-test',
            name: 'Write Test',
            leadAgentId: 'lead-1',
        );

        // Make the registry file read-only so subsequent saves fail
        $registryFile = $this->tempDir . '/teams/registry.json';
        chmod($registryFile, 0444);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to write registry');

        // This should fail because the registry file is read-only
        $tm->createTeam(
            teamId: 'write-test-2',
            name: 'Write Test 2',
            leadAgentId: 'lead-1',
        );
    }

    // -------------------------------------------------------------------------
    // Helper methods
    // -------------------------------------------------------------------------

    private function createTeamManager(string $basePath = null): TeamManager
    {
        if ($basePath === null) {
            $this->tempDir = $this->createTempDir();
            $basePath = $this->tempDir . '/teams';
        }
        return new TeamManager($basePath);
    }

    private function createTempDir(): string
    {
        $tempDir = sys_get_temp_dir() . '/sugar_crush_test_' . uniqid('', true);
        mkdir($tempDir, 0755, true);
        return $tempDir;
    }

    private function removeDirectory(string $dir): void
    {
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }
}
