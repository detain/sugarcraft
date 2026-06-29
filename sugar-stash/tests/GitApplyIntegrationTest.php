<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use SugarCraft\Stash\Git;
use SugarCraft\Stash\DiffViewer;
use PHPUnit\Framework\TestCase;

final class GitApplyIntegrationTest extends TestCase
{
    private string $cwd;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitapply_' . uniqid();
        mkdir($this->cwd);
        exec("git init {$this->cwd} 2>/dev/null");
        exec("git -C {$this->cwd} config user.email 'test@test.com' 2>/dev/null");
        exec("git -C {$this->cwd} config user.name 'Test' 2>/dev/null");
        file_put_contents($this->cwd . '/file.txt', "line 1\nline 2\nline 3\n");
        exec("git -C {$this->cwd} add file.txt 2>/dev/null");
        exec("git -C {$this->cwd} commit -m 'initial' 2>/dev/null");
    }

    protected function tearDown(): void
    {
        if (isset($this->cwd)) {
            $this->removeDir($this->cwd);
        }
    }

    private function removeDir(string $dir): void
    {
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
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
        $stagedDiff = $git->diff('file.txt');
        $this->assertNotEmpty($stagedDiff, 'hunk should be staged');
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
        exec("git -C {$this->cwd} diff --cached --no-color", $stagedOut);
        $this->assertEmpty($stagedOut, 'staged diff should be empty after unstage');
    }
}
