<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Agents;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\Task;
use SugarCraft\Crush\Agents\TaskList;
use SugarCraft\Crush\Agents\TaskStatus;
use SugarCraft\Crush\Sessions\BackgroundSupervisor;
use SugarCraft\Crush\Tests\Support\ReapsForkedChildrenTrait;

/**
 * Roadmap 4.6-1: the team task list is safe to share before teams are wired.
 *
 * - every write bumps a `revision`, and claim/release are compare-and-swap on
 *   it, so a decision made on a stale read is refused rather than applied;
 * - dependencies stay acyclic (a cycle is a set of tasks none of which can
 *   ever be claimed);
 * - a claim whose process died is released back to pending.
 */
final class TaskListCasAndCycleTest extends TestCase
{
    use ReapsForkedChildrenTrait;

    private string $dbPath;

    protected function setUp(): void
    {
        $this->dbPath = sys_get_temp_dir() . '/tasklist_cas_' . uniqid((string) getmypid(), true) . '.sqlite3';
    }

    protected function tearDown(): void
    {
        $this->reapTrackedForkedChildren();
        foreach ([$this->dbPath, $this->dbPath . '-wal', $this->dbPath . '-shm'] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Revision + compare-and-swap
    // -------------------------------------------------------------------------

    public function testEveryWriteBumpsTheRevision(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('a'));
        $this->assertSame(0, $list->revision('a'));

        $list->addDependency('a', 'later');
        $this->assertSame(1, $list->revision('a'));

        $this->assertFalse($list->claimTask('a', 'mate'), 'blocked on a dependency that does not exist yet');
        $this->assertSame(1, $list->revision('a'), 'a refused claim writes nothing');

        $list->updateTaskStatus('a', TaskStatus::Pending);
        $this->assertSame(2, $list->revision('a'));

        $list->failTask('a', 'boom');
        $this->assertSame(3, $list->revision('a'));

        $this->assertNull($list->revision('missing'));
    }

    public function testAClaimOnAStaleRevisionIsRefused(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('t'));
        $seen = $list->revision('t');

        // Someone else moves the row after this caller looked.
        $list->updateTaskStatus('t', TaskStatus::Pending);

        $this->assertFalse($list->claimTask('t', 'mate', $seen));
        $this->assertSame(TaskStatus::Pending, $list->getTask('t')?->status);
        $this->assertNull($list->getTask('t')?->assignedTo);

        $this->assertTrue($list->claimTask('t', 'mate', $list->revision('t')), 'the current revision claims');
        $this->assertSame(TaskStatus::InProgress, $list->getTask('t')?->status);
    }

    public function testAReleaseOnAStaleRevisionIsRefused(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('r'));
        $this->assertTrue($list->claimTask('r', 'mate'));
        $seen = $list->revision('r');

        $list->updateTaskStatus('r', TaskStatus::InProgress);

        $this->assertFalse($list->releaseTask('r', 'mate', $seen));
        $this->assertSame('mate', $list->getTask('r')?->assignedTo);
        $this->assertTrue($list->releaseTask('r', 'mate'), 'without an expected revision the current one is used');
        $this->assertSame(TaskStatus::Pending, $list->getTask('r')?->status);
        $this->assertNull($list->getTask('r')?->assignedTo);
    }

    public function testAnOlderDatabaseIsMigratedInPlace(): void
    {
        $legacy = new \SQLite3($this->dbPath);
        $legacy->exec(<<<'SQL'
            CREATE TABLE tasks (
                id TEXT NOT NULL PRIMARY KEY, team_id TEXT NOT NULL, title TEXT NOT NULL,
                description TEXT NOT NULL, prompt TEXT NOT NULL, assigned_to TEXT,
                status TEXT NOT NULL DEFAULT 'pending', result TEXT, error TEXT,
                created_at TEXT NOT NULL, claimed_at TEXT, completed_at TEXT,
                depends_on TEXT NOT NULL DEFAULT '[]', contested INTEGER NOT NULL DEFAULT 0
            );
            INSERT INTO tasks (id, team_id, title, description, prompt, created_at)
            VALUES ('old', 'team', 'Old', 'd', 'p', '2026-01-01T00:00:00+00:00');
            SQL);
        $legacy->close();

        $list = new TaskList($this->dbPath);

        $this->assertSame(0, $list->revision('old'));
        $this->assertTrue($list->claimTask('old', 'mate'));
        $this->assertSame(1, $list->revision('old'));
    }

    // -------------------------------------------------------------------------
    // Acyclic dependencies
    // -------------------------------------------------------------------------

    public function testATaskCannotDependOnItself(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('self'));

        try {
            $list->addDependency('self', 'self');
            $this->fail('a self-dependency must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('self -> self', $e->getMessage());
        }
        $this->assertSame([], $list->getTask('self')?->dependsOn);
    }

    public function testAddDependencyRefusesAnEdgeThatClosesALoop(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('a'));
        $list->addTask($this->task('b', ['a']));
        $list->addTask($this->task('c', ['b']));

        try {
            $list->addDependency('a', 'c');
            $this->fail('a -> c -> b -> a must be refused');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('a -> c -> b -> a', $e->getMessage());
        }
        $this->assertSame([], $list->getTask('a')?->dependsOn, 'the refused edge was not written');

        // A diamond is not a cycle.
        $list->addTask($this->task('d', ['b']));
        $list->addDependency('d', 'c');
        $this->assertSame(['b', 'c'], $list->getTask('d')?->dependsOn);
    }

    public function testAddTaskRefusesALoopClosedThroughAForwardReference(): void
    {
        $list = new TaskList($this->dbPath);
        // 'x' depends on a task that does not exist yet — allowed.
        $list->addTask($this->task('x', ['y']));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('y -> x -> y');
        $list->addTask($this->task('y', ['x']));
    }

    public function testARefusedAddLeavesTheWriteLockFree(): void
    {
        $list = new TaskList($this->dbPath);
        $list->setLockWaitSecondsForTesting(0.2);
        $list->addTask($this->task('p'));

        $refused = 0;
        try {
            $list->addDependency('p', 'p');
        } catch (\InvalidArgumentException) {
            $refused++;
        }
        try {
            $list->addTask($this->task('q', ['q']));
        } catch (\InvalidArgumentException) {
            $refused++;
        }
        $this->assertSame(2, $refused);

        // Would time out in 0.2 s if either refusal had kept the write lock.
        $list->addTask($this->task('after'));
        $this->assertNotNull($list->getTask('after'));
        $this->assertNull($list->getTask('q'));
    }

    public function testACompletionOnAStaleRevisionIsRefusedAndWritesNothing(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('a'));
        $this->assertTrue($list->claimTask('a', 'mate'));
        $seen = (int) $list->revision('a');

        // The claim is released and re-taken between the read and the write.
        $this->assertTrue($list->releaseTask('a', 'mate'));
        $this->assertTrue($list->claimTask('a', 'other'));

        $this->assertFalse($list->completeTask('a', 'stale result', $seen));
        $this->assertFalse($list->failTask('a', 'stale error', $seen));

        $task = $list->getTask('a');
        $this->assertSame(TaskStatus::InProgress, $task?->status);
        $this->assertSame('other', $task?->assignedTo);
        $this->assertNull($task?->result);
        $this->assertNull($task?->error);
        $this->assertSame($seen + 2, $list->revision('a'), 'a refused finish bumps nothing');
    }

    public function testACompletionAtTheCurrentRevisionLands(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('a'));
        $list->addTask($this->task('b'));
        $list->addTask($this->task('c'));

        $this->assertTrue($list->completeTask('a', 'done', $list->revision('a')));
        $this->assertTrue($list->failTask('b', 'broke', $list->revision('b')));
        $this->assertTrue($list->completeTask('c', 'no revision named'));

        $this->assertSame(TaskStatus::Completed, $list->getTask('a')?->status);
        $this->assertSame('done', $list->getTask('a')?->result);
        $this->assertSame(TaskStatus::Failed, $list->getTask('b')?->status);
        $this->assertSame('broke', $list->getTask('b')?->error);
        $this->assertSame(TaskStatus::Completed, $list->getTask('c')?->status);
    }

    public function testFinishingAMissingTaskStillThrows(): void
    {
        $list = new TaskList($this->dbPath);

        try {
            $list->completeTask('ghost', 'x');
            $this->fail('completing a missing task must throw');
        } catch (\SQLite3Exception) {
        }
        $this->expectException(\SQLite3Exception::class);
        $list->failTask('ghost', 'x');
    }

    // -------------------------------------------------------------------------
    // Duplicate ids and per-process connections (roadmap 4.6-2)
    // -------------------------------------------------------------------------

    public function testATakenIdIsReportedCleanlyNotThroughAPrimaryKeyWarning(): void
    {
        $first = new TaskList($this->dbPath);
        // A second instance stands in for a second process: it has no state
        // of its own beyond the shared database.
        $second = new TaskList($this->dbPath);

        $this->assertTrue($first->addTaskIfAbsent($this->task('t1')));
        $this->assertFalse($second->addTaskIfAbsent($this->task('t1')), 'the loser learns the id is taken');

        try {
            $second->addTask($this->task('t1'));
            $this->fail('addTask() must refuse a taken id');
        } catch (\InvalidArgumentException $taken) {
            $this->assertStringContainsString('"t1" already exists', $taken->getMessage());
        }

        $this->assertSame('Task t1', $first->getTask('t1')?->title);
        $this->assertSame(0, $first->revision('t1'), 'the refused inserts wrote nothing');
    }

    public function testAForkedChildOpensItsOwnConnection(): void
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs pcntl');
        }

        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('before-fork'));
        $parentPid = (int) getmypid();

        $pid = $this->forkTracked();
        if ($pid === 0) {
            $ok = false;
            try {
                // Used after the fork exactly as a turn child uses the Team
                // tool's list: the write must go through a connection this
                // process opened, and the parent's must be left untouched.
                $list->addTask($this->task('from-child'));
                $ok = $list->completeTask('before-fork', 'child finished it');
                $keys = array_keys((new \ReflectionProperty(TaskList::class, 'connections'))->getValue());
                $mine = array_filter($keys, static fn (string $k): bool => str_starts_with($k, getmypid() . "\0"));
                $inherited = array_filter($keys, static fn (string $k): bool => str_starts_with($k, $parentPid . "\0"));
                $ok = $ok && $mine !== [] && $inherited !== [];
            } catch (\Throwable) {
                $ok = false;
            }
            \SugarCraft\Crush\Support\ForkedChild::exitNow($ok ? 0 : 1);
        }

        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);
        $this->assertSame(0, pcntl_wexitstatus($status), 'the child wrote through a connection of its own');

        // And the parent's connection still works after the child exited.
        $this->assertSame(TaskStatus::Completed, $list->getTask('before-fork')?->status);
        $this->assertNotNull($list->getTask('from-child'));
        $list->addTask($this->task('after-fork'));
        $this->assertNotNull($list->getTask('after-fork'));
    }

    // -------------------------------------------------------------------------
    // Crash recovery
    // -------------------------------------------------------------------------

    public function testAClaimRecordsItsProcessAndALiveClaimantKeepsIt(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('live'));
        $this->assertTrue($list->claimTask('live', 'mate'));

        $probed = [];
        $released = $list->releaseOrphanedClaims(function (int $pid, ?int $start) use (&$probed): bool {
            $probed[] = [$pid, $start];

            return true;
        });

        $this->assertSame([], $released);
        $this->assertSame([[getmypid(), BackgroundSupervisor::procStartTime(getmypid())]], $probed);
        $this->assertSame([], $list->releaseOrphanedClaims(), 'the real probe sees this process alive');
        $this->assertSame(TaskStatus::InProgress, $list->getTask('live')?->status);
    }

    public function testADeadClaimantsTaskGoesBackToPending(): void
    {
        $list = new TaskList($this->dbPath);
        $list->addTask($this->task('orphan'));
        $list->addTask($this->task('kept'));
        $this->assertTrue($list->claimTask('orphan', 'crashed', null, $this->deadPid()));
        $this->assertTrue($list->claimTask('kept', 'working'));
        $before = $list->revision('orphan');

        $this->assertSame(['orphan'], $list->releaseOrphanedClaims());

        $orphan = $list->getTask('orphan');
        $this->assertSame(TaskStatus::Pending, $orphan?->status);
        $this->assertNull($orphan?->assignedTo);
        $this->assertSame($before + 1, $list->revision('orphan'));
        $this->assertSame(TaskStatus::InProgress, $list->getTask('kept')?->status);
        $this->assertTrue($list->claimTask('orphan', 'someone-else'), 'the released task is claimable again');
    }

    public function testAClaimWithNoRecordedClaimantIsLeftAlone(): void
    {
        $list = new TaskList($this->dbPath);
        // A status write is not a claim: no claimant is recorded.
        $list->addTask($this->task('legacy', [], TaskStatus::InProgress, 'mate'));

        $this->assertSame([], $list->releaseOrphanedClaims(static fn(): bool => false));
        $this->assertSame(TaskStatus::InProgress, $list->getTask('legacy')?->status);
    }

    // -------------------------------------------------------------------------
    // Fixtures
    // -------------------------------------------------------------------------

    /** A pid that belonged to a process which has exited and been reaped. */
    private function deadPid(): int
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs pcntl to mint a dead pid');
        }
        $pid = $this->forkTracked();
        if ($pid === 0) {
            \SugarCraft\Crush\Support\ForkedChild::exitNow(0);
        }
        $this->assertGreaterThan(0, $pid);
        pcntl_waitpid($pid, $status);
        $this->forgetForkedChild($pid);

        return $pid;
    }

    /**
     * @param list<string> $dependsOn
     */
    private function task(string $id, array $dependsOn = [], TaskStatus $status = TaskStatus::Pending, ?string $assignedTo = null): Task
    {
        return new Task(
            id: $id,
            teamId: 'team',
            title: "Task {$id}",
            description: "Description {$id}",
            prompt: "Prompt {$id}",
            assignedTo: $assignedTo,
            status: $status,
            result: null,
            error: null,
            createdAt: new \DateTimeImmutable(),
            dependsOn: $dependsOn,
        );
    }
}
