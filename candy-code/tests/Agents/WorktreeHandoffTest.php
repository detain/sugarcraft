<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Agents;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SugarCraft\Code\Agents\WorktreeConfig;
use SugarCraft\Code\Agents\WorktreeHandoff;
use SugarCraft\Code\Agents\WorktreeIsolationMode;
use SugarCraft\Code\Agents\WorktreeManager;
use SugarCraft\Code\Host\Commands\AgentStatusCommands;
use SugarCraft\Code\Workspace\GitRunner;

use function bin2hex;
use function date;
use function escapeshellarg;
use function explode;
use function file_put_contents;
use function getenv;
use function implode;
use function is_dir;
use function json_encode;
use function mkdir;
use function posix_getuid;
use function putenv;
use function random_bytes;
use function scandir;
use function shell_exec;
use function trim;

/**
 * RS-AGENT-A5: the worktree hand-off helper's read-only report and its
 * plumbing-only apply, proven against throwaway git repositories.
 *
 * Every case builds a real repo with a real agent worktree (the same
 * WorktreeManager the fleet uses, configured to keep the registry inside the
 * fixture repo so {@see WorktreeHandoff::inspect}'s production-shaped
 * `WorktreeManager::new($repoRoot)` resolves to the same file), then asserts
 * the machine-visible shape: ahead/behind counts anchored at the merge base,
 * a diffstat naming the file the agent actually changed, and the conflict
 * prediction from `git merge-tree` — read-only, with no branch, HEAD, or
 * worktree moved.
 *
 * The apply side is where the honesty lives: it refuses unknown ids, ids
 * that cannot survive a ref name, uncommitted work, and conflicted merges,
 * and it RE-PREDICTS at the apply instant rather than trusting the earlier
 * report. When it does succeed, the only thing the repository gains is a
 * `handoff/*` ref with exactly two parents; the guards below prove that with
 * before/after rev-parse and rev-list walks, not with faith.
 */
#[CoversClass(WorktreeHandoff::class)]
final class WorktreeHandoffTest extends TestCase
{
    /** Env names whose mere presence would point the fresh manager at another repository's registry. */
    private const ENV_DIR_KEYS = ['CANDY_CODE_WORKTREES_DIR', 'SUGARCRUSH_WORKTREES_DIR', 'SUGAR_CRUSH_WORKTREES_DIR'];

    /** @var array<string,string|false> */
    private array $savedEnv = [];

    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::ENV_DIR_KEYS as $key) {
            $this->savedEnv[$key] = getenv($key);
            putenv($key);
        }

        $this->sandbox = '/tmp/wf-a5-handoff-' . posix_getuid() . '-' . bin2hex(random_bytes(4));
        if (!mkdir($this->sandbox, 0700, true)) {
            self::fail('could not create the hand-off sandbox');
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                continue;
            }
            putenv($key . '=' . $value);
        }

        $this->rmTree($this->sandbox);
        parent::tearDown();
    }

    public function testAnUnknownIdIsReportedUnknownAndCannotBeApplied(): void
    {
        $repo = $this->makeRepo('unknown');

        $report = WorktreeHandoff::inspect($repo, 'ghost-e137');

        self::assertFalse($report['known']);
        self::assertNull($report['branch']);
        self::assertSame('ghost-e137', $report['agentId']);

        $apply = WorktreeHandoff::apply($repo, 'ghost-e137');
        self::assertFalse($apply['ok']);
        self::assertSame('no worktree is on record for that id in this repository', $apply['reason']);
        self::assertSame([], $this->handoffRefs($repo));
    }

    public function testACleanAheadBranchIsMeasuredAgainstTheMergeBaseNotTheTip(): void
    {
        $repo = $this->makeRepo('clean');
        $worktree = $this->createAgentWorktree($repo, 'a1');
        file_put_contents($worktree . '/agent.txt', "agent work product\n");
        $this->runGitAt($worktree, 'add', '-A');
        $this->runGitAt($worktree, 'commit', '-qm', 'agent work');

        $report = WorktreeHandoff::inspect($repo, 'a1');

        self::assertTrue($report['known']);
        self::assertSame('agent-a1', $report['branch']);
        self::assertTrue($report['directoryPresent']);
        self::assertFalse($report['uncommitted']);
        self::assertSame(1, $report['ahead']);
        self::assertSame(0, $report['behind']);
        self::assertCount(1, $report['commits']);
        self::assertStringContainsString('agent work', $report['commits'][0]);
        self::assertNotNull($report['diffStat']);
        self::assertStringContainsString('agent.txt', (string) $report['diffStat']);
        self::assertSame('clean', $report['mergeVerdict']);
        self::assertSame([], $report['conflicts']);
        self::assertSame([], $report['problems']);
    }

    public function testUncommittedWorkIsFlaggedAndRefusedByApply(): void
    {
        $repo = $this->makeRepo('dirty');
        $worktree = $this->createAgentWorktree($repo, 'a1');
        file_put_contents($worktree . '/agent.txt', "agent work product\n");
        $this->runGitAt($worktree, 'add', '-A');
        $this->runGitAt($worktree, 'commit', '-qm', 'agent work');
        // And then the agent keeps typing without committing: the report must
        // say so, and apply must refuse — the hand-off moves committed history
        // only.
        file_put_contents($worktree . '/scratch.txt', "uncommitted\n");

        $report = WorktreeHandoff::inspect($repo, 'a1');
        self::assertTrue($report['uncommitted']);

        $apply = WorktreeHandoff::apply($repo, 'a1', 1700000000);
        self::assertFalse($apply['ok']);
        self::assertSame(
            'the working directory has uncommitted changes; commit them on the branch and hand off again',
            $apply['reason'],
        );
        self::assertSame([], $this->handoffRefs($repo));
    }

    public function testAConflictingBranchIsPredictedConflictsAndRefusedByApply(): void
    {
        $repo = $this->makeRepo('conflict');
        $worktree = $this->createAgentWorktree($repo, 'a1');
        file_put_contents($worktree . '/work.txt', "agent line\nagent tail\n");
        $this->runGitAt($worktree, 'commit', '-qam', 'agent work');
        file_put_contents($repo . '/work.txt', "main line\nagent tail\n");
        $this->runGitAt($repo, 'commit', '-qam', 'main work');

        $report = WorktreeHandoff::inspect($repo, 'a1');
        self::assertSame('conflicts', $report['mergeVerdict']);
        self::assertContains('work.txt', $report['conflicts']);
        self::assertSame(1, $report['behind']);

        $apply = WorktreeHandoff::apply($repo, 'a1', 1700000000);
        self::assertFalse($apply['ok']);
        self::assertSame('refusing to apply: the merge conflicts (1 file(s))', $apply['reason']);
        self::assertSame([], $this->handoffRefs($repo));
    }

    public function testApplyWritesOneTwoParentHandOffRefAndMovesNothingElse(): void
    {
        $repo = $this->makeRepo('apply');
        $worktree = $this->createAgentWorktree($repo, 'a1');
        file_put_contents($worktree . '/agent.txt', "agent work product\n");
        $this->runGitAt($worktree, 'add', '-A');
        $this->runGitAt($worktree, 'commit', '-qm', 'agent work');
        $headBefore = $this->runGitAt($repo, 'rev-parse', 'HEAD');
        $branchBefore = $this->runGitAt($repo, 'rev-parse', 'refs/heads/agent-a1');

        $result = WorktreeHandoff::apply($repo, 'a1', 1700000000);

        self::assertTrue($result['ok'], $result['reason']);
        self::assertSame('handoff/a1-' . date('Ymd-His', 1700000000), $result['ref']);
        self::assertSame([$result['ref']], $this->handoffRefs($repo));

        // The ref names an object with EXACTLY two parents: the main HEAD and
        // the agent branch tip — nothing else was merged, and the commit is
        // the GitRunner identity's, never the caller's author law.
        $parents = explode(' ', $this->runGitAt($repo, 'rev-list', '-n', '1', '--parents', $result['ref']));
        self::assertCount(3, $parents);
        self::assertSame($headBefore, $parents[1]);
        self::assertSame($branchBefore, $parents[2]);

        // The three things the command promised not to touch: the branch tip,
        // HEAD, and the author of the merge object.
        self::assertSame($branchBefore, $this->runGitAt($repo, 'rev-parse', 'refs/heads/agent-a1'));
        self::assertSame($headBefore, $this->runGitAt($repo, 'rev-parse', 'HEAD'));
        self::assertSame('candy-code <checkpoint@candy-code.invalid>', $this->runGitAt($repo, 'log', '-1', '--format=%an <%ae>', $result['ref']));
    }

    public function testApplyRePredictsTheMergeAtTheApplyInstantNotFromTheReport(): void
    {
        $repo = $this->makeRepo('stale');
        $worktree = $this->createAgentWorktree($repo, 'a1');
        file_put_contents($worktree . '/work.txt', "agent line\nagent tail\n");
        $this->runGitAt($worktree, 'commit', '-qam', 'agent work');

        // The report-time merge is clean; then main moves onto the SAME line
        // before apply runs. A cached verdict would happily stamp a conflicted
        // merge — the fresh re-prediction must catch it.
        self::assertSame('clean', WorktreeHandoff::inspect($repo, 'a1')['mergeVerdict']);
        file_put_contents($repo . '/work.txt', "main line\nagent tail\n");
        $this->runGitAt($repo, 'commit', '-qam', 'main work');

        $apply = WorktreeHandoff::apply($repo, 'a1', 1700000000);
        self::assertFalse($apply['ok']);
        self::assertStringStartsWith('refusing to apply', $apply['reason']);
        self::assertSame([], $this->handoffRefs($repo));
    }

    public function testASecondApplyStampedInTheSameSecondRefusesToCollide(): void
    {
        $repo = $this->makeRepo('collision');
        $worktree = $this->createAgentWorktree($repo, 'a1');
        file_put_contents($worktree . '/agent.txt', "agent work product\n");
        $this->runGitAt($worktree, 'add', '-A');
        $this->runGitAt($worktree, 'commit', '-qm', 'agent work');

        $first = WorktreeHandoff::apply($repo, 'a1', 1700000000);
        self::assertTrue($first['ok'], $first['reason']);

        $second = WorktreeHandoff::apply($repo, 'a1', 1700000000);
        self::assertFalse($second['ok']);
        self::assertSame('a hand-off ref already exists at that second; wait a moment and apply again', $second['reason']);
        self::assertSame([$first['ref']], $this->handoffRefs($repo));
    }

    public function testAnUnsafeAgentIdCannotBecomeARefName(): void
    {
        $repo = $this->makeRepo('idgate');
        // The forged entry points at a REAL branch so the known-gate passes
        // and the id-gate is what fires — a dot-dot id would otherwise walk
        // refs/heads/handoff/x..y straight into the repository.
        $this->runGitAt($repo, 'branch', 'evil', 'HEAD');
        $this->seedRegistry($repo, [
            'x..y' => ['branch' => 'evil', 'createdAt' => '2026-11-09T00:00:00+00:00'],
        ]);

        $apply = WorktreeHandoff::apply($repo, 'x..y', 1700000000);
        self::assertFalse($apply['ok']);
        self::assertSame('the id cannot be embedded in a branch name', $apply['reason']);
        self::assertSame([], $this->handoffRefs($repo));
    }

    public function testAGhostRegistryEntryReportsTheBranchIsGoneWithoutCounting(): void
    {
        $repo = $this->makeRepo('ghost');
        $this->seedRegistry($repo, [
            'ghost-e137' => ['branch' => 'agent-ghost-e137', 'createdAt' => '2026-11-09T00:00:00+00:00'],
        ]);

        $report = WorktreeHandoff::inspect($repo, 'ghost-e137');

        self::assertTrue($report['known']);
        self::assertFalse($report['directoryPresent']);
        self::assertNull($report['uncommitted']);
        self::assertNull($report['ahead']);
        self::assertNull($report['behind']);
        self::assertSame([], $report['commits']);
        self::assertNull($report['diffStat']);
        self::assertSame('unavailable', $report['mergeVerdict']);
        self::assertSame([], $report['conflicts']);
        self::assertSame(['the branch is gone from the repository'], $report['problems']);
    }

    public function testAHostileBranchNameNeverReachesGitAndPrintsInert(): void
    {
        $repo = $this->makeRepo('hostile');
        $this->seedRegistry($repo, [
            'h1' => ['branch' => "agent\tevil\nrm -rf /", 'createdAt' => '2026-11-09T00:00:00+00:00'],
        ]);

        $report = WorktreeHandoff::inspect($repo, 'h1');

        // Known but never run: the shape gate stops every leg before git sees
        // the name.
        self::assertTrue($report['known']);
        self::assertNull($report['ahead']);
        self::assertSame([], $report['commits']);
        self::assertSame('unavailable', $report['mergeVerdict']);
        self::assertSame(
            ['the registry names a branch this command will not run as a git argument'],
            $report['problems'],
        );

        // And the printed report absorbs the name through reportField: no raw
        // control byte of the registry value reaches the screen.
        $printed = AgentStatusCommands::handoff('h1', $repo, false);
        self::assertStringContainsString('Hand-off report for', $printed);
        self::assertStringNotContainsString("\t", $printed);
        self::assertStringNotContainsString("evil\nrm", $printed);
    }

    public function testABranchNameLeadingWithADashNeverReachesGit(): void
    {
        $repo = $this->makeRepo('dashlead');
        // The wf-a5 NIT-1 close: a leading '-' passed the old character class
        // and rode into `git merge-tree` as an option-lookalike argument. The
        // gate now mirrors REF_SAFE's leading class and refuses it before any
        // leg runs, exactly as the control-byte shape above already does.
        $this->seedRegistry($repo, [
            'd1' => ['branch' => '-evil', 'createdAt' => '2026-11-09T00:00:00+00:00'],
        ]);

        $report = WorktreeHandoff::inspect($repo, 'd1');

        self::assertTrue($report['known']);
        self::assertNull($report['ahead']);
        self::assertSame([], $report['commits']);
        self::assertSame('unavailable', $report['mergeVerdict']);
        self::assertSame(
            ['the registry names a branch this command will not run as a git argument'],
            $report['problems'],
        );
    }

    public function testADeletedWorktreeDirectoryIsReportedRemovedButStillAppliable(): void
    {
        $repo = $this->makeRepo('removed');
        $worktree = $this->createAgentWorktree($repo, 'a1');
        file_put_contents($worktree . '/agent.txt', "agent work product\n");
        $this->runGitAt($worktree, 'add', '-A');
        $this->runGitAt($worktree, 'commit', '-qm', 'agent work');
        $this->removeWorktreeDirectory($repo, $worktree);

        $report = WorktreeHandoff::inspect($repo, 'a1');

        self::assertTrue($report['known']);
        self::assertFalse($report['directoryPresent']);
        self::assertNull($report['uncommitted']);
        self::assertSame(1, $report['ahead']);
        self::assertSame('clean', $report['mergeVerdict']);
        self::assertSame([], $report['problems']);

        // apply still succeeds: the committed history is intact and that is
        // all a hand-off moves.
        $apply = WorktreeHandoff::apply($repo, 'a1', 1700000000);
        self::assertTrue($apply['ok'], $apply['reason']);
    }

    public function testDivergenceIsCountedOnBothSidesAgainstTheMergeBase(): void
    {
        $repo = $this->makeRepo('diverged');
        $worktree = $this->createAgentWorktree($repo, 'a1');
        file_put_contents($worktree . '/agent.txt', "agent work product\n");
        $this->runGitAt($worktree, 'add', '-A');
        $this->runGitAt($worktree, 'commit', '-qm', 'agent work');
        file_put_contents($repo . '/other.txt', "main's own\nmore main\n");
        $this->runGitAt($repo, 'commit', '-qam', 'main work');

        $report = WorktreeHandoff::inspect($repo, 'a1');
        self::assertSame(1, $report['ahead']);
        self::assertSame(1, $report['behind']);
        self::assertSame('clean', $report['mergeVerdict']);
    }

    /* ------------------------------------------------------------------ */

    private function makeRepo(string $tag): string
    {
        $path = $this->sandbox . '/' . $tag . '-repo';
        shell_exec('git init -q -b main ' . escapeshellarg($path) . ' 2>&1');
        if (!is_dir($path . '/.git')) {
            self::fail('git init failed for the ' . $tag . ' fixture');
        }
        $this->runGitAt($path, 'config', 'user.email', 'fixture@example.invalid');
        $this->runGitAt($path, 'config', 'user.name', 'Fixture');
        file_put_contents($path . '/work.txt', "base line\nagent tail\n");
        file_put_contents($path . '/other.txt', "main's own\n");
        $this->runGitAt($path, 'add', '-A');
        $this->runGitAt($path, 'commit', '-qm', 'seed commit');

        return $path;
    }

    private function createAgentWorktree(string $repo, string $agentId): string
    {
        // The default relative basePath expands INSIDE the fixture repo, so
        // the registry lands exactly where WorktreeHandoff's production
        // `WorktreeManager::new($repo)` will look for it.
        $manager = new WorktreeManager(
            new WorktreeConfig(isolationMode: WorktreeIsolationMode::Worktree),
            $repo,
        );

        return $manager->createWorktree($agentId, 'agent-' . $agentId);
    }

    /** @param array<string,array{branch:string,createdAt:string}> $entries */
    private function seedRegistry(string $repo, array $entries): void
    {
        $dir = $repo . '/.candy-code/worktrees';
        if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
            self::fail('could not create the registry directory');
        }
        file_put_contents($dir . '/.registry.json', (string) json_encode($entries));
    }

    /** @return list<string> */
    private function handoffRefs(string $repo): array
    {
        $out = trim($this->runGitAt($repo, 'for-each-ref', '--format=%(refname:short)', 'refs/heads/handoff'));
        if ($out === '') {
            return [];
        }

        return explode("\n", $out);
    }

    private function runGitAt(string $cwd, string ...$args): string
    {
        $result = GitRunner::new($cwd)->run(...$args);
        self::assertTrue($result['ok'], 'git ' . implode(' ', $args) . ' failed: ' . (string) $result['stderr']);

        return trim((string) $result['stdout']);
    }

    private function removeWorktreeDirectory(string $repo, string $worktree): void
    {
        // The agent's directory is REMOVED on purpose — WorktreeManager's own
        // release paths would prune the branch too, and the removed-directory
        // case needs the branch and the registry entry to survive without it.
        $this->rmTree($worktree);
        $this->rmTree($repo . '/.git/worktrees');
    }

    private function rmTree(string $path): void
    {
        // The caps-test shape: one shell rm -rf over the sandbox, not a PHP
        // recursive walker a sibling test could drift against.
        shell_exec('rm -rf ' . escapeshellarg($path) . ' 2>&1');
    }
}
