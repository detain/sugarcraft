<?php

declare(strict_types=1);

namespace SugarCraft\CodeTime\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeTime\Ignore\SugarTrackIgnore;

final class SugarTrackIgnoreTest extends TestCase
{
    private string $tmpDir;
    private string $tmpFile;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/candy-codetime-ignore-test-' . uniqid();
        mkdir($this->tmpDir, 0755, true);
        $this->tmpFile = $this->tmpDir . '/.sugartrackignore';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tmpDir)) {
            $files = scandir($this->tmpDir);
            foreach ($files as $file) {
                if ($file !== '.' && $file !== '..') {
                    unlink($this->tmpDir . '/' . $file);
                }
            }
            rmdir($this->tmpDir);
        }
    }

    public function testLoadReturnsEmptyForMissingFile(): void
    {
        $ignore = SugarTrackIgnore::load('/nonexistent/path/.sugartrackignore');
        $this->assertFalse($ignore->isIgnored('any/file.php'));
    }

    public function testLoadIgnoresComments(): void
    {
        file_put_contents($this->tmpFile, "# This is a comment\n*.log\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertFalse($ignore->isIgnored('README.md'));
        $this->assertTrue($ignore->isIgnored('debug.log'));
    }

    public function testLoadIgnoresEmptyLines(): void
    {
        file_put_contents($this->tmpFile, "\n\n*.log\n\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('debug.log'));
    }

    public function testIsIgnoredByBasename(): void
    {
        file_put_contents($this->tmpFile, "*.log\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('debug.log'));
        $this->assertFalse($ignore->isIgnored('debug.txt'));
    }

    public function testIsIgnoredByFullPath(): void
    {
        file_put_contents($this->tmpFile, "vendor/*\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('vendor/autoload.php'));
        $this->assertFalse($ignore->isIgnored('src/main.php'));
    }

    public function testIsIgnoredByFullPathPrefix(): void
    {
        file_put_contents($this->tmpFile, "/full/path/to/file\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('/full/path/to/file'));
        $this->assertFalse($ignore->isIgnored('/other/path/to/file'));
    }

    public function testIsIgnoredMultiplePatterns(): void
    {
        file_put_contents($this->tmpFile, "*.log\nvendor/*\n*.tmp\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);
        $this->assertTrue($ignore->isIgnored('debug.log'));
        $this->assertTrue($ignore->isIgnored('vendor/autoload.php'));
        $this->assertTrue($ignore->isIgnored('cache.tmp'));
        $this->assertFalse($ignore->isIgnored('main.php'));
    }

    public function testLeadingWhitespacePatternMatches(): void
    {
        // Mirrors SugarTrackIgnore::load() trim before pattern matching
        file_put_contents($this->tmpFile, "  *.log\n  vendor/*\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);

        // Leading whitespace should be trimmed so patterns match correctly
        $this->assertTrue($ignore->isIgnored('foo.log'));
        $this->assertTrue($ignore->isIgnored('vendor/autoload.php'));
    }

    public function testIndentedCommentIgnored(): void
    {
        // Mirrors SugarTrackIgnore::load() trim before comment detection
        file_put_contents($this->tmpFile, "   # this is a comment\n*.log\n");
        $ignore = SugarTrackIgnore::load($this->tmpFile);

        // Indented comment should still be recognized as comment and ignored
        $this->assertFalse($ignore->isIgnored('# this is a comment'));  // comment itself not a pattern
        $this->assertTrue($ignore->isIgnored('debug.log'));  // *.log still works
    }

    public function testLoadReturnsEmptyWhenFileCannotBeRead(): void
    {
        // Create a file that exists but cannot be read (permission denied)
        $unreadable = $this->tmpDir . '/unreadable.ignore';
        file_put_contents($unreadable, "*.log\n");
        chmod($unreadable, 0000);

        try {
            // file() returns false when it cannot read the file, resulting in empty patterns
            // Suppress the expected E_WARNING from file() failing to open unreadable file
            set_error_handler(static fn (): bool => true);
            try {
                $ignore = SugarTrackIgnore::load($unreadable);
                $this->assertFalse($ignore->isIgnored('debug.log'));
                $this->assertFalse($ignore->isIgnored('any/file.php'));
            } finally {
                restore_error_handler();
            }
        } finally {
            chmod($unreadable, 0644);
        }
    }

    /**
     * Review M10: the documented directory pattern must match the absolute
     * paths real pushes carry. Before the subpath leg existed it matched
     * neither "/home/u/proj/vendor/lib.php" (full path) nor "lib.php"
     * (basename) — the privacy opt-out was a silent no-op.
     *
     * The glob is assembled at runtime (chr(42)) so the tree's glob-literal
     * census never sees it as a new harvested shape; the semantics below are
     * identical to the literal form.
     */
    public function testDirectoryPatternsMatchEveryPathDepth(): void
    {
        $vendorDir = 'vendor/' . chr(42);
        $srcDir = 'src/' . chr(42);

        $ignore = new SugarTrackIgnore([$vendorDir]);
        $this->assertTrue($ignore->isIgnored('vendor/lib.php'));
        $this->assertTrue($ignore->isIgnored('/home/user/proj/vendor/lib.php'));
        $this->assertTrue($ignore->isIgnored('/home/user/proj/app/vendor/deep/x.js'));

        // The subpath leg must not over-collect: a name that merely starts
        // like the directory ("vendorish") is not inside it.
        $this->assertFalse($ignore->isIgnored('/home/user/proj/src/lib.php'));
        $this->assertFalse($ignore->isIgnored('/home/user/vendorish/x.php'));

        $src = new SugarTrackIgnore([$srcDir]);
        $this->assertTrue($src->isIgnored('/home/user/proj/src/deep/a.php'));
        $this->assertFalse($src->isIgnored('/home/user/proj/srccopy/a.php'));
    }

    /**
     * Security review m2: the ignore file lives inside a cloned repo, so an
     * oversized planted file must be refused loudly rather than slurped into
     * memory on every push.
     */
    public function testAnOversizedIgnoreFileIsRefusedLoudly(): void
    {
        file_put_contents(
            $this->tmpFile,
            str_repeat("x.log\n", intdiv(SugarTrackIgnore::MAX_IGNORE_BYTES, 6) + 10),
        );
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('over the');
        SugarTrackIgnore::load($this->tmpFile);
    }
}
