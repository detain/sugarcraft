<?php

declare(strict_types=1);

namespace SugarCraft\SuperCandy\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\SuperCandy\PreviewPane;

final class PreviewPaneTest extends TestCase
{
    public function testDefaultValues(): void
    {
        $pp = PreviewPane::forFile('');
        $this->assertSame('', $pp->filePath);
        $this->assertSame(40, $pp->previewWidth);
        $this->assertSame(20, $pp->previewHeight);
        $this->assertSame('', $pp->error);
    }

    public function testWithWidth(): void
    {
        $pp = PreviewPane::forFile('/some/path');
        $pp2 = $pp->withWidth(60);
        $this->assertSame(40, $pp->previewWidth);
        $this->assertSame(60, $pp2->previewWidth);
    }

    public function testWithWidthRejectsZero(): void
    {
        $pp = PreviewPane::forFile('/some/path');
        $pp2 = $pp->withWidth(0);
        $this->assertSame(40, $pp->previewWidth);
        $this->assertNotSame(0, $pp2->previewWidth);
        $this->assertNotSame('', $pp2->lastError());
    }

    public function testWithWidthRejectsNegative(): void
    {
        $pp = PreviewPane::forFile('/some/path');
        $pp2 = $pp->withWidth(-5);
        $this->assertNotSame(-5, $pp2->previewWidth);
        $this->assertNotSame('', $pp2->lastError());
    }

    public function testWithHeight(): void
    {
        $pp = PreviewPane::forFile('/some/path');
        $pp2 = $pp->withHeight(30);
        $this->assertSame(20, $pp->previewHeight);
        $this->assertSame(30, $pp2->previewHeight);
    }

    public function testIsImageReturnsFalseForEmptyPath(): void
    {
        $pp = PreviewPane::forFile('');
        $this->assertFalse($pp->isImage());
    }

    public function testIsImageReturnsFalseForNonexistentFile(): void
    {
        $pp = PreviewPane::forFile('/nonexistent/path/file.png');
        $this->assertFalse($pp->isImage());
    }

    public function testIsImageReturnsFalseForNonImageExtension(): void
    {
        $pp = PreviewPane::forFile('/tmp/test.txt');
        $this->assertFalse($pp->isImage());
    }

    public function testIsDirectoryReturnsFalseForEmptyPath(): void
    {
        $pp = PreviewPane::forFile('');
        $this->assertFalse($pp->isDirectory());
    }

    public function testFormatSize(): void
    {
        $pp = PreviewPane::forFile('');

        $this->assertSame('0 B', $pp->formatSize(0));
        $this->assertSame('1 B', $pp->formatSize(1));
        $this->assertSame('1 KB', $pp->formatSize(1024));
        $this->assertSame('1 KB', $pp->formatSize(1025));
        $this->assertSame('1.5 KB', $pp->formatSize(1536));
        $this->assertSame('1 MB', $pp->formatSize(1048576));
        $this->assertSame('1 GB', $pp->formatSize(1073741824));
        $this->assertSame('1 TB', $pp->formatSize(1099511627776));
    }

    public function testFormatSizeHandlesNegative(): void
    {
        $pp = PreviewPane::forFile('');
        $this->assertStringContainsString('unknown', $pp->formatSize(-1));
    }

    public function testFormatMode(): void
    {
        $pp = PreviewPane::forFile('');

        // Regular file rwxrwxrwx
        $this->assertSame('-rwxrwxrwx', $pp->formatMode(0o777));

        // Read-only
        $this->assertSame('-r--r--r--', $pp->formatMode(0o444));

        // Directory
        $this->assertSame('drwxr-xr-x', $pp->formatMode(0o40755));
    }

    public function testRenderReturnsPlaceholderForEmptyPath(): void
    {
        $pp = PreviewPane::forFile('');
        $output = $pp->render();
        $this->assertStringContainsString('no file', $output);
    }

    public function testRenderReturnsPlaceholderForNonexistentFile(): void
    {
        $pp = PreviewPane::forFile('/nonexistent/file.txt');
        $output = $pp->render();
        $this->assertStringContainsString('not found', $output);
    }

    public function testImmutability(): void
    {
        $original = PreviewPane::forFile('/some/path');
        $modified = $original->withWidth(80);

        $this->assertNotSame($original, $modified);
        $this->assertSame(40, $original->previewWidth);
        $this->assertSame(80, $modified->previewWidth);
    }

    public function testFluentChain(): void
    {
        $pp = PreviewPane::forFile('/path/to/file.png')
            ->withWidth(60)
            ->withHeight(25);

        $this->assertSame('/path/to/file.png', $pp->filePath);
        $this->assertSame(60, $pp->previewWidth);
        $this->assertSame(25, $pp->previewHeight);
    }

    public function testSupportedImageExtensionsDefaults(): void
    {
        $pp = PreviewPane::forFile('/tmp/test.png');
        $this->assertContains('png', $pp->supportedImageExtensions);
        $this->assertContains('jpg', $pp->supportedImageExtensions);
        $this->assertContains('jpeg', $pp->supportedImageExtensions);
        $this->assertContains('gif', $pp->supportedImageExtensions);
        $this->assertContains('webp', $pp->supportedImageExtensions);
        $this->assertContains('bmp', $pp->supportedImageExtensions);
    }

    public function testResolveFileTypeReturnsOctetStreamForUnknown(): void
    {
        $pp = PreviewPane::forFile('/tmp/test.xyz');
        $this->assertSame('application/octet-stream', $pp->resolveFileType());
    }

    public function testResolveFileTypeReturnsTextForTxt(): void
    {
        $pp = PreviewPane::forFile('/tmp/test.txt');
        $this->assertSame('text/plain', $pp->resolveFileType());
    }

    public function testResolveFileTypeReturnsImageTypeForPng(): void
    {
        $pp = PreviewPane::forFile('/tmp/test.png');
        $this->assertSame('image/png', $pp->resolveFileType());
    }

    public function testResolveFileTypeReturnsArchiveTypeForZip(): void
    {
        $pp = PreviewPane::forFile('/tmp/test.zip');
        $this->assertSame('archive/zip', $pp->resolveFileType());
    }

    public function testResolveFileTypeReturnsVideoType(): void
    {
        $pp = PreviewPane::forFile('/tmp/test.mp4');
        $this->assertSame('video/mp4', $pp->resolveFileType());
    }

    public function testResolveFileTypeReturnsAudioType(): void
    {
        $pp = PreviewPane::forFile('/tmp/test.mp3');
        $this->assertSame('audio/mp3', $pp->resolveFileType());
    }
}
