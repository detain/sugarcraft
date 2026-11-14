<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;
use SugarCraft\Git\Git;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;

/**
 * Audit A5/A7 (round of 2026-11-03): conflicted and untracked status rows
 * staged as if they were reverse-able staged changes, a failed merge never
 * refreshed the status pane (conflicts stayed invisible), and Git's
 * cherryPickContinue()/cherryPickAbort() had zero callers — a wedged
 * cherry-pick had no way out inside the TUI.
 */
final class SequenceMenuTest extends TestCase
{
    use RecursiveDirCleanup;

    /** @var list<string> */
    private array $trash = [];

    protected function tearDown(): void
    {
        foreach ($this->trash as $dir) {
            if (is_dir($dir)) {
                $this->removeDir($dir);
            }
        }
        $this->trash = [];
    }

    // ------------------------------------------- row classification (A5/A7)

    public function testUntrackedRowStagesOnFirstKeypress(): void
    {
        $g = new FixtureGit(
            [['index_status' => '?', 'work_status' => '?', 'path' => 'brand-new']],
            [],
            []
        );
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));

        self::assertSame(['brand-new'], $g->stages, 'untracked rows stage, never unstage');
        self::assertSame([], $g->unstages);
    }

    public function testConflictedRowStagesToResolve(): void
    {
        $g = new FixtureGit(
            [['index_status' => 'U', 'work_status' => 'U', 'path' => 'both.txt']],
            [],
            []
        );
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));

        self::assertSame(['both.txt'], $g->stages, 'UU rows resolve via git add, not git reset');
        self::assertSame([], $g->unstages);
    }

    public function testStagedRowStillUnstages(): void
    {
        $g = new FixtureGit(
            [['index_status' => 'M', 'work_status' => ' ', 'path' => 'staged']],
            [],
            []
        );
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));

        self::assertSame(['staged'], $g->unstages, 'genuine staged rows keep the unstage half');
        self::assertSame([], $g->stages);
    }

    // ------------------------------------------- merge failure visibility (A5/A6)

    public function testMergeConflictRefreshesNamedFailureAndClosesBar(): void
    {
        $g = new FixtureGit(
            [['index_status' => 'U', 'work_status' => 'U', 'path' => 'both.txt']],
            [['name' => 'main', 'sha' => 'aaaa', 'current' => true]],
            []
        );
        $g->throwOn['merge'] = new RuntimeException(
            "Auto-merging both.txt\nCONFLICT (content): Merge conflict in both.txt"
        );
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        foreach (['f', 'e', 'a', 't'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));

        self::assertNotNull($a->error);
        self::assertStringContainsString('feat', $a->error, 'conflict line names the merge target');
        self::assertStringContainsString('unresolved conflicts', $a->error);
        self::assertFalse($a->collectingMergeTarget, 'the merge input bar must close on failure');

        // The conflicted row is actionable right where the user stands.
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        self::assertSame(['both.txt'], $g->stages);
    }

    public function testPlainMergeFailureKeepsRawGitDetail(): void
    {
        $g = new FixtureGit([], [['name' => 'main', 'sha' => 'aaaa', 'current' => true]], []);
        $g->throwOn['merge'] = new RuntimeException('fatal: refusal whatever');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'M'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'x'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));

        self::assertNotNull($a->error);
        self::assertStringContainsString('refusal whatever', $a->error);
    }

    // ------------------------------------------- sequencer menu kinds (A5)

    public function testCherryPickStopOpensMenuInCherryKind(): void
    {
        $g = new FixtureGit([], [], []);
        $g->throwOn['cherryPick'] = new RuntimeException('error: could not apply deadbeef');
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'V'));
        foreach (['d', 'e', 'a', 'd'] as $rune) {
            [$a, ] = $a->update(new KeyMsg(KeyType::Char, $rune));
        }
        [$a, ] = $a->update(new KeyMsg(KeyType::Enter, ''));

        self::assertTrue($a->showRebaseMenu, 'a stopped cherry-pick must surface continue/abort');
        self::assertSame('cherry', $a->rebaseMenuKind);
        self::assertNotNull($a->error);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertTrue($g->cherryPickAbortCalled, "'a' aborts the cherry-pick, not a rebase");
        self::assertFalse($g->rebaseAbortCalled);
        self::assertFalse($a->showRebaseMenu);
    }

    public function testCherryMenuContinuesAndIgnoresSkip(): void
    {
        $g = new FixtureGit([], [], []);
        $g->sequenceStateResult = 'cherry';
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertSame('cherry', $a->rebaseMenuKind);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        self::assertFalse($g->rebaseSkipCalled, 'skip is not a cherry-pick verb');
        self::assertTrue($a->showRebaseMenu, 'unknown-for-kind key leaves the menu up');

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertTrue($g->cherryPickContinueCalled);
        self::assertFalse($a->showRebaseMenu);
    }

    public function testRebaseMenuKeepsRebaseVerbs(): void
    {
        $g = new FixtureGit([], [], []);
        $g->sequenceStateResult = 'rebase';
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertSame('rebase', $a->rebaseMenuKind);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        self::assertTrue($g->rebaseSkipCalled);
        self::assertFalse($g->cherryPickContinueCalled);
    }

    public function testMergeMenuDrivesMergeLegsAndIgnoresSkip(): void
    {
        // CG-14 (candy-git-012): 'c'/'a' on a merge stop drive the merge
        // legs — not a rebase's — and the skip verb is not offered.
        $g = new FixtureGit([], [], []);
        $g->sequenceStateResult = 'merge';
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        self::assertSame('merge', $a->rebaseMenuKind);

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 's'));
        self::assertFalse($g->rebaseSkipCalled, 'skip is not a merge verb');
        self::assertTrue($a->showRebaseMenu, 'a key foreign to the kind leaves the menu up');

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertTrue($g->mergeContinueCalled, "'c' commits the recorded resolution");
        self::assertFalse($g->rebaseContinueCalled, 'the merge menu never resumes a rebase');
        self::assertFalse($a->showRebaseMenu, 'executing closes the menu');

        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'a'));
        self::assertTrue($g->mergeAbortCalled, "'a' abandons the merge");
        self::assertFalse($g->rebaseAbortCalled);
    }

    public function testFailedMergeContinueKeepsTheMenuOpenForRetry(): void
    {
        // git refuses to commit unresolved paths; the stop is still live, so
        // the menu must survive the error for a second press.
        $g = new FixtureGit([], [], []);
        $g->sequenceStateResult = 'merge';
        $g->throwOn['mergeContinue'] = new RuntimeException(
            'error: Committing is not possible because you have unmerged files.'
        );
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'c'));
        self::assertNotNull($a->error);
        self::assertStringContainsString('unmerged files', $a->error);
        self::assertTrue($a->showRebaseMenu, 'a failed continue keeps the menu for retry');
    }

    public function testRKeyDuringConflictedMergeOpensTheMenu(): void
    {
        // CG-14 (candy-git-012): a conflicted merge previously got a hint-only
        // error and no in-app exit. It now joins rebase/cherry-pick behind the
        // sequencer menu, so 'r' opens it (kind 'merge') and does not report an
        // error — the continue/abort legs are driven elsewhere in this suite.
        $g = new FixtureGit([], [], []);
        $g->sequenceStateResult = 'merge';
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Char, 'r'));

        self::assertTrue($a->showRebaseMenu, 'r on a merge stop must open the menu');
        self::assertSame('merge', $a->rebaseMenuKind);
        self::assertNull($a->error, 'the merge stop is not an error state any more');
    }

    // ------------------------------------------- real-git sequenceState probe

    public function testSequenceStateReadsRealSequencerFiles(): void
    {
        exec('git --version 2>/dev/null', $out, $rc);
        if ($rc !== 0) {
            $this->markTestSkipped('git binary unavailable');
        }
        $dir = sys_get_temp_dir() . '/gitseq_' . uniqid();
        $this->trash[] = $dir;
        mkdir($dir);
        exec('git init ' . escapeshellarg($dir) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($dir) . " config user.email 't@t' 2>/dev/null");
        exec('git -C ' . escapeshellarg($dir) . " config user.name 'T' 2>/dev/null");
        file_put_contents($dir . '/f.txt', "x\n");
        exec('git -C ' . escapeshellarg($dir) . ' add -A 2>/dev/null');
        exec('git -C ' . escapeshellarg($dir) . ' commit -qm init 2>/dev/null');

        $git = new Git($dir);
        self::assertSame('', $git->sequenceState());

        $gitDir = trim((string) shell_exec('git -C ' . escapeshellarg($dir) . ' rev-parse --absolute-git-dir'));
        self::assertNotSame('', $gitDir);

        file_put_contents($gitDir . '/MERGE_HEAD', str_repeat('0', 40) . "\n");
        self::assertSame('merge', $git->sequenceState());

        file_put_contents($gitDir . '/CHERRY_PICK_HEAD', str_repeat('1', 40) . "\n");
        self::assertSame('cherry', $git->sequenceState(), 'cherry pick beats merge marker');

        unlink($gitDir . '/CHERRY_PICK_HEAD');
        mkdir($gitDir . '/rebase-apply');
        self::assertSame('rebase', $git->sequenceState(), 'rebase beats every other stop');
    }
}
