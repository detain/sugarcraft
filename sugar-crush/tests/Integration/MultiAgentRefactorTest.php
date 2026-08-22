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
use SugarCraft\Crush\Support\ForkedChild;

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

    /**
     * The whole budget one forked coder gets for ALL of its tasks, and why it
     * is one budget rather than one per task.
     *
     * WHAT IT SAID: each task got its own 30s race deadline, described as
     * "generous ... claimTask() creates a real git worktree, which takes far
     * longer on a small shared CI runner than on a dev box".
     *
     * WHAT IS TRUE NOW: two tasks at 30s each is a 60s worst case in the
     * child, and the parent spends all of it blocked in pcntl_waitpid() --
     * which is exactly phpunit.xml's enforced defaultTimeLimit. A test whose
     * own budget equals the limit that aborts it cannot report its own
     * diagnosis: the slow path surfaces as PHPUnit's "aborted after 60
     * seconds" RISKY rather than as this file's gaveup breadcrumb. Measured on
     * this host (PHP 8.3.6, 48 cores): 700 runs of this test under 48
     * CPU-burner processes finished between 0.18s and 0.63s, p50 0.48s. So the
     * real work is three orders of magnitude inside any of these numbers, and
     * the only thing a 60s ceiling ever bought was the harness taking the
     * failure away from the test.
     *
     * WHY A BUDGET STILL EARNS ITS PLACE: without one a genuine claim
     * regression is an unbounded spin rather than a red. 20s for the whole
     * child keeps the parent's wait comfortably under the enforced limit while
     * still being ~30x the slowest run ever measured here.
     */
    private const CHILD_BUDGET_SECONDS = 20.0;

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
                // Never returns: see runCoderChild()'s doc-block for why a
                // forked child of this suite must not reach a plain exit().
                $this->runCoderChild($coderId, $teamId, $resultDir, $goMarker);
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
        $threw = implode(', ', array_map(
            static fn(string $f): string => basename($f) . '[' . trim((string) file_get_contents($f)) . ']',
            glob($resultDir . '/*.threw') ?: [],
        ));
        $diag = ($gaveUp === '' ? '' : ' | gave up: ' . $gaveUp)
            . ($threw === '' ? '' : ' | threw: ' . $threw);

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
     * A coder takes AT MOST ONE task, deterministically.
     *
     * The forked version of this is a race, and a race is only ever a
     * probabilistic pin: a coder claiming both tasks needs to be quick enough
     * to beat its sibling twice, which happened 2 times in 700 loaded runs. So
     * the invariant is pinned here instead, with the race removed and nothing
     * else changed -- ONE coder, TWO claimable tasks, the same
     * {@see raceForTasks()} the child runs. A loop that advances after a win
     * claims both and asks {@see \SugarCraft\Crush\Agents\WorktreeManager::createWorktree()}
     * for a second worktree under the same agent id, which throws; a loop that
     * stops after a win leaves the second task Pending for somebody else.
     */
    public function testACoderTakesAtMostOneTaskSoNoAgentEverAsksForTwoWorktrees(): void
    {
        $teamId = 'solo-' . uniqid('', true);
        $manager = new TeamManager($this->tmpRoot . '/.sugar-crush/teams');
        $team = $manager->createTeam($teamId, 'Refactor Team', 'lead-agent', new TeamConfig(maxTeammates: 10));

        $taskList = $team->getTaskList();
        $taskList->addTask($this->makeTask('task-a', $teamId, 'Extract helper in module A'));
        $taskList->addTask($this->makeTask('task-b', $teamId, 'Extract helper in module B'));

        $resultDir = $this->tmpRoot . '/results-solo';
        mkdir($resultDir, 0755, true);

        $this->raceForTasks('coder-1', $teamId, $resultDir, microtime(true) + self::CHILD_BUDGET_SECONDS);

        $this->assertSame(
            ['task-a.won.coder-1'],
            array_map('basename', glob($resultDir . '/*.won.*') ?: []),
            'one coder facing two claimable tasks must take the first and leave the second',
        );

        $fresh = $team->getTaskList();
        $this->assertSame(TaskStatus::Completed, $fresh->getTask('task-a')->status);
        $this->assertSame(
            TaskStatus::Pending,
            $fresh->getTask('task-b')->status,
            'the second task must still be claimable by the other coder',
        );

        // ...and only one worktree was ever asked for. Dot-entries filtered
        // rather than listed: WorktreeManager keeps its own `.registry.json`
        // and `.last-sweep` alongside the agent directories, and neither is a
        // worktree.
        $this->assertSame(
            ['coder-1'],
            array_values(array_filter(
                scandir($this->worktreesBase) ?: [],
                static fn (string $entry): bool => !str_starts_with($entry, '.'),
            )),
        );
    }

    /**
     * A throw inside a forked coder stays inside that coder.
     *
     * THE POSITIVE CONTROL IS THE THROW ITSELF: the child is set up to fail for
     * real (its worktree is created in the parent first, so its very first
     * {@see \SugarCraft\Crush\Agents\Team::claimTask()} raises the same
     * `Worktree for agent "…" already exists.` that E80's double-claim
     * produced), and the `.threw` breadcrumb proves the failure actually
     * happened rather than the child having quietly done nothing. Only then is
     * the absence assertion -- the parent's tree survived -- worth anything.
     *
     * Without {@see runCoderChild()}'s catch and {@see ForkedChild::exitNow()},
     * PHPUnit catches that throw in the CHILD, runs this class's
     * {@see tearDown()} there, and deletes `$this->tmpRoot` out from under the
     * parent. Measured on this host before the fix: the sentinel below was
     * gone, the child printed its own "ERRORS! Tests: 1" summary, and the
     * parent's real assertions then failed on an empty results directory.
     */
    public function testAThrowInsideAForkedCoderCannotRunPhpunitsTeardownInTheChild(): void
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            $this->markTestSkipped('pcntl extension not available.');
        }

        $teamId = 'throwing-' . uniqid('', true);
        $manager = new TeamManager($this->tmpRoot . '/.sugar-crush/teams');
        $team = $manager->createTeam($teamId, 'Refactor Team', 'lead-agent', new TeamConfig(maxTeammates: 10));
        $team->getTaskList()->addTask($this->makeTask('task-a', $teamId, 'Extract helper in module A'));

        // Make the child's FIRST createWorktree() throw, exactly as a second
        // claim by the same coder would.
        (new WorktreeManager($this->worktreeConfig, $this->repoRoot))->createWorktree('coder-1');

        $sentinel = $this->tmpRoot . '/sentinel';
        file_put_contents($sentinel, 'the parent still owns this tree');

        $resultDir = $this->tmpRoot . '/results-throw';
        mkdir($resultDir, 0755, true);
        $goMarker = $this->tmpRoot . '/go-throw';
        file_put_contents($goMarker, '1');

        $this->resetTaskListConnectionCache();

        $pid = pcntl_fork();
        $this->assertNotSame(-1, $pid, 'pcntl_fork() must succeed.');
        if ($pid === 0) {
            $this->runCoderChild('coder-1', $teamId, $resultDir, $goMarker);
        }

        $status = 0;
        pcntl_waitpid($pid, $status);

        $breadcrumb = $resultDir . '/coder-1.threw';
        $this->assertFileExists($breadcrumb, 'the child was supposed to fail for real; nothing was recorded');
        $this->assertStringContainsString('already exists', (string) file_get_contents($breadcrumb));

        $this->assertFileExists(
            $sentinel,
            'the forked child ran this class\'s tearDown() and deleted the parent\'s temp tree',
        );

        $this->assertTrue(
            pcntl_wifsignaled($status),
            'the child must leave via ForkedChild::exitNow()\'s SIGKILL, which is what skips PHP\'s '
                . 'shutdown sequence and with it PHPUnit\'s teardown',
        );
        $this->assertSame(SIGKILL, pcntl_wtermsig($status));
    }

    /**
     * Runs inside a forked child process, playing one coder. NEVER RETURNS.
     *
     * WHY THIS ENDS IN {@see ForkedChild::exitNow()} AND CATCHES EVERYTHING.
     * A `pcntl_fork()` child of a PHPUnit process is a complete copy of the
     * test runner, so anything that unwinds out of here is caught by PHPUnit
     * IN THE CHILD, which then runs {@see tearDown()} there -- and this
     * class's tearDown deletes `$this->tmpRoot`, the tree the PARENT is still
     * using. Measured on this host (PHP 8.3.6) with a child that throws: the
     * child ran tearDown, removed the shared directory, and printed a second
     * "ERRORS! Tests: 1" summary of its own. The observed run then failed with
     * an EMPTY winner list, from both halves at once: no `.won` file for
     * task-a was ever written (the claim committed, then createWorktree()
     * threw before the breadcrumb), and the results directory those files
     * would have been counted in had been deleted underneath the parent. That
     * is E80's whole failure chain, and it reproduced 2 times in 700 runs of
     * this test under 48 CPU-burner processes.
     *
     * `exitNow()` SIGKILLs the child instead of returning or calling `exit()`,
     * which skips PHP's shutdown sequence entirely -- no destructors, no
     * `register_shutdown_function` callbacks, and so no PHPUnit teardown.
     * {@see ForkedChild}'s class doc-block already required this of every
     * forked child in the codebase. It is NOT true that this was the only test
     * that disobeyed -- several other fork sites under `tests/` end their
     * child in a plain `exit(0)`, some of them deliberately and documented as
     * such ({@see \SugarCraft\Crush\Tests\Integration\McpToolWiringTest},
     * {@see \SugarCraft\Crush\Tests\Support\ForkedChildTest}). What made
     * this one damaging is the combination the others lack: a child that can
     * throw, and a tearDown that deletes a tree the parent is still reading.
     * Note that a plain `exit()` is
     * hazardous here for a second, independent reason: `React\EventLoop\Loop`
     * registers a shutdown function that RUNS the loop, so a child inheriting
     * a loop with any live watcher blocks forever at exit. Measured: with a
     * periodic timer armed, such a child never exited; with none, it exited at
     * once. This suite is currently shielded from that by tests/bootstrap.php
     * installing the loop with `Loop::set()` (which never registers the hook)
     * rather than `Loop::get()` -- a shield that exists for an unrelated
     * reason and could be reworked away without anyone connecting it to this.
     */
    private function runCoderChild(string $coderId, string $teamId, string $resultDir, string $goMarker): never
    {
        try {
            $deadline = microtime(true) + 5.0;
            while (!file_exists($goMarker) && microtime(true) < $deadline) {
                usleep(1_000);
            }

            $this->raceForTasks($coderId, $teamId, $resultDir, microtime(true) + self::CHILD_BUDGET_SECONDS);
        } catch (\Throwable $e) {
            // A breadcrumb rather than a rethrow, for the same reason the
            // gaveup files exist: the parent has to be able to say WHY it has
            // no winner, and a child that dies silently makes a claim
            // regression and a crashed coder look identical.
            @file_put_contents(
                $resultDir . '/' . $coderId . '.threw',
                $e::class . ': ' . $e->getMessage(),
                LOCK_EX,
            );

            ForkedChild::exitNow(1);
        }

        ForkedChild::exitNow(0);
    }

    /**
     * Race the other coder for the shared task list, taking AT MOST ONE task.
     *
     * ONE TASK, AND WHY THAT IS THE FIX RATHER THAN A TIDY-UP. This loop used
     * to `continue` after a win, under a comment reading "This coder already
     * implemented one task -- move on and let the other coder take the
     * remaining one". `continue` does not do that: it advances to the next
     * task and races for that one too. A coder quick enough to win both then
     * calls {@see \SugarCraft\Crush\Agents\Team::claimTask()} twice, whose
     * second {@see \SugarCraft\Crush\Agents\WorktreeManager::createWorktree()}
     * throws `Worktree for agent "…" already exists.` -- see
     * {@see runCoderChild()} for what an uncaught throw in a forked child then
     * does to the parent's temp tree. The comment described the intent
     * correctly and the code did something else; `break` is the code catching
     * up, and it also makes "one task each" structural instead of raced.
     *
     * Extracted from the child so it can be driven in-process by
     * {@see testACoderTakesAtMostOneTaskSoNoAgentEverAsksForTwoWorktrees()},
     * where a single coder facing two claimable tasks is a DETERMINISTIC
     * version of the race above.
     */
    private function raceForTasks(string $coderId, string $teamId, string $resultDir, float $budgetUntil): void
    {
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
            $claimed = false;
            $attempts = 0;
            while (microtime(true) < $budgetUntil) {
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

                // Back off between attempts, jittered by coder id so the two
                // processes do not re-collide in lockstep.
                //
                // WHAT THIS SAID: that without the backoff "two forked
                // children hammering the same lock on a 2-vCPU runner starve
                // each other badly enough that NEITHER claims before the
                // deadline", i.e. that the loop is a lock-contention spin.
                //
                // WHAT IS TRUE NOW: it is not a lock spin, because
                // TaskList::acquireTaskLock() takes a BLOCKING `flock(LOCK_EX)`
                // — a contender waits for the lock, it never fails to get one
                // and comes back round. The only way claimTask() returns false
                // while the task is still Pending is a dependency that is not
                // complete or an assignment to somebody else, neither of which
                // task-a and task-b can have. Measured accordingly: over 700
                // runs of this test under 48 CPU-burner processes, every coder
                // — winners and losers alike — recorded attempts=0. This
                // backoff has never executed.
                //
                // WHY IT STILL EARNS ITS PLACE: dormant is not the same as
                // wrong. It is the only thing standing between a future
                // claimTask() that CAN return a retryable false (a LOCK_NB
                // acquire, a busy-timeout surfaced as false) and a hot spin
                // that burns this child's whole budget. Left armed, and left
                // documented as unexercised rather than quietly deleted.
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

                continue;
            }

            file_put_contents("{$resultDir}/{$taskId}.won.{$coderId}", $coderId, LOCK_EX);
            $childTeam->getTaskList()->completeTask($taskId, "Implemented by {$coderId}.");

            // ONE TASK PER CODER. See this method's doc-block: `continue` here
            // is what let a fast coder claim both and ask WorktreeManager for
            // a second worktree under the same agent id.
            return;
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
