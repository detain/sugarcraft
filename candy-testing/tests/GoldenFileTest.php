<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCraft\Testing\Concerns\TemporaryDirectoryTrait;
use SugarCraft\Testing\Snapshot\GoldenFile;

final class GoldenFileTest extends TestCase
{
    use TemporaryDirectoryTrait;

    private string $tmpFile;

    protected function getTempDirSuffix(): string
    {
        return 'golden';
    }

    protected function setUp(): void
    {
        $this->setUpTemporaryDirectory();
        $this->tmpFile = $this->tmpDir . '/test.golden';
    }

    protected function tearDown(): void
    {
        $this->tmpFile = '';
        $this->tearDownTemporaryDirectory();
    }

    public function testLoadReturnsNullOnMissingFile(): void
    {
        $result = GoldenFile::load($this->tmpDir . '/nonexistent.golden');

        $this->assertNull($result);
    }

    public function testLoadReturnsFileContents(): void
    {
        $content = "\x1b[1;32mHello\x1b[0m\n";
        file_put_contents($this->tmpFile, $content);

        $result = GoldenFile::load($this->tmpFile);

        $this->assertSame($content, $result);
    }

    public function testSaveWritesBytes(): void
    {
        $content = "\x1b[1;33mGold\x1b[0m";

        GoldenFile::save($this->tmpFile, $content);

        $this->assertSame($content, file_get_contents($this->tmpFile));
    }

    public function testSaveCreatesParentDirectories(): void
    {
        $nested = $this->tmpDir . '/nested/path/test.golden';

        GoldenFile::save($nested, 'content');

        $this->assertSame('content', file_get_contents($nested));
    }

    public function testLoadSaveRoundTripPreservesBytes(): void
    {
        $original = "Line1\nLine2\n\x1b[2J\x1b[H";
        GoldenFile::save($this->tmpFile, $original);

        $loaded = GoldenFile::load($this->tmpFile);

        $this->assertSame($original, $loaded);
    }

    public function testResolveBuildsFixturesPath(): void
    {
        $baseDir = '/home/test';
        $relative = 'counter.golden';

        $resolved = GoldenFile::resolve($baseDir, $relative);

        $this->assertSame('/home/test/fixtures/counter.golden', $resolved);
    }

    public function testResolveHandlesLeadingSlash(): void
    {
        $baseDir = '/home/test';
        $relative = '/counter.golden';

        $resolved = GoldenFile::resolve($baseDir, $relative);

        $this->assertSame('/home/test/fixtures/counter.golden', $resolved);
    }

    public function testResolveAllowsNestedSubdirectory(): void
    {
        // A legitimate nested golden path must resolve unchanged.
        $resolved = GoldenFile::resolve('/home/test', 'sub/dir/counter.golden');

        $this->assertSame('/home/test/fixtures/sub/dir/counter.golden', $resolved);
    }

    public function testResolveAllowsInternalDotDotThatStaysInsideBase(): void
    {
        // '..' that does not escape the fixtures base is permitted and the
        // raw (unnormalized) path is returned for byte-stable behaviour.
        $resolved = GoldenFile::resolve('/home/test', 'sub/../counter.golden');

        $this->assertSame('/home/test/fixtures/sub/../counter.golden', $resolved);
    }

    public function testResolveRejectsDotDotTraversalEscape(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/escapes the fixtures directory/');
        GoldenFile::resolve('/home/test', '../../../etc/passwd');
    }

    public function testResolveRejectsTraversalThatClimbsOutThenBackIn(): void
    {
        // Climbs above <base>/fixtures before re-descending — still an escape.
        $this->expectException(\RuntimeException::class);
        GoldenFile::resolve('/home/test', 'ok/../../../test/fixtures-evil/x.golden');
    }

    public function testResolveNormalizesPathWithDotSegments(): void
    {
        // Path with . and empty segments should be normalized away.
        $resolved = GoldenFile::resolve('/home/test', 'sub/./dir/../file.golden');

        $this->assertSame('/home/test/fixtures/sub/file.golden', $resolved);
    }

    public function testResolveNormalizesMultipleDotDotSegments(): void
    {
        // Multiple '..' in succession should each be processed.
        $resolved = GoldenFile::resolve('/home/test', 'a/b/c/../../d.golden');

        $this->assertSame('/home/test/fixtures/a/d.golden', $resolved);
    }

    public function testResolveHandlesPureDotPath(): void
    {
        // A path that is just '.' should normalize to empty but valid path.
        // Actually '.' alone would just be skipped as empty segment after split.
        $resolved = GoldenFile::resolve('/home/test', './././file.golden');

        $this->assertSame('/home/test/fixtures/file.golden', $resolved);
    }

    public function testSaveFailsOnUnwritablePath(): void
    {
        // Attempting to save to a path where file_put_contents would fail
        // should throw a RuntimeException.
        // On Unix, /dev/full simulates disk full; on Windows we test with
        // a read-only directory path.
        if (is_dir('/dev/full')) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessageMatches('/write_failed/');
            GoldenFile::save('/dev/full/test.golden', 'content');
        } else {
            // Skip on platforms without /dev/full
            $this->markTestSkipped('/dev/full not available on this platform');
        }
    }

    public function testResolveHandlesAbsolutePathAsRelative(): void
    {
        // A path with leading slash is treated as fixtures-relative (stripped),
        // not as an absolute filesystem path.
        $resolved = GoldenFile::resolve('/home/test', '/subdir/file.golden');

        $this->assertSame('/home/test/fixtures/subdir/file.golden', $resolved);
    }

    public function testResolveEmptyRelativeBecomesFileInFixtures(): void
    {
        // Edge case: if relative is empty or just '/', what happens?
        // ltrim('/') on empty string gives '/' which stays.
        // But resolve() with empty string would give fixtures/FILENAME since
        // ltrim('', '/') is empty, then '/' + '' = '/', prepended to base gives
        // base fixtures path with trailing slash.
        // This is an edge case that shouldn't happen in practice.
        $resolved = GoldenFile::resolve('/home/test', '');
        $this->assertSame('/home/test/fixtures', $resolved);
    }
}
