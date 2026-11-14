<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Core\Util\Color;
use SugarCraft\Style\Style;
use SugarCraft\Git\App;
use SugarCraft\Git\CherryPick;
use SugarCraft\Git\DiffViewer;
use SugarCraft\Git\Process;
use SugarCraft\Git\InteractiveRebase;
use SugarCraft\Git\Pane;
use SugarCraft\Git\RebaseAction;
use SugarCraft\Git\RebaseCommit;
use SugarCraft\Git\Renderer;
use SugarCraft\Git\StashEntry;
use SugarCraft\Git\StashManager;
use SugarCraft\Git\WorktreeEntry;
use SugarCraft\Git\Worktrees;
use PHPUnit\Framework\TestCase;

final class RendererTest extends TestCase
{
    private function git(): FixtureGit
    {
        return new FixtureGit(
            statusRows: [
                ['branch_summary' => 'main...origin/main [ahead 1]'],
                ['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/A.php'],
                ['index_status' => ' ', 'work_status' => 'M', 'path' => 'src/B.php'],
                ['index_status' => ' ', 'work_status' => ' ', 'path' => 'src/C.php'],
            ],
            branchRows: [
                ['name' => 'main', 'sha' => 'abc1', 'current' => true],
                ['name' => 'feature', 'sha' => 'def2', 'current' => false],
            ],
            logRows: [
                ['sha' => 'abc1', 'subject' => 'A short subject', 'author' => 'Joe', 'ago' => '5m'],
                ['sha' => 'def2', 'subject' => str_repeat('long-subject-line ', 5), 'author' => 'Joe', 'ago' => '1h'],
            ],
        );
    }

    public function testRenderIncludesHeaderAndBranchSummary(): void
    {
        $a = App::start($this->git());
        $out = Renderer::render($a);
        $this->assertStringContainsString('CandyGit', $out);
        $this->assertStringContainsString('main...origin/main', $out);
    }

    public function testRenderShowsHelpFooter(): void
    {
        $out = Renderer::render(App::start($this->git()));
        $this->assertStringContainsString('switch pane', $out);
        $this->assertStringContainsString('quit', $out);
    }

    public function testRenderShowsAllPanesContent(): void
    {
        $out = Renderer::render(App::start($this->git()));
        $this->assertStringContainsString('src/A.php', $out);
        $this->assertStringContainsString('main', $out);
        $this->assertStringContainsString('abc1', $out);
    }

    public function testRenderHandlesEmptyState(): void
    {
        $g = new FixtureGit([], [], []);
        $a = App::start($g);
        $out = Renderer::render($a);
        $this->assertStringContainsString('clean working tree', $out);
        $this->assertStringContainsString('no branches', $out);
        $this->assertStringContainsString('empty log', $out);

        // candy-git-093: uniform empty-state chrome - the branches and log
        // rows must ride the SAME dim channel the status pane uses, not raw
        // text. Built through the style library so any wrap-off or hex drift
        // changes the bytes and fails here.
        $dim = static fn(string $text): string => Style::new()->foreground(Color::hex('#7d6e98'))->render($text);
        $this->assertStringContainsString($dim('(no branches)'), $out, 'branches empty state lost its dim styling');
        $this->assertStringContainsString($dim('(empty log)'), $out, 'log empty state lost its dim styling');
    }

    public function testRenderShowsErrorBanner(): void
    {
        $g = new class extends \SugarCraft\Git\Tests\Support\NullGit {
            public function status(): array
            {
                throw new \RuntimeException('fatal: not a git repository');
            }
        };
        $a = App::start($g);
        $out = Renderer::render($a);
        $this->assertStringContainsString('error', $out);
        $this->assertStringContainsString('not a git repository', $out);
    }

    public function testRenderTruncatesLongSubjects(): void
    {
        $out = Renderer::render(App::start($this->git()));
        // The 'long-subject-line' subject is 90 chars; should be cut to 25 + '…'.
        $this->assertStringContainsString('…', $out);
    }

    public function testSanitizesControlBytes(): void
    {
        // Create a git that returns paths and subjects with control bytes
        $g = new class extends \SugarCraft\Git\Tests\Support\NullGit {
            public function status(): array
            {
                return [
                    ['branch_summary' => 'main'],
                    ['index_status' => 'M', 'work_status' => ' ', 'path' => "src/\x07bell.php"],
                ];
            }

            public function branches(): array
            {
                return [['name' => "branch-\x07bell", 'sha' => 'abc', 'current' => true]];
            }

            public function log(int $limit = 25): array
            {
                return [['sha' => 'abc', 'subject' => "evil\x07bell", 'author' => 'Joe', 'ago' => '5m']];
            }
        };
        $a = App::start($g);
        $out = Renderer::render($a);
        // Control bytes (bell \x07) must be stripped from rendered output
        $this->assertStringNotContainsString("\x07", $out);
    }

    public function testRenderDiffOverlay(): void
    {
        $diffLines = [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '-line 1',
            '+line 1 modified',
            ' context',
        ];
        $dv = \SugarCraft\Git\DiffViewer::fromRawDiff('src/A.php', $diffLines);
        // Construct App with diffViewer set
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App(
            $g,
            status: [['branch_summary' => 'main'], ['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/A.php']],
            diffViewer: $dv
        );
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('diff:', $out);
        $this->assertStringContainsString('src/A.php', $out);
    }

    public function testRenderDiffOverlayHighlightsPhpTokens(): void
    {
        $dv = DiffViewer::fromRawDiff('src/A.php', [
            'diff --git a/src/A.php b/src/A.php',
            '@@ -1,3 +1,4 @@',
            '+function compute() { return 42; }',
        ]);
        $a = new App($this->git(), diffViewer: $dv);
        $out = Renderer::render($a);

        // Keyword + number accents ride inside the (selected) hunk's reverse.
        $this->assertStringContainsString("\x1b[7m\x1b[38;2;137;180;250mfunction\x1b[0m", $out);
        $this->assertStringContainsString("\x1b[7m\x1b[38;2;167;139;250m42\x1b[0m", $out);
        // Marker keeps the add-line green, not the keyword blue.
        $this->assertStringContainsString("\x1b[7m\x1b[38;2;110;231;183m +\x1b[0m", $out);
        // The diff header line is metadata, never tokenised: 'diff'/'git' stay
        // inside the single lavender wrap (no blue keyword SGR around them).
        $this->assertStringNotContainsString("\x1b[38;2;137;180;250mdiff\x1b[0m", $out);
    }

    public function testRenderDiffOverlayUnknownLanguageStaysPlain(): void
    {
        $dv = DiffViewer::fromRawDiff('docs/notes.txt', [
            'diff --git a/docs/notes.txt b/docs/notes.txt',
            '@@ -1,3 +1,4 @@',
            '+function compute() { return 42; }',
        ]);
        $a = new App($this->git(), diffViewer: $dv);
        $out = Renderer::render($a);

        // Whole line keeps its single add-line SGR wrap; no interior accents.
        $this->assertStringContainsString(
            "\x1b[7m\x1b[38;2;110;231;183m +function compute() { return 42; }\x1b[0m",
            $out,
        );
        $this->assertStringNotContainsString("\x1b[38;2;137;180;250mfunction\x1b[0m", $out);
    }

    public function testDiffTitleCarriesTheSideChip(): void
    {
        $lines = ['diff --git a/src/A.php b/src/A.php', '@@ -1,2 +1,2 @@', '+added'];
        $staged = new App($this->git(), diffViewer: DiffViewer::fromRawDiff('src/A.php', $lines, true));
        $plainStaged = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($staged));
        preg_match('/(?m)^.*diff: src\/A\.php.*$/', $plainStaged, $m);
        $this->assertStringContainsString('staged', (string) ($m[0] ?? ''), 'the viewed side rides the title');
        $this->assertStringNotContainsString('worktree', (string) ($m[0] ?? ''), 'only one side is named on the title row');

        $work = new App($this->git(), diffViewer: DiffViewer::fromRawDiff('src/A.php', $lines, false));
        $plainWork = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($work));
        preg_match('/(?m)^.*diff: src\/A\.php.*$/', $plainWork, $m2);
        $this->assertStringContainsString('worktree', (string) ($m2[0] ?? ''));
        $this->assertStringNotContainsString('staged', (string) ($m2[0] ?? ''));
    }

    public function testDiffHintNamesTheActionOfTheViewedSide(): void
    {
        $lines = ['diff --git a/src/A.php b/src/A.php', '@@ -1,2 +1,2 @@', '+added'];
        $staged = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render(
            new App($this->git(), diffViewer: DiffViewer::fromRawDiff('src/A.php', $lines, true)),
        ));
        $this->assertStringContainsString('space: unstage', $staged);
        $this->assertStringNotContainsString('space: stage ', $staged);

        $work = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render(
            new App($this->git(), diffViewer: DiffViewer::fromRawDiff('src/A.php', $lines, false)),
        ));
        $this->assertStringContainsString('space: stage', $work);
        $this->assertStringNotContainsString('space: unstage', $work);
    }

    public function testDiffWindowFollowsScrollOffsetNotSelection(): void
    {
        $lines = ['diff --git a/src/A.php b/src/A.php', 'index 111..222', '--- a/src/A.php', '+++ b/src/A.php'];
        for ($i = 0; $i < 30; $i++) {
            $lines[] = ' body line ' . $i;
        }
        $viewer = DiffViewer::fromRawDiff('src/A.php', $lines)->withScrollOffset(6);
        // CG-30 disclosed re-pin: the contextual strip's second row pays a
        // chrome row on screens >= 16, so the same window that fit at h=20
        // needs h=21 now. The windowing contract itself is unchanged
        // (precedent: CG-16/CG-36 stash-overlay footer re-pins).
        $app = new App($this->git(), diffViewer: $viewer, height: 21);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($app));

        // candy-git-045/122: the visible slice derives from scrollOffset (the
        // array lines[6] up = body line 2), so a scrolled view hides the
        // early rows and reports them above - while the selection stays
        // exactly where it was (this fixture carries no hunks at all).
        $this->assertStringNotContainsString('body line 1', $plain, 'rows above the top-anchored window are not rendered');
        $this->assertStringContainsString('body line 2', $plain, 'the window opens exactly at scrollOffset');
        $this->assertStringContainsString('body line 7', $plain);
        $this->assertStringNotContainsString('body line 8', $plain, 'and stops at the bottom');
        $this->assertStringContainsString('above', $plain, 'the scroll indicator names what is out of view');
        self::assertSame(0, $viewer->hunkIndex(), 'scrolling never moved the selection');
    }

    public function testRenderRebaseOverlay(): void
    {
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App(
            $g,
            showRebaseMenu: true
        );
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('rebase', $out);
        $this->assertStringContainsString('continue', $out);
        $this->assertStringContainsString('abort', $out);
    }

    public function testRenderSequencerMenuForMergeStop(): void
    {
        // CG-14: the merge stop names itself and offers only the legs git
        // actually has for a merge (skip is a rebase-only notion).
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App($g, showRebaseMenu: true, rebaseMenuKind: 'merge');
        $out = \SugarCraft\Git\Renderer::render($a);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $out);
        $this->assertStringContainsString('merge: [c]ontinue [a]bort', $plain);
        $this->assertStringContainsString(' merge ', $plain, 'the box title names the merge');
        $this->assertStringNotContainsString('skip', $plain, 'a merge menu must not offer skip');
    }

    public function testRenderSequencerMenuForCherryStopStillSkipsNothing(): void
    {
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App($g, showRebaseMenu: true, rebaseMenuKind: 'cherry');
        $out = \SugarCraft\Git\Renderer::render($a);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $out);
        $this->assertStringContainsString('cherry-pick: [c]ontinue [a]bort', $plain);
        $this->assertStringNotContainsString('skip', $plain);
    }

    public function testRenderRebaseMenuKeepsSkipForRebaseKind(): void
    {
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App($g, showRebaseMenu: true, rebaseMenuKind: 'rebase');
        $out = \SugarCraft\Git\Renderer::render($a);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', $out);
        $this->assertStringContainsString('rebase: [c]ontinue [a]bort [s]kip', $plain);
        $this->assertStringContainsString('skip', $plain);
    }

    public function testRenderStashOverlayWithEntries(): void
    {
        $g = new FixtureGit([], [], []);
        $entries = [
            new \SugarCraft\Git\StashEntry(0, 'abc1234', 'main', 'WIP commit'),
        ];
        $sm = new \SugarCraft\Git\StashManager($entries);
        $a = new \SugarCraft\Git\App($g, stashManager: $sm);
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('stashes', $out);
        $this->assertStringContainsString('WIP commit', $out);
    }

    public function testRenderStashOverlayEmpty(): void
    {
        $g = new FixtureGit([], [], []);
        $sm = new \SugarCraft\Git\StashManager([]);
        $a = new \SugarCraft\Git\App($g, stashManager: $sm);
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('stashes', $out);
        $this->assertStringContainsString('empty', $out);
    }

    // ---- CG-16: stash create/pop surface ----

    public function testStashCollectorBarShowsTheTrackedPrompt(): void
    {
        $g = new FixtureGit([], [], []);
        $sm = new \SugarCraft\Git\StashManager([]);
        $a = new \SugarCraft\Git\App($g, stashManager: $sm, collectingStash: true, stashMessage: 'wip');
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', \SugarCraft\Git\Renderer::render($a));
        $this->assertStringContainsString('stash message: wip_', $plain);
        $this->assertStringNotContainsString('(+untracked)', $plain);
    }

    public function testStashCollectorBarShowsTheUntrackedPrompt(): void
    {
        $g = new FixtureGit([], [], []);
        $sm = new \SugarCraft\Git\StashManager([]);
        $a = new \SugarCraft\Git\App($g, stashManager: $sm, collectingStash: true, stashMessage: 'wip', stashUntracked: true);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', \SugarCraft\Git\Renderer::render($a));
        $this->assertStringContainsString('stash message (+untracked): wip_', $plain);
    }

    public function testStashOverlayFooterListsTheNewVerbs(): void
    {
        $g = new FixtureGit([], [], []);
        $entries = [new \SugarCraft\Git\StashEntry(0, 'abc1234', 'main', 'WIP commit')];
        $a = new \SugarCraft\Git\App($g, stashManager: new \SugarCraft\Git\StashManager($entries));
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', \SugarCraft\Git\Renderer::render($a));
        $this->assertStringContainsString('stash changes', $plain);
        $this->assertStringContainsString('stash incl. untracked', $plain);
        $this->assertStringContainsString('pop (apply + drop)', $plain);
    }

    public function testHostileStashMessageCannotForgeEscapesInTheBar(): void
    {
        $g = new FixtureGit([], [], []);
        $sm = new \SugarCraft\Git\StashManager([]);
        $a = new \SugarCraft\Git\App($g, stashManager: $sm, collectingStash: true, stashMessage: "evil\x1b[1;1Hgate");
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringNotContainsString("\x1b[1;1H", $out);
        $this->assertStringContainsString('evilgate', preg_replace('/\x1b\[[0-9;]*m/', '', $out));
    }

    public function testRenderWorktreeOverlayWithEntries(): void
    {
        $g = new FixtureGit([], [], []);
        // Branch deliberately NOT named main: the badge must be proven as
        // the badge, not as a branch word that happens to match.
        $entries = [
            new \SugarCraft\Git\WorktreeEntry('/path/one', 'trunk'),
        ];
        $wt = new \SugarCraft\Git\Worktrees($entries);
        $a = new \SugarCraft\Git\App($g, worktrees: $wt);
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('worktrees', $out);
        $this->assertStringContainsString('/path/one', $out);
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $out);
        $this->assertStringContainsString('(main)', $plain, 'row 0 always carries the main badge (candy-git-089)');
    }

    public function testRenderWorktreeOverlayShowsDetachedBadge(): void
    {
        $g = new FixtureGit([], [], []);
        $entries = [
            new \SugarCraft\Git\WorktreeEntry('/path/one', 'main'),
            new \SugarCraft\Git\WorktreeEntry('/path/two', '', true),
        ];
        $wt = new \SugarCraft\Git\Worktrees($entries);
        $a = new \SugarCraft\Git\App($g, worktrees: $wt);
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', \SugarCraft\Git\Renderer::render($a));
        $this->assertStringContainsString('/path/two', $plain);
        $this->assertStringContainsString('(detached)', $plain, 'the surfaced state replaces the old blank branch column (candy-git-082)');
    }

    public function testWorktreeAddFooterLabelsTheExitThatWorks(): void
    {
        $g = new FixtureGit([], [], []);
        // candy-git-031: mid-add, c is a path letter, so the footer must not
        // advertise it as the close key. Esc is the labeled exit.
        $wt = (new \SugarCraft\Git\Worktrees([new \SugarCraft\Git\WorktreeEntry('/path/one', 'trunk')]))->startAdding();
        $a = new \SugarCraft\Git\App($g, worktrees: $wt);
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', \SugarCraft\Git\Renderer::render($a));

        $this->assertStringContainsString('esc  close', $plain, 'add mode names esc');
        $this->assertStringNotContainsString(' c  close', $plain, 'the false c affordance must not render mid-add (leading space: esc  close legitimately ends in c  close)');
    }

    public function testRenderWorktreeOverlayAddingState(): void
    {
        $g = new FixtureGit([], [], []);
        $wt = new \SugarCraft\Git\Worktrees([], adding: true, newPath: '/new/wt', newBranch: 'feature');
        $a = new \SugarCraft\Git\App($g, worktrees: $wt);
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('worktrees', $out);
        $this->assertStringContainsString('/new/wt', $out);
        $this->assertStringContainsString('feature', $out);
    }

    public function testRenderInteractiveRebaseOverlaySelectingN(): void
    {
        $g = new FixtureGit([], [], []);
        $ir = \SugarCraft\Git\InteractiveRebase::selectingN();
        $a = new \SugarCraft\Git\App($g, interactiveRebase: $ir);
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('interactive rebase', $out);
        // The selectingN state shows a prompt for entering the commit count
        $this->assertStringContainsString('n = _', $out);
    }

    public function testRenderInteractiveRebaseOverlayWithCommits(): void
    {
        $g = new FixtureGit([], [], []);
        $commits = [
            new \SugarCraft\Git\RebaseCommit('abc1234', 'first commit', \SugarCraft\Git\RebaseAction::Pick),
            new \SugarCraft\Git\RebaseCommit('def5678', 'second commit', \SugarCraft\Git\RebaseAction::Reword),
        ];
        $ir = new \SugarCraft\Git\InteractiveRebase($commits, selectingN: false);
        $a = new \SugarCraft\Git\App($g, interactiveRebase: $ir);
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('interactive rebase', $out);
        $this->assertStringContainsString('abc1234', $out);
        $this->assertStringContainsString('def5678', $out);
    }

    public function testRenderWithError(): void
    {
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App($g, error: 'something went wrong');
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('error', $out);
        $this->assertStringContainsString('something went wrong', $out);
    }

    public function testRenderWithSuccessMessage(): void
    {
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App($g, successMessage: 'Operation succeeded');
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('Operation succeeded', $out);
    }

    public function testRenderWithCommitMessageCollection(): void
    {
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App($g, collectingCommit: true, commitMessage: 'my commit');
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('commit', $out);
        $this->assertStringContainsString('my commit', $out);
    }

    public function testRenderWithBranchNameCollection(): void
    {
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App($g, collectingBranchName: true, branchName: 'feature-x');
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('branch', $out);
        $this->assertStringContainsString('feature-x', $out);
    }

    public function testRenderWithMergeTargetCollection(): void
    {
        $g = new FixtureGit([], [], []);
        $a = new \SugarCraft\Git\App($g, collectingMergeTarget: true, mergeTarget: 'main');
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('merge', $out);
        $this->assertStringContainsString('main', $out);
    }

    public function testRenderWithCherryPickCollection(): void
    {
        $g = new FixtureGit([], [], []);
        $cp = \SugarCraft\Git\CherryPick::collecting()->withCommitRef('ab');
        $a = new \SugarCraft\Git\App($g, cherryPick: $cp);
        $out = \SugarCraft\Git\Renderer::render($a);
        // The rendered output shows "cherry-pick:" (with hyphen from lang file)
        $this->assertStringContainsString('cherry-pick:', $out);
        $this->assertStringContainsString('ab', $out);
    }

    public function testTruncatesLongPath(): void
    {
        $longPath = str_repeat('a', 200);
        $g = new class($longPath) implements \SugarCraft\Git\GitDriver {
            private string $path;
            public function __construct(string $path) { $this->path = $path; }
            public function status(): array {
                return [['branch_summary' => 'main'], ['index_status' => 'M', 'work_status' => ' ', 'path' => $this->path]];
            }
            public function branches(): array { return [['name' => 'main', 'sha' => 'abc', 'current' => true]]; }
            public function log(int $limit = 25): array { return [['sha' => 'abc', 'subject' => 'short', 'author' => 'Joe', 'ago' => '5m']]; }
            public function stage(string $path): void {}
            public function unstage(string $path): void {}
            public function checkout(string $branch): void {}
            public function checkoutRemote(string $remoteBranch): void {}
            public function commit(string $_message): void {}
            public function stageAll(): void {}
            public function unstageAll(): void {}
            public function diff(string $path, bool $cached = false): array { return []; }
            public function discard(string $path): void {}
            public function untrackedDiff(string $path): array { return []; }
            public function deleteUntracked(string $path): void {}
            public function amend(): void {}
            public function amendWith(string $message): void {}
            public function lastCommitMessage(): string { return ""; }
            public function commitGpgSigns(): bool { return false; }
            public function stagePatch(string $path, string $hunk): void {}
            public function unstagePatch(string $path, string $hunk): void {}
            public function createBranch(string $name, ?string $from = null): void {}
            public function commitDetails(string $ref): array { return []; }
            public function revert(string $ref): void {}
            public function resetHard(string $ref): void {}
            public function deleteBranch(string $_name): void {}
            public function restoreBranch(string $_name, string $_sha): void {}
            public function merge(string $branch): void {}
            public function mergeAbort(): void {}
            public function mergeContinue(): void {}
            public function fetch(): void {}
            public function pull(): void {}
            public function push(): void {}
            public function rebaseContinue(): void {}
            public function rebaseAbort(): void {}
            public function rebaseSkip(): void {}
            public function reset(): void {}
            public function stashList(): array { return []; }
            public function stashApply(string $stashRef): void {}
            public function stashDrop(string $stashRef): void {}
    public function stashSha(string $stashRef): string { return ''; }

    public function stashSubject(string $stashRef): string { return ''; }
    public function stashRestore(string $sha, string $subject = ''): void {}
            public function stashPush(?string $message, bool $keepIndex = false, bool $includeUntracked = false): void {}
            public function stashPop(string $stashRef): void {}
            public function cherryPick(string $commit): void {}
            public function cherryPickContinue(): void {}
            public function cherryPickAbort(): void {}
            public function worktreeList(): array { return []; }
            public function worktreeAdd(string $path, string $branch): void {}
            public function worktreeRemove(string $path): void {}
            public function sequenceState(): string { return ''; }
        };
        $a = (new App($g, width: 80, height: 24, history: new \SugarCraft\Git\HistoryManager()))->refresh();
        $out = Renderer::render($a);
        // Long path should be truncated with ellipsis
        $this->assertStringContainsString('…', $out);
        // CG-22 re-pin (candy-git-022): the cursor modeline re-states the
        // selected path on its own row, so the old "no run of 50 a's" pin
        // could never hold once the modeline shipped — the row simply repeats
        // the selection up to the bar budget. What must stay forbidden is
        // UNBOUNDED overflow: the full 200-character path may not appear, and
        // no physical row may exceed the 80-cell frame.
        $this->assertStringNotContainsString(str_repeat('a', 200), $out);
        foreach (explode("\n", $out) as $line) {
            $cells = Width::of((string) preg_replace('/\x1b\[[0-9;]*m/', '', $line));
            $this->assertLessThanOrEqual(80, $cells, 'a frame row overflowed the width budget');
        }
    }

    /**
     * PB-GIT-15 (M8): pane focus must never ride the color channel alone.
     * The focused pane's title carries a {@see Renderer::FOCUS_MARKER} text
     * prefix; unfocused titles never do, and exactly one marker is painted
     * per frame wherever focus lives.
     */
    public function testTheFocusedPaneCarriesATextMarker(): void
    {
        $out = Renderer::render(App::start($this->git()));

        $this->assertSame(1, substr_count($out, Renderer::FOCUS_MARKER), 'exactly the focused pane is marked');
        $this->assertStringContainsString(Renderer::FOCUS_MARKER . '  status', $out, 'status is focused at start');
        $this->assertStringNotContainsString(Renderer::FOCUS_MARKER . '  branches', $out);
        $this->assertStringNotContainsString(Renderer::FOCUS_MARKER . '  log', $out);
    }

    public function testTheTextMarkerFollowsTabCycling(): void
    {
        [$a, ] = App::start($this->git())->update(new KeyMsg(KeyType::Tab, ''));
        $out   = Renderer::render($a);

        $this->assertSame(1, substr_count($out, Renderer::FOCUS_MARKER));
        $this->assertStringContainsString(Renderer::FOCUS_MARKER . '  branches', $out);
        $this->assertStringNotContainsString(Renderer::FOCUS_MARKER . '  status', $out);
    }

    /**
     * The discriminating keystone (red before PB-GIT-15): with the Ascii
     * profile the accent border emits no truecolor, so before the text
     * marker the two focus states were byte-identical — tab cycling
     * "showed nothing" for NO_COLOR / TERM=dumb / ASCII-palette users.
     */
    public function testFocusIsDistinguishableUnderAnAsciiPalette(): void
    {
        Style::setDefaultProfile(ColorProfile::Ascii);
        try {
            $git   = $this->git();
            $onStatus = Renderer::render(App::start($git));
            [$tabbed, ] = App::start($git)->update(new KeyMsg(KeyType::Tab, ''));
            $onBranches = Renderer::render($tabbed);

            $this->assertSame(0, substr_count($onStatus, "\x1b[38;2;"), 'Ascii strips the color channel');
            $this->assertNotSame($onStatus, $onBranches, 'focus must change the frame with color stripped');
            $this->assertStringContainsString(Renderer::FOCUS_MARKER . '  status', $onStatus);
            $this->assertStringContainsString(Renderer::FOCUS_MARKER . '  branches', $onBranches);
        } finally {
            Style::setDefaultProfile(null);
        }
    }

    /**
     * ▸ must stay a one-cell glyph in the canonical width oracle, or the
     * marker would desync the fixed-width pane boxes.
     */
    public function testTheFocusMarkerIsSingleWidth(): void
    {
        $this->assertSame(1, Width::of(Renderer::FOCUS_MARKER));
    }

    /**
     * candy-git-037: the branches pane carries remote-tracking rows and
     * per-row divergence badges. Two locals + two remotes render both, with
     * `*` for the current local, `~` for remotes, `[↑n ↓m]` from the parsed
     * upstream:track counts, `[!]` for a vanished upstream, and no badge
     * where there is no divergence.
     */
    public function testBranchesPaneRendersRemotesAndBadges(): void
    {
        $g = $this->git();
        $g->branchRows = [
            ['name' => 'main',    'sha' => 'abc1', 'current' => true,  'remote' => false, 'ahead' => 2,    'behind' => 1,    'gone' => false],
            ['name' => 'orphan',  'sha' => 'def2', 'current' => false, 'remote' => false, 'ahead' => null, 'behind' => null, 'gone' => true],
            ['name' => 'origin/main',    'sha' => 'abc1', 'current' => false, 'remote' => true, 'ahead' => null, 'behind' => null, 'gone' => false],
            ['name' => 'origin/feature', 'sha' => 'ghi3', 'current' => false, 'remote' => true, 'ahead' => null, 'behind' => null, 'gone' => false],
        ];
        $a = App::start($g);
        [$a, ] = $a->update(new KeyMsg(KeyType::Tab, ''));
        $out = Renderer::render($a);
        $this->assertStringContainsString('main [↑2 ↓1]', $out);
        $this->assertStringContainsString('orphan [!]', $out);
        $this->assertStringContainsString('~ origin/main', $out);
        $this->assertStringContainsString('~ origin/feature', $out);
        $this->assertStringNotContainsString('~ origin/main [', $out, 'remote rows carry no upstream badge');
        $this->assertStringNotContainsString('ahead', substr($out, strpos($out, 'branches')), 'badges never ship English words (branches pane onward)');
    }

    // --------------------------------------------------------- notice lane (CG-11)

    public function testNoticeLaneRendersTheArmsRequestOutsideTheErrorLane(): void
    {
        $a = new App($this->git(), notice: 'discard: press d again for src/A.php (unrecoverable)', width: 80, height: 24);
        $out = Renderer::render($a);

        $this->assertStringContainsString('press d again for src/A.php', $out);
        $this->assertStringNotContainsString('error:', $out, 'a request for confirmation must not wear the red lane');
    }

    public function testCollectorHintRidesTheNoticeLane(): void
    {
        // candy-git-106: one confirmation grammar — the collector gets the
        // same amber instruction lane the destructive arms use. Sampled on
        // the branch bar since CG-15 gave the commit collector its own
        // (different) hint — see testCommitCollectorShowsTheCommitHint.
        $a = new App($this->git(), collectingBranchName: true, branchName: 'wip', width: 80, height: 24);
        $out = Renderer::render($a);

        $this->assertStringContainsString('enter confirms · esc cancels', $out);
    }

    public function testCommitCollectorShowsTheCommitHint(): void
    {
        // candy-git-013: Enter commits here, so the generic hint would lie
        // about the newline chord; the commit collector names the real keys.
        $a = new App($this->git(), collectingCommit: true, commitMessage: 'wip', width: 80, height: 24);
        $out = Renderer::render($a);

        $this->assertStringContainsString('enter commits · tab newline · esc cancels', $out);
        $this->assertStringNotContainsString('enter confirms', $out);
    }

    public function testCommitBarPreviewsMultiLineMessageOnOneRow(): void
    {
        // candy-git-013: the body must not smash the layout — first line plus
        // an arrow glyph counting the lines below it.
        $a = new App(
            $this->git(),
            collectingCommit: true,
            commitMessage: "subject line\nbody one\nbody two",
            width: 80,
            height: 24,
        );
        $out = Renderer::render($a);
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $out);

        $this->assertStringContainsString('commit message: subject line ↵2', $plain);
        // The folded rows never reach the frame verbatim.
        $this->assertStringNotContainsString('body one', $plain);
    }

    public function testAmendCollectorAsksForItsRewriteInItsOwnPrompt(): void
    {
        $a = new App(
            $this->git(),
            collectingCommit: true,
            commitAmend: true,
            commitMessage: 'feat: original',
            width: 80,
            height: 24,
        );
        $out = Renderer::render($a);
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $out);

        $this->assertStringContainsString('amend message: feat: original', $plain);
    }

    public function testArmNoticeWinsOverTheCollectorHint(): void
    {
        $a = new App($this->git(), collectingBranchName: true, branchName: 'wip', notice: 'press d again to discard src/A.php', width: 80, height: 24);
        $out = Renderer::render($a);

        $this->assertStringContainsString('press d again to discard', $out);
        $this->assertStringNotContainsString('enter confirms', $out);
    }

    public function testMultiLineStderrCollapsesToOnePhysicalRow(): void
    {
        // candy-git-044: checkout-conflict stderr is five to ten lines; pasted
        // raw into the one-row lane it grew the frame and pushed content past
        // the ceiling, while truncation amputated the explanation. Every
        // non-empty line must survive the collapse, and the lane must stay on
        // exactly one physical row (budget truncate may still cut the tail —
        // same law as every other lane).
        $stderr = "local changes would be overwritten:\n\tmodified: src/A.php\nAborting";
        $a      = new App($this->git(), error: $stderr, width: 80, height: 24);
        $out    = Renderer::render($a);
        $plain  = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $out);

        $this->assertStringContainsString('error: local changes would be overwritten: · modified: src/A.php · Aborting', $plain);
        $this->assertSame(1, preg_match_all('/(?m)^.*error:.*$/', $plain), 'the error lane is exactly one physical row');

        $tall = new App($this->git(), error: str_repeat("some git detail line ", 40) . "\nAborting", width: 80, height: 24);
        $tallPlain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($tall));
        $this->assertSame(1, preg_match_all('/(?m)^.*error:.*$/', $tallPlain), 'a twelve-hundred-cell stderr blob still owns a single row');
    }

    // ---- CG-22 (candy-git-022): the cursor channel on a styleless palette --

    public function testTheNoTtyFrameCarriesNoEscapesYetStillNamesTheCursor(): void
    {
        $app = new App(
            $this->git(),
            status: [['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/A.php']],
            width: 80,
            height: 24,
        );

        Style::setDefaultProfile(ColorProfile::NoTty);
        try {
            $frame = Renderer::render($app);
        } finally {
            Style::setDefaultProfile(null);
        }

        $this->assertStringNotContainsString(chr(27), $frame, 'a TERM=dumb frame must be pure text');
        $this->assertStringContainsString('cursor: src/A.php', $frame, 'the modeline names the selection on every palette');
    }

    public function testTheModelineCoexistsWithTheStyledFrame(): void
    {
        $frame = Renderer::render(new App(
            $this->git(),
            status: [['index_status' => 'M', 'work_status' => ' ', 'path' => 'src/A.php']],
            width: 80,
            height: 24,
        ));

        $this->assertStringContainsString('cursor: src/A.php', $frame);
        $this->assertStringContainsString(chr(27) . '[', $frame, 'styling terminals keep their palette');
    }

    public function testAHostileRowCannotForgeEscapesThroughTheModeline(): void
    {
        $app = new App(
            $this->git(),
            branches: [['name' => "evil\x1b[1;1Hgate", 'sha' => 'x', 'current' => true]],
            pane: Pane::Branches,
            width: 80,
            height: 24,
        );

        $frame = Renderer::render($app);

        $this->assertStringNotContainsString(chr(27) . '[1;1H', $frame);
        $this->assertStringContainsString('cursor: evilgate', $frame);
    }

    // ---- CG-29 (candy-git-046): the collect bar windows the tail under the caret ----

    private static function branchRowOf(string $frame): string
    {
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $frame);
        self::assertSame(1, preg_match('/(?m)^ branch name: (.*)$/m', $plain, $m), 'the branch bar is exactly one row');

        return $m[1];
    }

    public function testALongBufferShowsItsTailNotItsHead(): void
    {
        $frame = Renderer::render(new App(
            $this->git(),
            collectingBranchName: true,
            branchName: str_repeat('x', 200) . 'TAILMARK',
            width: 80,
            height: 24,
        ));

        $this->assertStringNotContainsString(str_repeat('x', 200), $frame, 'the head may not overflow the bar');
        $row = self::branchRowOf($frame);
        $this->assertStringContainsString('…', $row, 'the hidden head is signalled');
        $this->assertMatchesRegularExpression('/TAILMARK_\z/', $row, 'the tail and its caret are the visible part');
        $this->assertLessThanOrEqual(78, Width::of('branch name: ' . $row));
    }

    public function testTheTailWindowNeverSplitsAGraphemeCluster(): void
    {
        $frame = Renderer::render(new App(
            $this->git(),
            collectingBranchName: true,
            branchName: str_repeat("\u{1F469}\u{200D}\u{1F4BB}", 40),
            width: 80,
            height: 24,
        ));

        $row = self::branchRowOf($frame);
        $this->assertLessThanOrEqual(78, Width::of('branch name: ' . $row));
        $this->assertMatchesRegularExpression('/_\z/', $row);
        $this->assertStringNotContainsString("\u{200D}", (string) preg_replace('/(?:\x{1F469}\x{200D}\x{1F4BB})+/u', '', $row), 'no orphaned joiner survives the window');
    }

    public function testEvenANarrowBarKeepsItsCaret(): void
    {
        $frame = Renderer::render(new App(
            $this->git(),
            collectingBranchName: true,
            branchName: str_repeat('y', 50),
            width: 20,
            height: 24,
        ));

        $row = self::branchRowOf($frame);
        $this->assertStringContainsString('…', $row);
        $this->assertMatchesRegularExpression('/_\z/', $row, 'barWidth 18: prompt 8 + ellipsis + 8 tail + caret = 18');
        $this->assertLessThanOrEqual(18, Width::of('branch name: ' . $row));
    }

    // ---- CG-18: log meta, sealed selection band, commit view (candy-git-015) ----

    /** @return array<int, array{sha:string,subject:string,author:string,ago:string}> */
    private static function logWithMeta(): array
    {
        return [
            ['sha' => 'abc1', 'subject' => 'first commit', 'author' => 'Joe', 'ago' => '5m ago'],
            ['sha' => 'def2', 'subject' => 'second commit', 'author' => 'Ann', 'ago' => '2m ago'],
        ];
    }

    public function testTheLogRowRendersAuthorAndAge(): void
    {
        $a = new App(new FixtureGit([], [], []), log: self::logWithMeta(), pane: Pane::Log, logCursor: 1, width: 80, height: 24);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($a));
        $this->assertStringContainsString('Ann', $plain, 'author/ago were fetched and never shown (candy-git-015)');
        $this->assertStringContainsString('2m ago', $plain);
        $this->assertStringContainsString('second commit', $plain);
    }

    public function testTheSelectedLogBandStaysSealedAroundTheMeta(): void
    {
        $a = new App(new FixtureGit([], [], []), log: self::logWithMeta(), pane: Pane::Log, logCursor: 0, width: 80, height: 24);
        $frame = Renderer::render($a);
        // one reverse run carries subject AND meta: no interior reset may
        // re-open the band (the CG-09 law, extended to the new tail)
        $this->assertMatchesRegularExpression('/\x1b\[7m[^]*Joe/', $frame);
        $this->assertStringNotContainsString("\x1b[0mJoe", $frame);
        $this->assertStringNotContainsString("\x1b[0m5m ago", $frame);
    }

    public function testTheCommitViewTitlesItsChipAndHintsItsKeys(): void
    {
        $lines = [
            'commit abc10123456789abcdef0123456789abcdef01234567',
            'Author: Joe Huss <detain@interserver.net>',
            '',
            '    first commit',
            'diff --git a/f.php b/f.php',
            '@@ -0,0 +1 @@',
            '+hello',
        ];
        $dv = DiffViewer::fromCommitDetails('abc1', $lines);
        $a = new App(new FixtureGit([], [], []), diffViewer: $dv, width: 80, height: 24);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($a));
        $title = [];
        self::assertMatchesRegularExpression('/(?m)^.*diff: abc1.*$/', $plain);
        preg_match('/(?m)^(.*diff: abc1.*)$/', $plain, $title);
        $this->assertStringContainsString('commit', $title[1], 'the side chip names the view');
        $this->assertStringNotContainsString('worktree', $title[1]);
        $this->assertStringContainsString('r: revert', $plain);
        $this->assertStringNotContainsString('space: stage', $plain, 'the read-only view must not advertise staging');
    }

    public function testTheBranchBarNamesTheCapturedBase(): void
    {
        $a = new App(new FixtureGit([], [], []), collectingBranchName: true, branchName: 'x', pendingBranchBase: 'abc1', width: 80, height: 24);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($a));
        $this->assertStringContainsString('new branch from abc1: x_', $plain);
    }

    // -- candy-git-035: the untracked synthetic diff renders like any diff --

    public function testTheUntrackedViewerFramesEveryAddedLine(): void
    {
        $dv = DiffViewer::fromRawDiff('new/F.php', [
            'diff --git a/new/F.php b/new/F.php',
            'new file mode 100644',
            '--- /dev/null',
            '+++ b/new/F.php',
            '@@ -0,0 +1,2 @@',
            '+one',
            '+two',
        ]);
        $a     = new App(new FixtureGit([], [], []), diffViewer: $dv, width: 80, height: 24);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($a));

        $this->assertStringContainsString('+one', $plain);
        $this->assertStringContainsString('+two', $plain);
        $this->assertStringContainsString('new/F.php', $plain);
    }

    public function testThePartialTailWarningRidesTheViewerFrame(): void
    {
        // candy-git-040: name the damage where the user reads it, instead of
        // letting the beheaded last hunk look complete.
        $dv = DiffViewer::fromRawDiff('src/B.php', [
            'diff --git a/src/B.php b/src/B.php',
            '@@ -1 +1 @@',
            '-b',
            '+B',
            Process::CAPTURE_NOTICE,
        ]);

        $a     = new App($this->git(), diffViewer: $dv, width: 80, height: 24);
        $frame = Renderer::render($a);
        $plain = (string) preg_replace('/\x1b\[[0-9;]*m/', '', $frame);

        $this->assertTrue($dv->partialTail, 'the fixture must actually carry the flag');
        $this->assertStringContainsString('tail truncated at 4 MiB', $plain);
        $this->assertStringNotContainsString(Process::CAPTURE_NOTICE, $plain, 'the raw notice stays out of the frame');
    }

    // ---- candy-git-029 (part 2): stash filter bar ----

    public function testTheStashFilterBarShowsTheLiveQuery(): void
    {
        $sm = new \SugarCraft\Git\StashManager([
            new \SugarCraft\Git\StashEntry(0, 'aaaa1111', 'main', 'login form fix'),
        ]);
        $app = new App(
            $this->git(),
            stashManager: $sm,
            stashFiltering: true,
            stashFilterQuery: 'login',
            width: 80,
            height: 24,
        );
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($app));
        $this->assertStringContainsString('filter: login_', $plain);
        $this->assertStringContainsString('filter stashes', $plain, 'the footer names the affordance even while the bar is open');
        $this->assertMatchesRegularExpression('/\/ {2}filter stashes/', $plain, 'the footer key itself is the slash');
    }

    public function testTheHelpOverlayListsCtrlCAndThePorcelainLegend(): void
    {
        // candy-git-142 + candy-git-096: Ctrl+C was a first-class closer
        // the manual never named, and the XY porcelain pairs had no legend
        // anywhere in the app.
        $app = new App($this->git(), pane: Pane::Status, showHelp: true, width: 80, height: 70);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($app));
        $this->assertStringContainsString('ctrl+c  close overlay / quit', $plain);
        $this->assertStringContainsString('??  X=index Y=worktree; ?? new MM both UU conf', $plain);
    }

    public function testTheHelpOverlayGainsTheLogSection(): void
    {
        // candy-git-078: the log pane had actions since CG-18 but no help
        // section; the box claimed context for panes that had none.
        $app = new App($this->git(), pane: Pane::Log, showHelp: true, width: 80, height: 70);
        $plain = preg_replace('/\x1b\[[0-9;]*m/', '', Renderer::render($app));
        $this->assertStringContainsString('Log pane:', $plain);
        $this->assertStringContainsString('enter  open commit details', $plain);
    }
}
