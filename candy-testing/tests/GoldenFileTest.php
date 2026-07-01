<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SugarCraft\Testing\Tests\Concerns\TemporaryDirectoryTrait;
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
}
