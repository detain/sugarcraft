<?php

declare(strict_types=1);

namespace SugarCraft\Git\Tests;

use SugarCraft\Git\Git;
use SugarCraft\Git\DiffViewer;
use SugarCraft\Git\Tests\Concerns\RecursiveDirCleanup;
use PHPUnit\Framework\TestCase;

final class GitApplyIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;
    private string $cwd;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitapply_' . uniqid();
        mkdir($this->cwd);
        exec('git init ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
        file_put_contents($this->cwd . '/file.txt', "line 1\nline 2\nline 3\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' add file.txt 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " commit -m 'initial' 2>/dev/null");
    }

    protected function tearDown(): void
    {
        if (isset($this->cwd)) {
            $this->removeDir($this->cwd);
        }
    }

    public function testStageSingleHunkApplies(): void
    {
        file_put_contents($this->cwd . '/file.txt', "line 1\nline 2 modified\nline 3\n");
        $git = new Git($this->cwd);
        $diffLines = $git->diff('file.txt');
        $this->assertNotEmpty($diffLines);
        $dv = DiffViewer::fromRawDiff('file.txt', $diffLines);
        $this->assertGreaterThan(0, $dv->hunkCount());
        $patch = $dv->currentHunkPatch();
        $this->assertStringStartsWith('diff --git', $patch);
        $this->assertStringContainsString('--- a/file.txt', $patch);
        $this->assertStringContainsString('+++ b/file.txt', $patch);
        $git->stagePatch('file.txt', $patch);
        // Fail-able leg (candy-git-072): the old assertNotEmpty over this same
        // unstaged call could never fail — run() answers a drained diff as
        // [''], which is a NON-EMPTY ARRAY. The honest contract is: the
        // unstaged diff drains to empty content, the cached diff gains the
        // hunk, and the worktree bytes stay untouched by a --cached apply.
        $this->assertSame(
            '',
            implode("\n", $git->diff('file.txt')),
            'unstaged diff must drain to empty once the only hunk is staged',
        );
        $cached = implode("\n", $git->diff('file.txt', true));
        $this->assertStringContainsString('+line 2 modified', $cached, 'cached diff must carry the staged hunk');
        $this->assertStringContainsString(
            "line 2 modified\n",
            (string) file_get_contents($this->cwd . '/file.txt'),
            'a --cached apply never rewrites worktree bytes',
        );
    }

    public function testCurrentHunkPatchIncludesFileHeader(): void
    {
        file_put_contents($this->cwd . '/file.txt', "line 1\nline 2 modified\nline 3\n");
        $git = new Git($this->cwd);
        $diffLines = $git->diff('file.txt');
        $dv = DiffViewer::fromRawDiff('file.txt', $diffLines);
        $patch = $dv->currentHunkPatch();
        $this->assertStringStartsWith('diff --git', $patch);
        $this->assertStringContainsString('--- a/', $patch);
        $this->assertStringContainsString('+++ b/', $patch);
    }

    public function testUnstagePatchReversesHunk(): void
    {
        file_put_contents($this->cwd . '/file.txt', "line 1\nline 2 modified\nline 3\n");
        $git = new Git($this->cwd);
        $diffLines = $git->diff('file.txt');
        $dv = DiffViewer::fromRawDiff('file.txt', $diffLines);
        $patch = $dv->currentHunkPatch();
        $git->stagePatch('file.txt', $patch);
        $git->unstagePatch('file.txt', $patch);
        exec('git -C ' . escapeshellarg($this->cwd) . ' diff --cached --no-color', $stagedOut);
        $this->assertEmpty($stagedOut, 'staged diff should be empty after unstage');
    }
}
