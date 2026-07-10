<?php

declare(strict_types=1);

namespace SugarCraft\Stash\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Stash\Git;
use SugarCraft\Stash\Tests\Concerns\RecursiveDirCleanup;

/**
 * Real-`git` integration coverage for {@see Git::status()} rename parsing and
 * the {@see Git::isRepository()} launch probe. Both bugs shipped because the
 * production `Git` shell-out path had no temp-repo tests exercising it.
 */
final class GitStatusIntegrationTest extends TestCase
{
    use RecursiveDirCleanup;

    private string $cwd;

    protected function setUp(): void
    {
        if (!exec('git --version 2>/dev/null')) {
            $this->markTestSkipped('git not available');
        }
        $this->cwd = sys_get_temp_dir() . '/gitstatus_' . uniqid();
        mkdir($this->cwd);
        exec('git init ' . escapeshellarg($this->cwd) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.email 'test@test.com' 2>/dev/null");
        exec('git -C ' . escapeshellarg($this->cwd) . " config user.name 'Test' 2>/dev/null");
    }

    protected function tearDown(): void
    {
        if (isset($this->cwd)) {
            $this->removeDir($this->cwd);
        }
    }

    private function commitFile(string $name, string $contents): void
    {
        file_put_contents($this->cwd . '/' . $name, $contents);
        exec('git -C ' . escapeshellarg($this->cwd) . ' add ' . escapeshellarg($name) . ' 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->cwd) . " commit -m 'add {$name}' 2>/dev/null");
    }

    public function testStatusReportsRenameDestinationAsPath(): void
    {
        $this->commitFile('old.txt', "a\nb\nc\n");
        exec('git -C ' . escapeshellarg($this->cwd) . ' mv old.txt new.txt 2>/dev/null');

        $rows = (new Git($this->cwd))->status();

        $renameRow = null;
        foreach ($rows as $row) {
            if (($row['path'] ?? null) === 'new.txt') {
                $renameRow = $row;
                break;
            }
        }

        $this->assertNotNull($renameRow, 'status() must expose the rename destination as the working path');
        $this->assertSame('new.txt', $renameRow['path']);
        $this->assertSame('old.txt', $renameRow['orig_path'] ?? null, 'old path must remain available for rename-aware ops');
        $this->assertSame('R', $renameRow['index_status'] ?? null);

        // Regression guard: pre-fix substr($line, 3) left the literal
        // "old.txt -> new.txt" as the path, corrupting stage/discard/diff.
        foreach ($rows as $row) {
            $this->assertStringNotContainsString(' -> ', $row['path'] ?? '');
        }
    }

    public function testStatusLeavesPlainModificationPathIntact(): void
    {
        $this->commitFile('file.txt', "a\nb\nc\n");
        file_put_contents($this->cwd . '/file.txt', "a\nb\nc\nd\n");

        $rows = (new Git($this->cwd))->status();

        $modRow = null;
        foreach ($rows as $row) {
            if (($row['path'] ?? null) === 'file.txt') {
                $modRow = $row;
                break;
            }
        }

        $this->assertNotNull($modRow);
        $this->assertSame('file.txt', $modRow['path']);
        $this->assertArrayNotHasKey('orig_path', $modRow, 'non-rename entries carry no orig_path');
    }

    public function testIsRepositoryTrueForMainWorkingTree(): void
    {
        $this->assertTrue(Git::isRepository($this->cwd));
    }

    public function testIsRepositoryTrueInsideLinkedWorktree(): void
    {
        $this->commitFile('seed.txt', "seed\n");
        $linked = $this->cwd . '/wt';
        exec('git -C ' . escapeshellarg($this->cwd) . ' worktree add ' . escapeshellarg($linked) . ' -b feature 2>/dev/null');

        // Precondition for the bug: a linked worktree's `.git` is a FILE, so
        // the old is_dir("$cwd/.git") launch check wrongly rejected it.
        $this->assertFileExists($linked . '/.git');
        $this->assertDirectoryDoesNotExist($linked . '/.git');

        $this->assertTrue(Git::isRepository($linked), 'must launch inside a linked worktree');
    }

    public function testIsRepositoryFalseOutsideRepo(): void
    {
        $bare = sys_get_temp_dir() . '/gitstatus_norepo_' . uniqid();
        mkdir($bare);
        try {
            $this->assertFalse(Git::isRepository($bare));
        } finally {
            $this->removeDir($bare);
        }
    }

    public function testIsRepositoryFalseForEmptyPath(): void
    {
        $this->assertFalse(Git::isRepository(''));
    }
}
