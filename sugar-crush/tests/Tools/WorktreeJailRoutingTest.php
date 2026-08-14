<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Tools;

use PHPUnit\Framework\TestCase;
use SugarCraft\Crush\Agents\PathJail;
use SugarCraft\Crush\Agents\PathJailConfig;
use SugarCraft\Crush\Tools\BuiltIn\Bash;
use SugarCraft\Crush\Tools\BuiltIn\Edit;
use SugarCraft\Crush\Tools\BuiltIn\Glob;
use SugarCraft\Crush\Tools\BuiltIn\Grep;
use SugarCraft\Crush\Tools\BuiltIn\Read;
use SugarCraft\Crush\Tools\BuiltIn\Write;

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
    // The path that gets OPENED is the path that got CHECKED
    //
    // Read/Edit used to prove containment with isAllowed(), which realpath()s
    // its argument, and then re-open the UNRESOLVED string. Two different
    // paths, so a symlink component swapped in between was read from somewhere
    // the jail never approved. Both now resolve once and use the canonical
    // result (crush_code.md P8.14/15).
    // =========================================================================

    public function testReadOpensTheResolvedPathWhenTheTargetIsASymlink(): void
    {
        file_put_contents($this->worktreeRoot . '/target.txt', 'canonical bytes');
        symlink($this->worktreeRoot . '/target.txt', $this->worktreeRoot . '/link.txt');

        $result = (new Read(null, 1048576, $this->worktreeJail))->execute([
            'id' => 'call_read_symlink',
            'file_path' => 'link.txt',
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame('canonical bytes', $result->content());
    }

    public function testReadRejectsSymlinkInsideWorktreePointingOutside(): void
    {
        file_put_contents($this->outsideRoot . '/secret.txt', 'secret data');
        symlink($this->outsideRoot . '/secret.txt', $this->worktreeRoot . '/leak.txt');

        $result = (new Read(null, 1048576, $this->worktreeJail))->execute([
            'id' => 'call_read_symlink_escape',
            'file_path' => 'leak.txt',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside worktree', $result->content());
        $this->assertStringNotContainsString('secret data', $result->content());
    }

    public function testEditRejectsSymlinkInsideWorktreePointingOutside(): void
    {
        file_put_contents($this->outsideRoot . '/secret.txt', 'secret data');
        symlink($this->outsideRoot . '/secret.txt', $this->worktreeRoot . '/leak.txt');

        $result = (new Edit(null, 1048576, $this->worktreeJail))->execute([
            'id' => 'call_edit_symlink_escape',
            'file_path' => 'leak.txt',
            'old_string' => 'secret',
            'new_string' => 'PWNED',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside worktree', $result->content());
        $this->assertSame('secret data', file_get_contents($this->outsideRoot . '/secret.txt'));
    }

    /**
     * A write may not be aimed through a BROKEN symlink: the ancestor walk
     * cannot see it (is_dir() and realpath() both say "missing"), so it used
     * to hand back an in-jail-looking path whose write follows the link out of
     * the jail as soon as the link's target exists.
     */
    public function testWriteRejectsCreationUnderADanglingSymlink(): void
    {
        symlink($this->outsideRoot . '/not-created-yet', $this->worktreeRoot . '/dangling');

        $result = (new Write(null, $this->worktreeJail))->execute([
            'id' => 'call_write_dangling',
            'file_path' => 'dangling/planted.txt',
            'content' => 'PWNED',
        ]);

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('outside worktree', $result->content());
        $this->assertFalse(is_dir($this->outsideRoot . '/not-created-yet'));
    }

    // =========================================================================
    // A NUL byte is a tool error, not an uncaught ValueError
    //
    // realpath()/filesize() THROW on a NUL byte rather than failing, so these
    // used to escape execute() as a crash the model never saw as a result.
    // =========================================================================

    /**
     * Every tool × every jail wiring, because the guard is per-CALL-SITE.
     *
     * The screen inside {@see \SugarCraft\Crush\Tools\PathJail} is the
     * load-bearing one and has its own direct coverage in
     * {@see PathJailContractParityTest::testEveryEntryPointRejectsANulByte()}.
     * These rows pin the other half: each tool answers in its own vocabulary
     * ('file_path'/'path') on EVERY branch, including the no-jail branch, which
     * never reaches the algorithm at all and so is the one place a missing
     * guard still crashes.
     *
     * @return iterable<string, array{string, string}> case key, argument name
     */
    public static function nulByteCallers(): iterable
    {
        yield 'Read, workspace root' => ['read-root', 'file_path'];
        yield 'Read, worktree jail' => ['read-worktree', 'file_path'];
        yield 'Read, no jail at all' => ['read-none', 'file_path'];
        yield 'Edit, workspace root' => ['edit-root', 'file_path'];
        yield 'Edit, worktree jail' => ['edit-worktree', 'file_path'];
        yield 'Edit, no jail at all' => ['edit-none', 'file_path'];
        yield 'Write, workspace root' => ['write-root', 'file_path'];
        yield 'Write, worktree jail' => ['write-worktree', 'file_path'];
        yield 'Write, no jail at all' => ['write-none', 'file_path'];
        yield 'Glob, workspace root' => ['glob-root', 'path'];
        yield 'Glob, no jail at all' => ['glob-none', 'path'];
        yield 'Grep, workspace root' => ['grep-root', 'path'];
        yield 'Grep, no jail at all' => ['grep-none', 'path'];
    }

    /**
     * @dataProvider nulByteCallers
     */
    public function testNulByteInPathIsReportedAsAToolError(string $case, string $field): void
    {
        $bad = "a\0.txt";
        $jail = $this->worktreeJail;
        $root = $this->worktreeRoot;

        $result = match ($case) {
            'read-root' => (new Read($root))->execute(['id' => 'n', $field => $bad]),
            'read-worktree' => (new Read(null, 1048576, $jail))->execute(['id' => 'n', $field => $bad]),
            'read-none' => (new Read())->execute(['id' => 'n', $field => $bad]),
            'edit-root' => (new Edit($root))->execute(['id' => 'n', $field => $bad, 'old_string' => 'a', 'new_string' => 'b']),
            'edit-worktree' => (new Edit(null, 1048576, $jail))->execute(['id' => 'n', $field => $bad, 'old_string' => 'a', 'new_string' => 'b']),
            'edit-none' => (new Edit())->execute(['id' => 'n', $field => $bad, 'old_string' => 'a', 'new_string' => 'b']),
            'write-root' => (new Write($root))->execute(['id' => 'n', $field => $bad, 'content' => 'x']),
            'write-worktree' => (new Write(null, $jail))->execute(['id' => 'n', $field => $bad, 'content' => 'x']),
            'write-none' => (new Write())->execute(['id' => 'n', $field => $bad, 'content' => 'x']),
            'glob-root' => (new Glob($root))->execute(['id' => 'n', 'pattern' => '*.php', $field => $bad]),
            'glob-none' => (new Glob())->execute(['id' => 'n', 'pattern' => '*.php', $field => $bad]),
            'grep-root' => (new Grep($root))->execute(['id' => 'n', 'pattern' => 'x', $field => $bad]),
            'grep-none' => (new Grep())->execute(['id' => 'n', 'pattern' => 'x', $field => $bad]),
        };

        $this->assertTrue($result->isError());
        $this->assertStringContainsString('NUL byte', $result->content());
    }

    /**
     * A non-string `file_path` is a tool error, not a `TypeError` out of
     * `execute()`.
     *
     * The NUL screen above is the first thing `Read::execute()` does, and it
     * sits ABOVE the try/catch that used to turn this into a readable result —
     * so adding it silently converted `file_path: 123` from a caught
     * `clearstatcache()` complaint into an uncaught throw. `Runtime` catches
     * `\Throwable` around both of its `$tool->execute()` call sites, but
     * `ToolRegistry::execute()` does not, and a crash is not a verdict.
     *
     * @dataProvider nonStringPaths
     */
    public function testNonStringPathIsReportedAsAToolError(mixed $path): void
    {
        foreach ([new Read(), new Read($this->worktreeRoot), new Read(null, 1048576, $this->worktreeJail)] as $read) {
            $result = $read->execute(['id' => 'n', 'file_path' => $path]);

            $this->assertTrue($result->isError());
            $this->assertStringContainsString('must be a string', $result->content());
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function nonStringPaths(): iterable
    {
        yield 'int' => [123];
        yield 'array' => [['a']];
        yield 'false' => [false];
        yield 'float' => [1.5];
    }

    // =========================================================================
    // A jail whose configured root is reached through a symlink
    //
    // The migration tripwire for Read/Edit. The predicate they used to call —
    // isAllowed() as it was written before P8.14/15 — compared realpath($path)
    // against the RAW $agentWorktreePath, so a worktree spelled through a
    // symlink denied every file inside itself. Both tools now go through the
    // shared algorithm, which canonicalises the ROOT as well as the path.
    // =========================================================================

    public function testReadAcceptsAnInJailFileThroughASymlinkedWorktreeRoot(): void
    {
        file_put_contents($this->worktreeRoot . '/through-link.txt', 'canonical bytes');
        $jail = $this->symlinkedJail();

        $result = (new Read(null, 1048576, $jail))->execute([
            'id' => 'call_read_linked_root',
            'file_path' => 'through-link.txt',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('canonical bytes', $result->content());
    }

    public function testEditAcceptsAnInJailFileThroughASymlinkedWorktreeRoot(): void
    {
        $target = $this->worktreeRoot . '/through-link-edit.txt';
        file_put_contents($target, 'Hello World');
        $jail = $this->symlinkedJail();

        $result = (new Edit(null, 1048576, $jail))->execute([
            'id' => 'call_edit_linked_root',
            'file_path' => 'through-link-edit.txt',
            'old_string' => 'World',
            'new_string' => 'PHP',
        ]);

        $this->assertFalse($result->isError(), $result->content());
        $this->assertSame('Hello PHP', file_get_contents($target));
    }

    /**
     * The other half of the same claim: a symlinked root does not weaken the
     * jail. Without this, the two tests above could be satisfied by a jail that
     * simply stopped checking.
     */
    public function testASymlinkedWorktreeRootStillRejectsEscapes(): void
    {
        file_put_contents($this->outsideRoot . '/secret.txt', 'secret data');
        $jail = $this->symlinkedJail();

        foreach (['../outside/secret.txt', $this->outsideRoot . '/secret.txt'] as $escape) {
            $result = (new Read(null, 1048576, $jail))->execute([
                'id' => 'call_read_linked_escape',
                'file_path' => $escape,
            ]);

            $this->assertTrue($result->isError(), "allowed '{$escape}'");
            $this->assertStringContainsString('outside worktree', $result->content());
        }
    }

    /** A jail bound to a symlink pointing at the worktree, not to the worktree. */
    private function symlinkedJail(): PathJail
    {
        $link = dirname($this->worktreeRoot) . '/worktree-link';
        if (!is_link($link)) {
            symlink($this->worktreeRoot, $link);
        }

        return new PathJail($link, new PathJailConfig());
    }

    // =========================================================================
    // Bash — worktree jail routing
    // =========================================================================

    /**
     * Bash asks the jail for its root outright instead of getting the same
     * string out of an edge case of the unchecked join helper.
     */
    public function testBashUsesTheJailRootVerbatim(): void
    {
        $result = (new Bash(null, $this->worktreeJail))->execute([
            'id' => 'call_bash_root',
            'command' => 'pwd -P',
        ]);

        $this->assertFalse($result->isError());
        $this->assertSame(realpath($this->worktreeJail->root()), trim($result->content()));
    }

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
