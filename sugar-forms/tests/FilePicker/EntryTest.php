<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\FilePicker;

use PHPUnit\Framework\TestCase;
use SugarCraft\Forms\FilePicker\Entry;

/**
 * Tests for {@see Entry} — one filesystem entry discovered by {@see FilePicker}.
 */
final class EntryTest extends TestCase
{
    // =========================================================================
    // Constructor and basic properties
    // =========================================================================

    public function testConstructorSetsAllProperties(): void
    {
        $e = new Entry(
            name: 'README.md',
            isDir: false,
            isHidden: false,
            size: 1024,
            mtime: 1700000000,
        );

        $this->assertSame('README.md', $e->name);
        $this->assertFalse($e->isDir);
        $this->assertFalse($e->isHidden);
        $this->assertSame(1024, $e->size);
        $this->assertSame(1700000000, $e->mtime);
    }

    public function testConstructorDefaultsForDirectory(): void
    {
        $e = new Entry(name: 'docs', isDir: true, isHidden: false);

        $this->assertTrue($e->isDir);
        $this->assertFalse($e->isHidden);
        $this->assertSame(0, $e->size);
        $this->assertSame(0, $e->mtime);
    }

    public function testConstructorDefaultsForHiddenFile(): void
    {
        $e = new Entry(name: '.env', isDir: false, isHidden: true);

        $this->assertFalse($e->isDir);
        $this->assertTrue($e->isHidden);
    }

    // =========================================================================
    // path() — builds full path from cwd
    // =========================================================================

    public function testPathAppendsNameToNormalizedCwd(): void
    {
        $e = new Entry(name: 'file.txt', isDir: false, isHidden: false);
        $this->assertSame('/home/user/file.txt', $e->path('/home/user'));
        $this->assertSame('/home/user/file.txt', $e->path('/home/user/'));
    }

    public function testPathWithAbsoluteCwd(): void
    {
        $e = new Entry(name: 'file.txt', isDir: false, isHidden: false);
        $this->assertSame('/tmp/file.txt', $e->path('/tmp'));
    }

    public function testPathNormalizesTrailingSlashOnCwd(): void
    {
        $e = new Entry(name: 'a.txt', isDir: false, isHidden: false);
        $this->assertSame('/a/b/a.txt', $e->path('/a/b/'));
        $this->assertSame('/a/b/a.txt', $e->path('/a/b'));
    }

    // =========================================================================
    // display() — renders name with trailing slash for directories
    // =========================================================================

    public function testDisplayAppendsSlashForDirectories(): void
    {
        $e = new Entry(name: 'src', isDir: true, isHidden: false);
        $this->assertSame('src/', $e->display());
    }

    public function testDisplayShowsNameOnlyForFiles(): void
    {
        $e = new Entry(name: 'file.txt', isDir: false, isHidden: false);
        $this->assertSame('file.txt', $e->display());
    }

    // =========================================================================
    // icon() — single-character icon based on type/extension
    // =========================================================================

    public function testIconReturnsFolderForDirectories(): void
    {
        $e = new Entry(name: 'mydir', isDir: true, isHidden: false);
        $this->assertSame('📁', $e->icon());
    }

    public function testIconReturnsScriptGlyphForPhp(): void
    {
        $e = new Entry(name: 'index.php', isDir: false, isHidden: false);
        $this->assertSame('📜', $e->icon());
    }

    public function testIconReturnsDocumentGlyphForMarkdown(): void
    {
        $e = new Entry(name: 'README.md', isDir: false, isHidden: false);
        $this->assertSame('📄', $e->icon());
    }

    public function testIconReturnsConfigGlyphForJson(): void
    {
        $e = new Entry(name: 'config.json', isDir: false, isHidden: false);
        $this->assertSame('🧾', $e->icon());
    }

    public function testIconReturnsImageGlyphForPng(): void
    {
        $e = new Entry(name: 'photo.png', isDir: false, isHidden: false);
        $this->assertSame('🖼', $e->icon());
    }

    public function testIconReturnsMusicGlyphForMp3(): void
    {
        $e = new Entry(name: 'song.mp3', isDir: false, isHidden: false);
        $this->assertSame('🎵', $e->icon());
    }

    public function testIconReturnsVideoGlyphForMp4(): void
    {
        $e = new Entry(name: 'video.mp4', isDir: false, isHidden: false);
        $this->assertSame('🎬', $e->icon());
    }

    public function testIconReturnsArchiveGlyphForZip(): void
    {
        $e = new Entry(name: 'archive.zip', isDir: false, isHidden: false);
        $this->assertSame('📦', $e->icon());
    }

    public function testIconReturnsGenericDocumentForUnknownExtension(): void
    {
        $e = new Entry(name: 'weird.xyz', isDir: false, isHidden: false);
        $this->assertSame('📄', $e->icon());
    }

    public function testIconIsCaseInsensitiveForExtension(): void
    {
        $e1 = new Entry(name: 'file.PHP', isDir: false, isHidden: false);
        $e2 = new Entry(name: 'file.JPG', isDir: false, isHidden: false);
        $e3 = new Entry(name: 'file.ZIP', isDir: false, isHidden: false);

        $this->assertSame('📜', $e1->icon());
        $this->assertSame('🖼', $e2->icon());
        $this->assertSame('📦', $e3->icon());
    }

    // =========================================================================
    // formatSize() — SI-style compact size string
    // =========================================================================

    public function testFormatSizeReturnsEmptyForDirectory(): void
    {
        $e = new Entry(name: 'dir', isDir: true, isHidden: false);
        $this->assertSame('', $e->formatSize());
    }

    public function testFormatSizeBytes(): void
    {
        $e = new Entry(name: 'file', isDir: false, isHidden: false, size: 512);
        $this->assertSame('512B', $e->formatSize());
    }

    public function testFormatSizeKiloBytes(): void
    {
        $e = new Entry(name: 'file', isDir: false, isHidden: false, size: 1024);
        $this->assertSame('1.0K', $e->formatSize());
    }

    public function testFormatSizeMegaBytes(): void
    {
        $e = new Entry(name: 'file', isDir: false, isHidden: false, size: 1024 * 1024);
        $this->assertSame('1.0M', $e->formatSize());
    }

    public function testFormatSizeGigaBytes(): void
    {
        $e = new Entry(name: 'file', isDir: false, isHidden: false, size: 1024 * 1024 * 1024);
        $this->assertSame('1.0G', $e->formatSize());
    }

    public function testFormatSizeTeraBytes(): void
    {
        $e = new Entry(name: 'file', isDir: false, isHidden: false, size: 1024 * 1024 * 1024 * 1024);
        $this->assertSame('1.0T', $e->formatSize());
    }

    public function testFormatSizeDecimalMegaBytes(): void
    {
        // 4.2 MB = 4,404,953 bytes
        $e = new Entry(name: 'file', isDir: false, isHidden: false, size: 4404953);
        $this->assertSame('4.2M', $e->formatSize());
    }

    public function testFormatSizeZeroBytes(): void
    {
        $e = new Entry(name: 'file', isDir: false, isHidden: false, size: 0);
        $this->assertSame('0B', $e->formatSize());
    }
}
