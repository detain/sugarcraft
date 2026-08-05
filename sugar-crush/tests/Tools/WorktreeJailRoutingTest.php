<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail;
use SugarCraft\Crush\Agents\PathJailConfig;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Read;

/**
 * Tests for worktree PathJail routing in Edit/Read/Bash tools.
 *
 * When a Teammate has isolation:worktree and a worktreePath, all file
 * operations must be routed through the worktree's PathJail instance.
 *
 * @see Edit
 * @see Read
 * @see Bash
 */
final class WorktreeJailRoutingTest extends TestCase
{
    private string $worktreeRoot;
    private string $outsideRoot;
    private PathJail $worktreeJail;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/sugarcrush_wtjail_' . uniqid();
        $this->worktreeRoot = $base . '/worktree';
        $this->outsideRoot = $base . '/outside';
        mkdir($this->worktreeRoot, 0777, true);
        mkdir($this->outsideRoot, 0777, true);

        $this->worktreeJail = new PathJail($this->worktreeRoot, new PathJailConfig());
    }

    protected function tearDown(): void
    {
        $this->rrmdir(dirname($this->worktreeRoot));
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            if (is_link($path) || is_file($path)) {
                unlink($path);
            } elseif (is_dir($path)) {
                $this->rrmdir($path);
            }
        }
        rmdir($dir);
    }

    // =========================================================================
    // Edit — worktree jail routing
    // =========================================================================

    public function testEditWithWorktreeJailAcceptsRelativePathInWorktree(): void
    {
        $testFile = $this->worktreeRoot . '/test.txt';
        file_put_contents($testFile, 'Hello World');

        $edit = new Edit(null, 1048576, $this->worktreeJail);

        $result = $edit->execute([
            'id' => 'call_edit_1',
            'file_path' => 'test.txt',
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('updated', $result->content());
        $this->assertSame('Hello PHP', file_get_contents($testFile));
    }

    public function testEditWithWorktreeJailAcceptsAbsolutePathInWorktree(): void
    {
        $testFile = $this->worktreeRoot . '/abs_test.txt';
        file_put_contents($testFile, 'Hello World');

        $edit = new Edit(null, 1048576, $this->worktreeJail);

        $result = $edit->execute([
            'id' => 'call_edit_abs',
            'file_path' => $testFile,
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString('updated', $result->content());
    }

    public function testEditWithWorktreeJailRejectsPathOutsideWorktree(): void
    {
        $outsideFile = $this->outsideRoot . '/secret.txt';
        file_put_contents($outsideFile, 'secret data');

        $edit = new Edit(null, 1048576, $this->worktreeJail);

        $result = $edit->execute([
            'id' => 'call_edit_escape',
            'file_path' => $outsideFile,
            'old_string' => 'secret',
            'new_string' => 'REPLACED',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside worktree', $result->content());
        // Original file must be untouched
        $this->assertSame('secret data', file_get_contents($outsideFile));
    }

    public function testEditWithWorktreeJailRejectsDotDotEscape(): void
    {
        $testFile = $this->worktreeRoot . '/safe.txt';
        file_put_contents($testFile, 'safe content');

        $edit = new Edit(null, 1048576, $this->worktreeJail);

        $result = $edit->execute([
            'id' => 'call_edit_dotdot',
            'file_path' => '../outside/escape.txt',
            'old_string' => 'safe',
            'new_string' => 'REPLACED',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside worktree', $result->content());
    }

    // =========================================================================
    // Read — worktree jail routing
    // =========================================================================

    public function testReadWithWorktreeJailAcceptsRelativePathInWorktree(): void
    {
        $testFile = $this->worktreeRoot . '/readme.txt';
        file_put_contents($testFile, 'readable content');

        $read = new Read(null, 1048576, $this->worktreeJail);

        $result = $read->execute([
            'id' => 'call_read_1',
            'file_path' => 'readme.txt',
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame('readable content', $result->content());
    }

    public function testReadWithWorktreeJailAcceptsAbsolutePathInWorktree(): void
    {
        $testFile = $this->worktreeRoot . '/abs_readme.txt';
        file_put_contents($testFile, 'absolute readable');

        $read = new Read(null, 1048576, $this->worktreeJail);

        $result = $read->execute([
            'id' => 'call_read_abs',
            'file_path' => $testFile,
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame('absolute readable', $result->content());
    }

    public function testReadWithWorktreeJailRejectsPathOutsideWorktree(): void
    {
        $outsideFile = $this->outsideRoot . '/secret.txt';
        file_put_contents($outsideFile, 'secret data');

        $read = new Read(null, 1048576, $this->worktreeJail);

        $result = $read->execute([
            'id' => 'call_read_escape',
            'file_path' => $outsideFile,
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside worktree', $result->content());
    }

    public function testReadWithWorktreeJailRejectsDotDotEscape(): void
    {
        $read = new Read(null, 1048576, $this->worktreeJail);

        $result = $read->execute([
            'id' => 'call_read_dotdot',
            'file_path' => '../outside/secret.txt',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside worktree', $result->content());
    }

    public function testReadWithWorktreeJailEmptyPathReturnsWorktreeRoot(): void
    {
        $read = new Read(null, 1048576, $this->worktreeJail);

        $result = $read->execute([
            'id' => 'call_read_empty',
            'file_path' => '',
        ]);

        // Empty path resolves to worktree root which is a directory, not a file
        $this->assertTrue($result->isError());
    }

    // =========================================================================
    // Bash — worktree jail routing
    // =========================================================================

    public function testBashWithWorktreeJailChangesToWorktreeDirectory(): void
    {
        $bash = new Bash(null, $this->worktreeJail);

        $result = $bash->execute([
            'id' => 'call_bash_pwd',
            'command' => 'pwd',
        ]);

        $this->assertFalse($result->isError());
        $this->assertStringContainsString($this->worktreeRoot, $result->content());
    }

    public function testBashWithWorktreeJailRunsGitCommandsInWorktree(): void
    {
        // Initialize a git repo in the worktree
        chdir($this->worktreeRoot);
        exec('git init -q 2>/dev/null');
        exec('git config user.email "test@test.com" 2>/dev/null');
        exec('git config user.name "Test" 2>/dev/null');
        file_put_contents($this->worktreeRoot . '/file.txt', 'content');
        exec('git -C ' . escapeshellarg($this->worktreeRoot) . ' add . 2>/dev/null');
        exec('git -C ' . escapeshellarg($this->worktreeRoot) . ' commit -q -m "init" 2>/dev/null');

        $bash = new Bash(null, $this->worktreeJail);

        $result = $bash->execute([
            'id' => 'call_bash_git',
            'command' => 'git status --short',
        ]);

        $this->assertFalse($result->isError());
        // Should show nothing untracked/unchanged since everything is committed
        $this->assertSame('', trim($result->content()));
    }

    public function testBashWorktreeJailTakesPrecedenceOverRoot(): void
    {
        // Create a root directory that differs from the worktree
        $rootDir = sys_get_temp_dir() . '/sugarcrush_root_' . uniqid();
        mkdir($rootDir, 0777, true);

        try {
            $bash = new Bash($rootDir, $this->worktreeJail);

            $result = $bash->execute([
                'id' => 'call_bash_precedence',
                'command' => 'pwd',
            ]);

            // worktreeJail should take precedence over root
            $this->assertStringContainsString($this->worktreeRoot, $result->content());
        } finally {
            rmdir($rootDir);
        }
    }

    public function testBashWithoutWorktreeJailFallsBackToRoot(): void
    {
        $rootDir = sys_get_temp_dir() . '/sugarcrush_fallback_' . uniqid();
        mkdir($rootDir, 0777, true);

        try {
            $bash = new Bash($rootDir);

            $result = $bash->execute([
                'id' => 'call_bash_fallback',
                'command' => 'pwd',
            ]);

            $this->assertStringContainsString($rootDir, $result->content());
        } finally {
            rmdir($rootDir);
        }
    }

    // =========================================================================
    // Fallback — no jail set, original behavior preserved
    // =========================================================================

    public function testEditWithoutJailFallsBackToRootJail(): void
    {
        $rootDir = sys_get_temp_dir() . '/sugarcrush_fallback_edit_' . uniqid();
        mkdir($rootDir, 0777, true);

        try {
            $testFile = $rootDir . '/fallback.txt';
            file_put_contents($testFile, 'fallback content');

            $edit = new Edit($rootDir);

            $result = $edit->execute([
                'id' => 'call_edit_fallback',
                'file_path' => $testFile,
                'old_string' => 'fallback',
                'new_string' => 'WORKS',
            ]);

            $this->assertFalse($result->isError());
            $this->assertSame('WORKS content', file_get_contents($testFile));
        } finally {
            $this->rrmdir($rootDir);
        }
    }

    public function testReadWithoutJailFallsBackToRootJail(): void
    {
        $rootDir = sys_get_temp_dir() . '/sugarcrush_fallback_read_' . uniqid();
        mkdir($rootDir, 0777, true);

        try {
            $testFile = $rootDir . '/read_fallback.txt';
            file_put_contents($testFile, 'fallback read');

            $read = new Read($rootDir);

            $result = $read->execute([
                'id' => 'call_read_fallback',
                'file_path' => 'read_fallback.txt',
            ]);

            $this->assertFalse($result->isError());
            $this->assertSame('fallback read', $result->content());
        } finally {
            $this->rrmdir($rootDir);
        }
    }
}
