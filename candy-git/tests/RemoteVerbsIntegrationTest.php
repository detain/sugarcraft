<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Git\Git;
use SugarCraft\Git\Lang;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` integration coverage for the remote trio (candy-git-005).
 *
 * The unit lanes drive fetch/pull/push through FixtureGit booleans, which
 * replay whatever argv the code sends — the same blindness that made the
 * branches()/log() fake-driver pins insufficient (GitBranchesLogIntegration
 * Test). Only talking to an actual repository proves the push bootstrap
 * (no upstream → `--set-upstream origin <branch>`), the plain push leg once
 * an upstream exists, fetch refreshing remote-tracking refs in a second
 * clone, pull fast-forwarding that clone's worktree, and the detached-HEAD
 * refusal keeping HEAD where it is.
 */
final class RemoteVerbsIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $base;
    private string $origin;
    private string $cwd;
    private Git $git;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->base   = sys_get_temp_dir() . '/gitremote_' . uniqid();
        $this->origin = $this->base . '/origin.git';
        $this->cwd    = $this->base . '/a';

        mkdir($this->base, 0o777, true);
        exec('git init --bare ' . escapeshellarg($this->origin) . ' 2>/dev/null');

        // Seed the bare origin through a throwaway clone so both test clones
        // start with an existing master to track (git's init default branch
        // is master on the fleet runner — same premise as the branches suite).
        $seed = $this->base . '/seed';
        exec('git clone ' . escapeshellarg($this->origin) . ' ' . escapeshellarg($seed) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($seed) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($seed) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($seed . '/seed.txt', 'seed');
        exec('git -C ' . escapeshellarg($seed) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($seed) . " commit -m 'seed' 2>/dev/null");
        exec('git -C ' . escapeshellarg($seed) . ' push origin master 2>/dev/null');

        exec('git clone ' . escapeshellarg($this->origin) . ' ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
        $this->git = new Git($this->cwd);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->base);
    }

    /** @return null|string the sha the origin ref points at, null if absent */
    private function originSha(string $ref): ?string
    {
        exec('git -C ' . escapeshellarg($this->origin) . ' rev-parse --verify ' . escapeshellarg($ref) . ' 2>/dev/null', $out, $rc);
        return $rc === 0 ? trim((string) ($out[0] ?? '')) : null;
    }

    private function headSha(string $dir): string
    {
        exec('git -C ' . escapeshellarg($dir) . ' rev-parse HEAD 2>/dev/null', $out);
        return trim((string) ($out[0] ?? ''));
    }

    /**
     * rev-parse a ref inside $dir. shell_exec (not exec with an out-array):
     * PHP's exec() APPENDS to a pre-existing non-empty array instead of
     * resetting it, and two reads sharing one variable silently read the
     * stale first element — the exact foot-gun that made the first draft of
     * the fetch leg red while the library code was right.
     */
    private function resolve(string $dir, string $ref): string
    {
        return trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' rev-parse ' . escapeshellarg($ref) . ' 2>/dev/null'));
    }

    private function commitIn(string $dir, string $file, string $message): void
    {
        file_put_contents($dir . '/' . $file, $message);
        exec('git -C ' . escapeshellarg($dir) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($dir) . ' commit -m ' . escapeshellarg($message) . ' 2>/dev/null');
    }

    public function testFirstPushOfUntrackedBranchBootstrapsTheUpstream(): void
    {
        // 'feature' exists nowhere but locally: the push leg must discover the
        // missing upstream and run --set-upstream origin feature.
        $this->git->createBranch('feature');
        $this->commitIn($this->cwd, 'feat.txt', 'on feature');
        $this->assertNull($this->originSha('refs/heads/feature'));

        $this->git->push();

        self::assertSame($this->headSha($this->cwd), $this->originSha('refs/heads/feature'));
        // The bootstrap must also persist the tracking config, or the second
        // push would take the --set-upstream leg again for a branch that now
        // exists remotely (and -u on an existing remote ref is still legal,
        // so only the config check pins the difference).
        exec('git -C ' . escapeshellarg($this->cwd) . " config --get branch.feature.remote 2>/dev/null", $out);
        self::assertSame('origin', trim((string) ($out[0] ?? '')));
    }

    public function testPushWithUpstreamTakesThePlainLeg(): void
    {
        $this->git->createBranch('feature');
        $this->commitIn($this->cwd, 'feat.txt', 'first');
        $this->git->push();

        $this->commitIn($this->cwd, 'feat2.txt', 'second');
        $this->git->push();

        self::assertSame($this->headSha($this->cwd), $this->originSha('refs/heads/feature'));
    }

    public function testMasterIsPushedByPlainLegBecauseTheCloneAlreadyTracksIt(): void
    {
        // A fresh clone carries branch.master.remote=origin — the probe must
        // see the upstream and take run(['push']) with no bootstrap.
        $this->commitIn($this->cwd, 'more.txt', 'more on master');
        $this->git->push();

        self::assertSame($this->headSha($this->cwd), $this->originSha('refs/heads/master'));
    }

    public function testFetchUpdatesRemoteTrackingRefsInASecondClone(): void
    {
        $bDir = $this->base . '/b';
        exec('git clone ' . escapeshellarg($this->origin) . ' ' . escapeshellarg($bDir) . ' 2>/dev/null');
        $b = new Git($bDir);

        $seedSha = $this->headSha($this->cwd);   // what B's remote-tracking ref remembers
        $this->commitIn($this->cwd, 'pushed.txt', 'from a');
        $this->git->push();

        // Before fetch, B's origin/master ref is the stale seed sha — proof
        // the fetch actually moved a ref rather than aliasing the live tip.
        self::assertSame($seedSha, $this->resolve($bDir, 'origin/master'));
        self::assertNotSame($this->headSha($this->cwd), $seedSha);

        $b->fetch();

        self::assertSame($this->headSha($this->cwd), $this->resolve($bDir, 'origin/master'));
        // fetch alone must not touch B's worktree or branch tip.
        self::assertFileDoesNotExist($bDir . '/pushed.txt');
        self::assertSame($seedSha, $this->headSha($bDir));
    }

    public function testPullFastForwardsTheSecondCloneWorktree(): void
    {
        $bDir = $this->base . '/b';
        exec('git clone ' . escapeshellarg($this->origin) . ' ' . escapeshellarg($bDir) . ' 2>/dev/null');
        $b = new Git($bDir);

        $this->commitIn($this->cwd, 'arrives.txt', 'now in b');
        $this->git->push();

        $b->pull();

        self::assertFileExists($bDir . '/arrives.txt');
        self::assertSame('now in b', file_get_contents($bDir . '/arrives.txt'));
        self::assertSame($this->headSha($this->cwd), $this->headSha($bDir));
    }

    public function testPushOnDetachedHeadRefusesWithoutTouchingTheRemote(): void
    {
        exec('git -C ' . escapeshellarg($this->cwd) . ' checkout --detach 2>/dev/null');
        file_put_contents($this->cwd . '/orphan.txt', 'nowhere to push');
        $this->git->stageAll();
        $this->git->commit('orphan commit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(Lang::t('remote.detached'));
        $this->git->push();
    }

    public function testDetachedRefusalLeavesTheRemoteUnchanged(): void
    {
        $before = $this->originSha('refs/heads/master');
        exec('git -C ' . escapeshellarg($this->cwd) . ' checkout --detach 2>/dev/null');
        file_put_contents($this->cwd . '/orphan.txt', 'nowhere to push');
        $this->git->stageAll();
        $this->git->commit('orphan commit');

        try {
            $this->git->push();
            self::fail('detached push must refuse, not guess a branch name');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame($before, $this->originSha('refs/heads/master'));
        self::assertNull($this->originSha('refs/heads/orphan'));
    }

    /**
     * candy-git-037: after CG-06 the same bare origin answers branches() with
     * locals AND remote-tracking rows, ahead/behind counts parsed out of
     * %(upstream:track), and a `[gone]` upstream surfaced as a flag — proving
     * the flat refname parse against real git rather than fixture argv.
     */
    public function testBranchesAnswerWithRemoteRowsAndParsedDivergence(): void
    {
        // A tracks origin/master at seed, commits one: ahead 1, behind 0.
        $this->commitIn($this->cwd, 'ahead.txt', 'one ahead');
        $rows  = $this->git->branches();
        $local = null;
        $remoteSeen = false;
        foreach ($rows as $r) {
            if ($r['name'] === 'master') { $local = $r; }
            if ($r['name'] === 'origin/master') { $remoteSeen = true; }
        }
        self::assertNotNull($local, 'local master must be listed');
        self::assertFalse($local['remote']);
        self::assertTrue($local['current']);
        self::assertSame(1, $local['ahead']);
        self::assertNull($local['behind']);
        self::assertFalse($local['gone']);
        self::assertTrue($remoteSeen, 'remote-tracking master must be listed beside the local');
        // Same branch behind: pull-free, reset origin by pushing elsewhere and
        // checking out stale — instead assert behind on a second clone.
        $b = $this->base . '/b';
        exec('git clone ' . escapeshellarg($this->origin) . ' ' . escapeshellarg($b) . ' 2>/dev/null');
        $this->git->push(); // A's ahead commit reaches origin
        // behind compares the local branch against its tracking ref, so B
        // must fetch first — a clone alone still agrees with itself.
        (new Git($b))->fetch();
        $bRows = (new Git($b))->branches();
        $bMaster = null;
        foreach ($bRows as $r) {
            if ($r['name'] === 'master' && !$r['remote']) { $bMaster = $r; }
        }
        self::assertNotNull($bMaster);
        self::assertSame(1, $bMaster['behind'], 'second clone lags the pushed commit');
        self::assertNull($bMaster['ahead']);
    }

    public function testBranchesFlagAGoneUpstream(): void
    {
        $this->git->createBranch('vanished');
        $this->commitIn($this->cwd, 'v.txt', 'work on vanished');
        $this->git->push(); // bootstraps upstream origin/vanished
        exec('git -C ' . escapeshellarg($this->cwd) . ' update-ref -d refs/remotes/origin/vanished 2>/dev/null');
        $found = null;
        foreach ($this->git->branches() as $r) {
            if ($r['name'] === 'vanished') { $found = $r; }
        }
        self::assertNotNull($found);
        self::assertTrue($found['gone'], 'a vanished upstream must surface as gone, not as silence');
    }

    public function testCheckoutRemoteDwimsALocalTrackingBranch(): void
    {
        $this->git->createBranch('dwim');
        $this->commitIn($this->cwd, 'd.txt', 'work on dwim');
        $this->git->push();
        exec('git -C ' . escapeshellarg($this->cwd) . ' checkout master 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . ' branch -D dwim 2>/dev/null');
        $this->git->checkoutRemote('origin/dwim');
        // Local branch recreated from the remote ref and tracking it.
        self::assertSame('origin', trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' config --get branch.dwim.remote 2>/dev/null')));
        self::assertSame('refs/heads/dwim', trim((string) shell_exec('git -C ' . escapeshellarg($this->cwd) . ' config --get branch.dwim.merge 2>/dev/null')));
        $current = null;
        foreach ($this->git->branches() as $r) {
            if ($r['current']) { $current = $r['name']; }
        }
        self::assertSame('dwim', $current, 'DWIM lands on the local branch, never a detached HEAD');
    }
}
