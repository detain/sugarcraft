<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;

/**
 * Tests for FontLoader.
 */
final class FontLoaderTest extends TestCase
{
    public function testLoadReturnsPathForBundledFont(): void
    {
        $loader = new FontLoader();
        $path = $loader->load('DejaVuSansMono', 14.0, 'regular');

        $this->assertNotEmpty($path);
        $this->assertFileExists($path);
        $this->assertStringEndsWith('.ttf', $path);
    }

    public function testLoadReturnsPathForBoldFont(): void
    {
        $loader = new FontLoader();
        $path = $loader->load('DejaVuSansMono', 14.0, 'bold');

        $this->assertNotEmpty($path);
        $this->assertFileExists($path);
    }

    public function testLoadThrowsForMissingFont(): void
    {
        $loader = new FontLoader();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Font not found');
        $loader->load('NonExistentFontXYZ', 14.0, 'regular');
    }

    public function testResolveReturnsPath(): void
    {
        $loader = new FontLoader();
        $path = $loader->resolve('DejaVuSansMono', 'regular');

        $this->assertNotNull($path);
        $this->assertFileExists($path);
    }

    public function testResolveReturnsNullForMissingFont(): void
    {
        $loader = new FontLoader();
        $path = $loader->resolve('NonExistentFontXYZ', 'regular');

        $this->assertNull($path);
    }

    public function testLastResolvedPathAfterLoad(): void
    {
        $loader = new FontLoader();
        $loader->load('DejaVuSansMono', 14.0, 'regular');

        $this->assertNotNull($loader->lastResolvedPath());
        $this->assertFileExists($loader->lastResolvedPath());
    }

    public function testSystemDirsReturnsArray(): void
    {
        $dirs = FontLoader::systemDirs();

        $this->assertIsArray($dirs);
        $this->assertNotEmpty($dirs);
    }

    public function testLoadItalicStyleFallsBackToRegular(): void
    {
        $loader = new FontLoader();
        $path = $loader->load('DejaVuSansMono', 14.0, 'italic');

        $this->assertNotEmpty($path);
        $this->assertFileExists($path);
    }

    public function testLoadBoldItalicFallsBackToBold(): void
    {
        $loader = new FontLoader();
        $path = $loader->load('DejaVuSansMono', 14.0, 'bolditalic');

        $this->assertNotEmpty($path);
        $this->assertFileExists($path);
    }
}
