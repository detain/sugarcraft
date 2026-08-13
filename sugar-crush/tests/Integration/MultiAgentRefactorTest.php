<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\AgentType;
use SugarCraft\Crush\Agents\Task;
use SugarCraft\Crush\Agents\TaskStatus;
use SugarCraft\Crush\Agents\Team;
use SugarCraft\Crush\Agents\TeamConfig;
use SugarCraft\Crush\Agents\TeamManager;
use SugarCraft\Crush\Agents\Teammate;
use SugarCraft\Crush\Agents\WorktreeConfig;
use SugarCraft\Crush\Agents\WorktreeIsolationMode;
use SugarCraft\Crush\Agents\WorktreeManager;

/**
 * E2E: multi-agent refactor coordination via Team/TeamManager/TaskList/WorktreeManager.
 *
 * This is the second of the two "E2E Tests" named in crush_code_plan.md's
 * build plan (FanOutResearchTest / MultiAgentRefactorTest) that were never
 * actually built. It only makes sense now that R1 fixed TaskList's
 * releaseTaskLock() TOCTOU (the old unlock-then-unlink cycle could let two
 * concurrent claimants both win the same task) and R6 fixed the team-cap /
 * idle-reassignment / .worktreeinclude gaps — a multi-agent refactor test
 * against the old claim race would have been flaky/wrong by construction.
 *
 * Scenario: a team of architect + 2 coders + reviewer.
 *   - Architect plans a small refactor by creating two independent coder
 *     tasks plus a review task that depends on both.
 *   - The 2 coders are REAL forked OS processes (gated on pcntl_fork(), same
 *     style as TaskListTest::testConcurrentForkedClaimAllowsExactlyOneWinner
 *     for R1) that both race for the SAME first task via a start-barrier
 *     file, each with its own independent Team/TaskList/WorktreeManager
 *     instances and SQLite3 connections — the only way to exercise
 *     flock()-based mutual exclusion for real, matching this item's brief
 *     ("genuine concurrent load, not a sequential fake").
 *   - Team::claimTask() atomically claims the task AND creates that coder's
 *     isolated git worktree (WorktreeManager::createWorktree()) in the same
 *     step, so this also exercises the worktree-creation path under real
 *     multi-process contention.
 *   - The reviewer (this test standing in for it) verifies the review task
 *     only becomes claimable once both coder tasks are completed.
 *   - The lead (this test standing in for one) verifies both coders ended
 *     up with distinct, isolated worktrees and that git itself reports both.
 */
final class MultiAgentRefactorTest extends TestCase
{
    private string $tmpRoot;
    private string $repoRoot;
    private string $worktreesBase;
    private string $oldHome;
    private WorktreeConfig $worktreeConfig;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tmpRoot = sys_get_temp_dir() . '/sugar-crush-multiagent-refactor-' . uniqid('', true);
        mkdir($this->tmpRoot, 0755, true);

        // Override HOME so Team/TeamManager's ~/.sugar-crush/teams/ paths
        // resolve inside our disposable temp dir instead of the real home.
        $this->oldHome = $_SERVER['HOME'] ?? '/root';
        $_SERVER['HOME'] = $this->tmpRoot;

        // Disposable git repository — same bare+clone pattern as
        // WorktreeManagerTest, so WorktreeManager::createWorktree() has a
        // real repo to run `git worktree add` against.
        $bare = $this->tmpRoot . '/repo.git';
        shell_exec('git init --bare ' . escapeshellarg($bare) . ' 2>&1');
        $this->repoRoot = $this->tmpRoot . '/repo';
        shell_exec('git clone ' . escapeshellarg($bare) . ' ' . escapeshellarg($this->repoRoot) . ' 2>&1');

        $this->worktreesBase = $this->tmpRoot . '/worktrees';
        $this->worktreeConfig = new WorktreeConfig(
            basePath: $this->worktreesBase . '/',
            autoCleanup: true,
            isolationMode: WorktreeIsolationMode::Worktree,
        );
    }

    protected function tearDown(): void
    {
        $_SERVER['HOME'] = $this->oldHome;
        $this->removeDirectory($this->tmpRoot);

        parent::tearDown();
    }

    /**
     * testArchitectPlansTwoCodersImplementInParallelReviewerVerifiesLeadMerges
     *
     * The core assertion this test exists to make is R1's: under REAL
     * multi-process concurrent load, each task is claimed exactly once —
     * driven here through the full Team/TaskList/WorktreeManager stack
     * rather than TaskList in isolation (TaskListTest already covers that
     * unit in isolation).
     */
    public function testArchitectPlansTwoCodersImplementInParallelReviewerVerifiesLeadMerges(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        $teamId = 'refactor-' . uniqid('', true);
        $manager = new TeamManager($this->tmpRoot . '/.sugar-crush/teams');
        $team = $manager->createTeam($teamId, 'Refactor Team', 'lead-agent', new TeamConfig(maxTeammates: 10));

        // Team of architect + 2 coders + reviewer (in-memory registration on
        // the lead's own Team instance — narrative completeness; the forked
        // coder processes below register themselves independently on their
        // own fresh Team instances, since Team's in-memory teammates map
        // does not cross process/fork boundaries).
        $team->addTeammate(new Teammate('architect-1', $teamId, 'Architect', AgentType::Architect, 'test-model', []));
        $team->addTeammate(new Teammate('coder-1', $teamId, 'Coder One', AgentType::Coder, 'test-model', []));
        $team->addTeammate(new Teammate('coder-2', $teamId, 'Coder Two', AgentType::Coder, 'test-model', []));
        $team->addTeammate(new Teammate('reviewer-1', $teamId, 'Reviewer', AgentType::Reviewer, 'test-model', []));

        // --- Architect plans a small refactor: two independent implementation
        // tasks, plus a review task gated on both being completed. ---
        $taskList = $team->getTaskList();
        $taskList->addTask($this->makeTask('task-a', $teamId, 'Extract helper in module A'));
        $taskList->addTask($this->makeTask('task-b', $teamId, 'Extract helper in module B'));
        $taskList->addTask($this->makeTask('task-review', $teamId, 'Review both extractions', ['task-a', 'task-b']));

        // Before either coder task is done, the review task must NOT be
        // claimable — this is the dependency-gating half of "reviewer
        // verifies", exercised for real via TaskList::allDependenciesCompleted().
        $unblockedBefore = array_map(fn(Task $t) => $t->id, $taskList->getUnblockedTasks('reviewer-1'));
        $this->assertNotContains(
            'task-review',
            $unblockedBefore,
            'Review task must not be claimable before both coder tasks are completed.',
        );

        // Forget the cached SQLite3 connection before forking, so each coder
        // child opens its OWN fresh connection instead of racing on a
        // fork-inherited (copy-on-write) one — mirrors
        // TaskListTest::resetTaskListConnectionCache()'s reasoning for R1.
        $this->resetTaskListConnectionCache();

        $resultDir = $this->tmpRoot . '/results';
        mkdir($resultDir, 0755, true);
        $goMarker = $this->tmpRoot . '/go';

        $coderIds = ['coder-1', 'coder-2'];
        $pids = [];

        foreach ($coderIds as $coderId) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'pcntl_fork() must succeed.');

            if ($pid === 0) {
                $this->runCoderChild($coderId, $teamId, $resultDir, $goMarker);
                exit(0);
            }

            $pids[] = $pid;
        }

        // Release both real forked coder processes to race for task-a at
        // (as close as possible to) the same instant.
        file_put_contents($goMarker, '1', LOCK_EX);

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        // --- Assert R1's guarantee: each task was claimed exactly once,
        // under genuine multi-process concurrent load. ---
        $winnersA = glob($resultDir . '/task-a.won.*');
        $winnersB = glob($resultDir . '/task-b.won.*');
        $this->assertNotFalse($winnersA);
        $this->assertNotFalse($winnersB);

        // Surface the children's give-up breadcrumbs, so a failure says WHY
        // rather than just showing an empty list.
        $gaveUp = implode(', ', array_map(
            static fn(string $f): string => basename($f) . '[' . trim((string) file_get_contents($f)) . ']',
            glob($resultDir . '/*.gaveup.*') ?: [],
        ));
        $diag = $gaveUp === '' ? '' : ' | gave up: ' . $gaveUp;

        $this->assertCount(
            1,
            $winnersA,
            'Expected exactly one winner for task-a among the concurrent coder processes, got: '
                . implode(', ', $winnersA) . $diag,
        );
        $this->assertCount(
            1,
            $winnersB,
            'Expected exactly one winner for task-b among the concurrent coder processes, got: '
                . implode(', ', $winnersB) . $diag,
        );

        $winnerA = file_get_contents($winnersA[0]);
        $winnerB = file_get_contents($winnersB[0]);
        $this->assertNotSame(
            $winnerA,
            $winnerB,
            'Both refactor tasks were claimed by the SAME coder — expected the 2 coders to '
                . 'implement in parallel, one task each.',
        );
        $this->assertContains($winnerA, $coderIds);
        $this->assertContains($winnerB, $coderIds);
        $this->assertSame(['coder-1', 'coder-2'], $this->sortPair($winnerA, $winnerB));

        // Confirm final persisted state matches the winners recorded above.
        $freshTaskList = $team->getTaskList();
        $finalTaskA = $freshTaskList->getTask('task-a');
        $finalTaskB = $freshTaskList->getTask('task-b');
        $this->assertSame(TaskStatus::Completed, $finalTaskA->status);
        $this->assertSame(TaskStatus::Completed, $finalTaskB->status);
        $this->assertSame($winnerA, $finalTaskA->assignedTo);
        $this->assertSame($winnerB, $finalTaskB->assignedTo);

        // --- Reviewer verifies: now that both coder tasks are completed,
        // the review task must be claimable, and the reviewer claims + completes it. ---
        $unblockedAfter = array_map(fn(Task $t) => $t->id, $freshTaskList->getUnblockedTasks('reviewer-1'));
        $this->assertContains(
            'task-review',
            $unblockedAfter,
            'Review task should become claimable once both coder tasks are completed.',
        );

        $reviewerWm = new WorktreeManager($this->worktreeConfig, $this->repoRoot);
        $claimed = $team->claimTask('task-review', 'reviewer-1', $reviewerWm);
        $this->assertTrue($claimed, 'Reviewer should be able to claim the now-unblocked review task.');
        $freshTaskList->completeTask('task-review', 'Both extractions verified consistent.');

        $reviewedTask = $freshTaskList->getTask('task-review');
        $this->assertSame(TaskStatus::Completed, $reviewedTask->status);
        $this->assertSame('reviewer-1', $reviewedTask->assignedTo);

        // --- Lead merges: both coders ended up with distinct, isolated
        // worktrees, and git itself (not just our JSON registry) reports both. ---
        $pathA = $this->worktreesBase . '/' . $winnerA;
        $pathB = $this->worktreesBase . '/' . $winnerB;

        $this->assertTrue(is_dir($pathA), "Expected an isolated worktree directory for {$winnerA} at {$pathA}.");
        $this->assertTrue(is_dir($pathB), "Expected an isolated worktree directory for {$winnerB} at {$pathB}.");
        $this->assertNotSame($pathA, $pathB, 'The 2 coders must have distinct, isolated worktrees.');

        $worktreeList = shell_exec('git -C ' . escapeshellarg($this->repoRoot) . ' worktree list --porcelain 2>&1');
        $this->assertIsString($worktreeList);
        $this->assertStringContainsString($pathA, $worktreeList);
        $this->assertStringContainsString($pathB, $worktreeList);
    }

    /**
     * Runs inside a forked child process, playing one coder.
     *
     * Waits for the shared start-barrier, then races (in a tight retry loop,
     * same shape as TaskListTest's R1 fork-race test) to claim task-a first
     * and task-b second, via a FRESH Team/TaskList/WorktreeManager stack —
     * exercising Team::claimTask()'s real flock()-guarded claim plus real
     * `git worktree add` under genuine multi-process contention.
     */
    private function runCoderChild(string $coderId, string $teamId, string $resultDir, string $goMarker): void
    {
        $deadline = microtime(true) + 5.0;
        while (!file_exists($goMarker) && microtime(true) < $deadline) {
            usleep(1_000);
        }

        $childTeam = new Team(
            id: $teamId,
            name: 'Refactor Team',
            leadAgentId: 'lead-agent',
            createdAt: new \DateTimeImmutable(),
            maxTeammates: 10,
        );
        $childTeam->addTeammate(new Teammate($coderId, $teamId, $coderId, AgentType::Coder, 'test-model', []));
        $wm = new WorktreeManager($this->worktreeConfig, $this->repoRoot);

        foreach (['task-a', 'task-b'] as $taskId) {
            // Generous budget: claimTask() creates a real git worktree, which
            // takes far longer on a small shared CI runner than on a dev box.
            $raceDeadline = microtime(true) + 30.0;
            $claimed = false;
            $attempts = 0;
            while (microtime(true) < $raceDeadline) {
                if ($childTeam->claimTask($taskId, $coderId, $wm)) {
                    $claimed = true;
                    break;
                }
                // Another coder already holds this task (or it briefly
                // wasn't claimable yet) — stop retrying once it is no
                // longer pending, otherwise keep racing for it.
                $current = $childTeam->getTaskList()->getTask($taskId);
                if ($current !== null && $current->status !== TaskStatus::Pending) {
                    break;
                }

                // Back off between attempts. Without this the loop is a hot
                // spin, and two forked children hammering the same lock on a
                // 2-vCPU runner starve each other badly enough that NEITHER
                // claims before the deadline — the CI failure this fixes was
                // "expected exactly one winner ... got: 0", i.e. livelock, not
                // a broken claim. Jittered by coder id so the two processes
                // do not re-collide in lockstep.
                $attempts++;
                usleep(min(20_000, 1_000 * $attempts) + (crc32($coderId) % 3_000));
            }

            if (!$claimed) {
                // Leave a breadcrumb: without it a livelock and a genuine
                // double-claim regression both surface as an empty winner
                // list with nothing to tell them apart.
                $status = $childTeam->getTaskList()->getTask($taskId)?->status->value ?? 'missing';
                file_put_contents(
                    "{$resultDir}/{$taskId}.gaveup.{$coderId}",
                    "status={$status} attempts={$attempts}",
                    LOCK_EX,
                );
            }

            if ($claimed) {
                file_put_contents("{$resultDir}/{$taskId}.won.{$coderId}", $coderId, LOCK_EX);
                $childTeam->getTaskList()->completeTask($taskId, "Implemented by {$coderId}.");
                // This coder already implemented one task — move on and let
                // the other coder take the remaining one.
                continue;
            }
        }
    }

    /**
     * Sort a pair of coder IDs for order-independent comparison.
     *
     * @return string[]
     */
    private function sortPair(string $a, string $b): array
    {
        $pair = [$a, $b];
        sort($pair);

        return $pair;
    }

    /**
     * Clear TaskList's static connection cache via reflection.
     *
     * Mirrors TaskListTest::resetTaskListConnectionCache() — necessary so
     * `new TaskList(...)` calls made inside the forked coder children open
     * fresh connections instead of inheriting the parent's (copy-on-write)
     * cached SQLite3 handle.
     */
    private function resetTaskListConnectionCache(): void
    {
        $property = new \ReflectionProperty(\SugarCraft\Crush\Agents\TaskList::class, 'connections');
        $property->setValue(null, []);
    }

    /**
     * Create a Task with sensible defaults for testing.
     *
     * @param string[] $dependsOn
     */
    private function makeTask(string $id, string $teamId, string $title, array $dependsOn = []): Task
    {
        return new Task(
            id: $id,
            teamId: $teamId,
            title: $title,
            description: "Description for {$title}",
            prompt: "Prompt for {$title}",
            createdAt: new \DateTimeImmutable(),
            dependsOn: $dependsOn,
        );
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
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
