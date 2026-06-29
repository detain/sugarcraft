<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Stash\App;
use SugarCraft\Stash\Renderer;
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
        $this->assertStringContainsString('SugarStash', $out);
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
        $g = new class implements \SugarCraft\Stash\GitDriver {
            public function status(): array   { throw new \RuntimeException('fatal: not a git repository'); }
            public function branches(): array { return []; }
            public function log(int $limit = 25): array { return []; }
            public function stage(string $path): void {}
            public function unstage(string $path): void {}
            public function checkout(string $branch): void {}
            public function commit(string $message): void {}
            public function stageAll(): void {}
            public function unstageAll(): void {}
            public function diff(string $path): array { return []; }
            public function discard(string $path): void {}
            public function amend(): void {}
            public function stagePatch(string $path, string $hunk): void {}
            public function unstagePatch(string $path, string $hunk): void {}
            public function createBranch(string $name): void {}
            public function deleteBranch(string $name): void {}
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
        $g = new class implements \SugarCraft\Stash\GitDriver {
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
            public function commit(string $message): void {}
            public function stageAll(): void {}
            public function unstageAll(): void {}
            public function diff(string $path): array { return []; }
            public function discard(string $path): void {}
            public function amend(): void {}
            public function stagePatch(string $path, string $hunk): void {}
            public function unstagePatch(string $path, string $hunk): void {}
            public function createBranch(string $name): void {}
            public function deleteBranch(string $name): void {}
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

    public function testTruncatesLongPath(): void
    {
        $longPath = str_repeat('a', 200);
        $g = new class($longPath) implements \SugarCraft\Stash\GitDriver {
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
            public function commit(string $message): void {}
            public function stageAll(): void {}
            public function unstageAll(): void {}
            public function diff(string $path): array { return []; }
            public function discard(string $path): void {}
            public function amend(): void {}
            public function stagePatch(string $path, string $hunk): void {}
            public function unstagePatch(string $path, string $hunk): void {}
            public function createBranch(string $name): void {}
            public function deleteBranch(string $name): void {}
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
