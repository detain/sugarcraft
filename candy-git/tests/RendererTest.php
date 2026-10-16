<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Git\App;
use SugarCraft\Git\CherryPick;
use SugarCraft\Git\DiffViewer;
use SugarCraft\Git\InteractiveRebase;
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
    }

    public function testRenderShowsErrorBanner(): void
    {
        $g = new class implements \SugarCraft\Git\GitDriver {
            public function status(): array   { throw new \RuntimeException('fatal: not a git repository'); }
            public function branches(): array { return []; }
            public function log(int $_limit = 25): array { return []; }
            public function stage(string $path): void {}
            public function unstage(string $path): void {}
            public function checkout(string $branch): void {}
            public function commit(string $_message): void {}
            public function stageAll(): void {}
            public function unstageAll(): void {}
            public function diff(string $path): array { return []; }
            public function discard(string $path): void {}
            public function amend(): void {}
            public function stagePatch(string $path, string $hunk): void {}
            public function unstagePatch(string $path, string $hunk): void {}
            public function createBranch(string $name): void {}
            public function deleteBranch(string $_name): void {}
            public function merge(string $branch): void {}
            public function rebaseContinue(): void {}
            public function rebaseAbort(): void {}
            public function rebaseSkip(): void {}
            public function reset(): void {}
            public function stashList(): array { return []; }
            public function stashApply(string $_stashRef): void {}
            public function stashDrop(string $_stashRef): void {}
            public function cherryPick(string $_commit): void {}
            public function cherryPickContinue(): void {}
            public function cherryPickAbort(): void {}
            public function worktreeList(): array { return []; }
            public function worktreeAdd(string $_path, string $_branch): void {}
            public function worktreeRemove(string $_path): void {}
            public function rebaseInProgress(): bool { return false; }
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
        $g = new class implements \SugarCraft\Git\GitDriver {
            public function status(): array {
                return [
                    ['branch_summary' => 'main'],
                    ['index_status' => 'M', 'work_status' => ' ', 'path' => "src/\x07bell.php"],
                ];
            }
            public function branches(): array {
                return [['name' => "branch-\x07bell", 'sha' => 'abc', 'current' => true]];
            }
            public function log(int $limit = 25): array {
                return [['sha' => 'abc', 'subject' => "evil\x07bell", 'author' => 'Joe', 'ago' => '5m']];
            }
            public function stage(string $path): void {}
            public function unstage(string $path): void {}
            public function checkout(string $branch): void {}
            public function commit(string $_message): void {}
            public function stageAll(): void {}
            public function unstageAll(): void {}
            public function diff(string $path): array { return []; }
            public function discard(string $path): void {}
            public function amend(): void {}
            public function stagePatch(string $path, string $hunk): void {}
            public function unstagePatch(string $path, string $hunk): void {}
            public function createBranch(string $name): void {}
            public function deleteBranch(string $_name): void {}
            public function merge(string $branch): void {}
            public function rebaseContinue(): void {}
            public function rebaseAbort(): void {}
            public function rebaseSkip(): void {}
            public function reset(): void {}
            public function stashList(): array { return []; }
            public function stashApply(string $stashRef): void {}
            public function stashDrop(string $stashRef): void {}
            public function cherryPick(string $commit): void {}
            public function cherryPickContinue(): void {}
            public function cherryPickAbort(): void {}
            public function worktreeList(): array { return []; }
            public function worktreeAdd(string $path, string $branch): void {}
            public function worktreeRemove(string $path): void {}
            public function rebaseInProgress(): bool { return false; }
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

    public function testRenderWorktreeOverlayWithEntries(): void
    {
        $g = new FixtureGit([], [], []);
        $entries = [
            new \SugarCraft\Git\WorktreeEntry('/path/one', 'main', false, 'abc1234'),
        ];
        $wt = new \SugarCraft\Git\Worktrees($entries);
        $a = new \SugarCraft\Git\App($g, worktrees: $wt);
        $out = \SugarCraft\Git\Renderer::render($a);
        $this->assertStringContainsString('worktrees', $out);
        $this->assertStringContainsString('/path/one', $out);
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
        $cp = \SugarCraft\Git\CherryPick::collecting()->withChar('a')->withChar('b');
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
            public function commit(string $_message): void {}
            public function stageAll(): void {}
            public function unstageAll(): void {}
            public function diff(string $path): array { return []; }
            public function discard(string $path): void {}
            public function amend(): void {}
            public function stagePatch(string $path, string $hunk): void {}
            public function unstagePatch(string $path, string $hunk): void {}
            public function createBranch(string $name): void {}
            public function deleteBranch(string $_name): void {}
            public function merge(string $branch): void {}
            public function rebaseContinue(): void {}
            public function rebaseAbort(): void {}
            public function rebaseSkip(): void {}
            public function reset(): void {}
            public function stashList(): array { return []; }
            public function stashApply(string $stashRef): void {}
            public function stashDrop(string $stashRef): void {}
            public function cherryPick(string $commit): void {}
            public function cherryPickContinue(): void {}
            public function cherryPickAbort(): void {}
            public function worktreeList(): array { return []; }
            public function worktreeAdd(string $path, string $branch): void {}
            public function worktreeRemove(string $path): void {}
            public function rebaseInProgress(): bool { return false; }
        };
        $a = App::start($g);
        $out = Renderer::render($a);
        // Long path should be truncated with ellipsis
        $this->assertStringContainsString('…', $out);
        // The long string of 'a' characters should not appear in full - it should be truncated
        $this->assertStringNotContainsString(str_repeat('a', 50), $out);
    }
}
