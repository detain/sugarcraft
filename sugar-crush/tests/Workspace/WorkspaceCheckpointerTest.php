<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Workspace;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Tests\Support\HomeSandboxTrait;
use SugarCraft\Crush\Workspace\GitRunner;
use SugarCraft\Crush\Workspace\WorkspaceCheckpointer;

/**
 * Item 3.A-1: per-turn workspace snapshots inside a git work tree — the
 * stash-shaped commit, the untracked half and its limits, the pinning ref,
 * the refusals, and the restore/diff primitives 3.A-2 builds `/rewind
 * --files` on.
 *
 * @see WorkspaceCheckpointer
 */
final class WorkspaceCheckpointerTest extends TestCase
{
    use HomeSandboxTrait;

    private string $tmp;

    private string $repo;

    protected function setUp(): void
    {
        if (!GitRunner::available()) {
            self::markTestSkipped('git is not installed');
        }
        $this->tmp = (string) realpath(sys_get_temp_dir()) . '/sc_wscp_' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0o700, true);
        $this->useHomeSandbox($this->tmp . '/home');
        WorkspaceCheckpointer::forgetDisabled();

        $this->repo = $this->tmp . '/repo';
        mkdir($this->repo, 0o700, true);
        $this->gitIn($this->repo, 'init', '-q');
        file_put_contents($this->repo . '/tracked.txt', "original\n");
        file_put_contents($this->repo . '/doomed.txt', "keep me\n");
        $this->gitIn($this->repo, 'add', '.');
        $this->gitIn($this->repo, 'commit', '-q', '-m', 'base');
    }

    protected function tearDown(): void
    {
        WorkspaceCheckpointer::forgetDisabled();
        $this->restoreHomeSandbox();
        self::rmrf($this->tmp);
    }

    public function testADirtyTreeIsRecordedAsAStashShapedCommitPinnedUnderAPrivateRef(): void
    {
        file_put_contents($this->repo . '/tracked.txt', "edited\n");
        file_put_contents($this->repo . '/staged.txt', "staged\n");
        $this->gitIn($this->repo, 'add', 'staged.txt');
        file_put_contents($this->repo . '/fresh.txt', "untracked\n");
        $statusBefore = $this->gitIn($this->repo, 'status', '--porcelain');

        $workspace = WorkspaceCheckpointer::new($this->repo)->capture('sess-a', 3);

        self::assertSame('captured', $workspace['status'], (string) ($workspace['reason'] ?? ''));
        self::assertSame('repo', $workspace['kind']);
        self::assertSame('stash', $workspace['layout']);
        self::assertSame('refs/sugar-crush/checkpoints/sess-a/3', $workspace['ref']);
        self::assertSame($this->repo, $workspace['workTree']);
        self::assertSame(1, $workspace['untracked']);
        self::assertSame($this->gitIn($this->repo, 'rev-parse', 'HEAD'), $workspace['base']);
        self::assertSame($workspace['sha'], $this->gitIn($this->repo, 'rev-parse', $workspace['ref']), 'the ref pins the snapshot');

        // Three parents, the way `git stash -u` lays them out: HEAD, the
        // index, the untracked files.
        $parents = explode(' ', $this->gitIn($this->repo, 'show', '-s', '--format=%P', $workspace['sha']));
        self::assertCount(3, $parents);
        self::assertSame($workspace['base'], $parents[0]);
        self::assertSame("edited\n", $this->gitIn($this->repo, 'show', $workspace['sha'] . ':tracked.txt') . "\n");
        self::assertSame('staged', $this->gitIn($this->repo, 'show', $parents[1] . ':staged.txt'), 'the index commit holds what was staged');
        self::assertSame('untracked', $this->gitIn($this->repo, 'show', $parents[2] . ':fresh.txt'));

        // Nothing the user can see moved.
        self::assertSame($statusBefore, $this->gitIn($this->repo, 'status', '--porcelain'));
        self::assertSame('', $this->gitIn($this->repo, 'stash', 'list'));
    }

    public function testTheSnapshotIsOneGitStashApplyUnderstands(): void
    {
        file_put_contents($this->repo . '/tracked.txt', "edited\n");
        file_put_contents($this->repo . '/fresh.txt', "untracked\n");
        $workspace = WorkspaceCheckpointer::new($this->repo)->capture('sess-a', 0);

        $this->gitIn($this->repo, 'reset', '-q', '--hard');
        unlink($this->repo . '/fresh.txt');
        $this->gitIn($this->repo, 'stash', 'apply', '-q', $workspace['sha']);

        self::assertSame("edited\n", file_get_contents($this->repo . '/tracked.txt'));
        self::assertSame("untracked\n", file_get_contents($this->repo . '/fresh.txt'));
    }

    public function testACleanTreeRecordsHeadItself(): void
    {
        $workspace = WorkspaceCheckpointer::new($this->repo)->capture('sess-a', 0);

        self::assertSame('captured', $workspace['status']);
        self::assertSame('head', $workspace['layout']);
        self::assertSame($workspace['base'], $workspace['sha']);
    }

    public function testAnUnbornBranchIsRecordedAsOneParentlessSnapshot(): void
    {
        $fresh = $this->tmp . '/fresh';
        mkdir($fresh);
        $this->gitIn($fresh, 'init', '-q');
        file_put_contents($fresh . '/a.txt', "a\n");

        $workspace = WorkspaceCheckpointer::new($fresh)->capture('sess-a', 0);

        self::assertSame('captured', $workspace['status'], (string) ($workspace['reason'] ?? ''));
        self::assertSame('snapshot', $workspace['layout']);
        self::assertNull($workspace['base']);
        self::assertSame('a', $this->gitIn($fresh, 'show', $workspace['sha'] . ':a.txt'));
    }

    public function testLargeExcludedAndNestedUntrackedFilesAreLeftOutAndCounted(): void
    {
        file_put_contents($this->repo . '/big.txt', str_repeat('x', WorkspaceCheckpointer::MAX_UNTRACKED_FILE_BYTES + 1));
        file_put_contents($this->repo . '/photo.png', 'png');
        file_put_contents($this->repo . '/.env.local', 'SECRET=1');
        mkdir($this->repo . '/node_modules/pkg', 0o700, true);
        file_put_contents($this->repo . '/node_modules/pkg/index.js', 'x');
        mkdir($this->repo . '/nested');
        $this->gitIn($this->repo . '/nested', 'init', '-q');
        file_put_contents($this->repo . '/nested/inner.txt', 'inner');
        file_put_contents($this->repo . '/ok.txt', 'ok');

        $workspace = WorkspaceCheckpointer::new($this->repo)->capture('sess-a', 0);

        self::assertSame('captured', $workspace['status']);
        self::assertSame(1, $workspace['untracked'], 'only ok.txt');
        // The pattern list never reaches git's listing; the size and nested
        // repository limits are the ones counted as skipped.
        self::assertSame(2, $workspace['skipped']);
        $untracked = explode(' ', $this->gitIn($this->repo, 'show', '-s', '--format=%P', $workspace['sha']))[2];
        self::assertSame('ok.txt', $this->gitIn($this->repo, 'ls-tree', '-r', '--name-only', $untracked));
    }

    public function testRestorePutsTheFilesBackAndLeavesWhatItNeverRecordedAlone(): void
    {
        file_put_contents($this->repo . '/tracked.txt', "turn-start\n");
        file_put_contents($this->repo . '/notes.txt', "mine\n");
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $workspace = $checkpointer->capture('sess-a', 0);
        self::assertSame([], $checkpointer->changes($workspace), 'nothing to restore right after the capture');

        // What a turn does: edit, delete, create — plus a big file no
        // snapshot would hold.
        file_put_contents($this->repo . '/tracked.txt', "agent edit\n");
        unlink($this->repo . '/doomed.txt');
        unlink($this->repo . '/notes.txt');
        mkdir($this->repo . '/gen/deep', 0o700, true);
        file_put_contents($this->repo . '/gen/deep/new.txt', "created\n");
        file_put_contents($this->repo . '/huge.bin.txt', str_repeat('y', WorkspaceCheckpointer::MAX_UNTRACKED_FILE_BYTES + 1));

        $changes = $checkpointer->changes($workspace);
        self::assertIsArray($changes);
        self::assertEqualsCanonicalizing(
            [['D', 'doomed.txt'], ['A', 'gen/deep/new.txt'], ['D', 'notes.txt'], ['M', 'tracked.txt']],
            $changes,
        );

        $result = $checkpointer->restore($workspace);

        self::assertSame('restored', $result['status'], $result['reason']);
        self::assertSame(3, $result['written']);
        self::assertSame(1, $result['deleted']);
        self::assertSame("turn-start\n", file_get_contents($this->repo . '/tracked.txt'));
        self::assertSame("keep me\n", file_get_contents($this->repo . '/doomed.txt'));
        self::assertSame("mine\n", file_get_contents($this->repo . '/notes.txt'));
        self::assertFileDoesNotExist($this->repo . '/gen/deep/new.txt');
        self::assertDirectoryDoesNotExist($this->repo . '/gen', 'directories the deletion emptied go too');
        self::assertFileExists($this->repo . '/huge.bin.txt', 'a file no snapshot could hold is never deleted');
        self::assertSame([], $checkpointer->changes($workspace));
        self::assertSame(
            ' M tracked.txt',
            explode("\n", $this->gitIn($this->repo, 'status', '--porcelain', '--untracked-files=no'))[0],
            'the index is the checkpoint\'s again',
        );

        self::assertSame('unchanged', $checkpointer->restore($workspace)['status']);
    }

    public function testRestoreBringsTheStagedStateBack(): void
    {
        file_put_contents($this->repo . '/staged.txt', "staged\n");
        $this->gitIn($this->repo, 'add', 'staged.txt');
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $workspace = $checkpointer->capture('sess-a', 0);

        $this->gitIn($this->repo, 'rm', '-q', '--cached', 'staged.txt');
        file_put_contents($this->repo . '/staged.txt', "changed\n");

        self::assertSame('restored', $checkpointer->restore($workspace)['status']);
        self::assertSame("staged\n", file_get_contents($this->repo . '/staged.txt'));
        self::assertSame('A  staged.txt', $this->gitIn($this->repo, 'status', '--porcelain', 'staged.txt'));
    }

    public function testRestoreIsRefusedOnceHeadHasMoved(): void
    {
        file_put_contents($this->repo . '/tracked.txt', "edited\n");
        $checkpointer = WorkspaceCheckpointer::new($this->repo);
        $workspace = $checkpointer->capture('sess-a', 0);
        $this->gitIn($this->repo, 'commit', '-q', '-am', 'moved on');

        $result = $checkpointer->restore($workspace);

        self::assertSame('refused', $result['status']);
        self::assertStringContainsString('HEAD has moved', $result['reason']);
        self::assertIsString($checkpointer->changes($workspace));
    }

    public function testANonCaptureOutcomeIsRefusedWithItsReason(): void
    {
        $result = WorkspaceCheckpointer::new($this->repo)->restore(['status' => 'refused', 'reason' => 'git is not installed']);

        self::assertSame('refused', $result['status']);
        self::assertStringContainsString('git is not installed', $result['reason']);
    }

    public function testTheHomeDirectoryAndItsPersonalFoldersAreRefused(): void
    {
        $home = $this->tmp . '/home';
        mkdir($home . '/Downloads', 0o700, true);
        mkdir($home . '/work', 0o700, true);

        self::assertStringContainsString('home directory', (string) WorkspaceCheckpointer::refusalFor($home));
        self::assertStringContainsString('contains the home directory', (string) WorkspaceCheckpointer::refusalFor($this->tmp));
        self::assertStringContainsString('~/Downloads', (string) WorkspaceCheckpointer::refusalFor($home . '/Downloads'));
        self::assertNull(WorkspaceCheckpointer::refusalFor($home . '/work'));
        self::assertNull(WorkspaceCheckpointer::refusalFor($this->repo));

        $workspace = WorkspaceCheckpointer::new($home)->capture('sess-a', 0);
        self::assertSame('refused', $workspace['status']);
    }

    public function testADotfilesRepositoryAtHomeIsRefusedFromASubdirectory(): void
    {
        $home = $this->tmp . '/home';
        $this->gitIn($home, 'init', '-q');
        mkdir($home . '/proj', 0o700, true);

        $workspace = WorkspaceCheckpointer::new($home . '/proj')->capture('sess-a', 0);

        self::assertSame('refused', $workspace['status']);
        self::assertStringContainsString('home directory', $workspace['reason']);
    }

    public function testAnInheritedGitDirDoesNotRedirectTheSnapshot(): void
    {
        $other = $this->tmp . '/other';
        mkdir($other);
        $this->gitIn($other, 'init', '-q');
        putenv('GIT_DIR=' . $other . '/.git');
        try {
            file_put_contents($this->repo . '/tracked.txt', "edited\n");
            $workspace = WorkspaceCheckpointer::new($this->repo)->capture('sess-a', 0);
        } finally {
            putenv('GIT_DIR');
        }

        self::assertSame('captured', $workspace['status']);
        self::assertSame($this->repo . '/.git', $workspace['gitDir']);
        self::assertSame('', $this->gitIn($other, 'for-each-ref'));
    }

    public function testAnExhaustedBudgetFailsAndSwitchesTheDirectoryOff(): void
    {
        $first = WorkspaceCheckpointer::new($this->repo)->withCaptureBudget(0.000001)->capture('sess-a', 0);
        self::assertSame('failed', $first['status']);
        self::assertArrayNotHasKey('timedOut', $first);

        $second = WorkspaceCheckpointer::new($this->repo)->capture('sess-a', 1);
        self::assertSame('refused', $second['status']);
        self::assertStringContainsString('checkpoints are off for this directory', $second['reason']);
    }

    public function testRefNamesStayValidForAnySessionId(): void
    {
        self::assertSame('refs/sugar-crush/checkpoints/abc-123_x/7', WorkspaceCheckpointer::refFor('abc-123_x', 7));
        $odd = WorkspaceCheckpointer::refFor('a b/../c.lock', 2);
        self::assertMatchesRegularExpression('#^refs/sugar-crush/checkpoints/x[0-9a-f]{40}/2$#', $odd);
        $this->gitIn($this->repo, 'check-ref-format', $odd);
    }

    public function testDropAndCopyRefsFollowTheirSnapshots(): void
    {
        file_put_contents($this->repo . '/tracked.txt', "edited\n");
        $workspace = WorkspaceCheckpointer::new($this->repo)->capture('sess-a', 0);
        $copy = WorkspaceCheckpointer::refFor('sess-b', 0);

        WorkspaceCheckpointer::copyRefs([[$workspace, $copy]]);
        self::assertSame($workspace['sha'], $this->gitIn($this->repo, 'rev-parse', $copy));

        WorkspaceCheckpointer::dropRefs([$workspace, ['status' => 'refused', 'reason' => 'x']]);
        self::assertSame($copy, $this->gitIn($this->repo, 'for-each-ref', '--format=%(refname)', 'refs/sugar-crush/'));
    }

    private function gitIn(string $dir, string ...$args): string
    {
        $command = 'git -c user.name=t -c user.email=t@example.invalid -c commit.gpgSign=false -C ' . escapeshellarg($dir);
        foreach ($args as $arg) {
            $command .= ' ' . escapeshellarg($arg);
        }
        exec($command . ' 2>&1', $out, $code);
        self::assertSame(0, $code, $command . "\n" . implode("\n", $out));

        return implode("\n", $out);
    }

    private static function rmrf(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::rmrf($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
