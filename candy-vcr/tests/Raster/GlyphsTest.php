<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\Glyphs;

/**
 * Tests for Glyphs tile cache.
 */
final class GlyphsTest extends TestCase
{
    private FontLoader $fonts;

    protected function setUp(): void
    {
        $this->fonts = new FontLoader();
    }

    public function testTileReturnsSameInstanceOnSecondCall(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $tile1 = $glyphs->tile('A', 7, 0, false, false, false);
        $tile2 = $glyphs->tile('A', 7, 0, false, false, false);

        $this->assertSame($tile1, $tile2);
    }

    public function testTileReturnsDifferentInstanceForDifferentChars(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $tileA = $glyphs->tile('A', 7, 0, false, false, false);
        $tileB = $glyphs->tile('B', 7, 0, false, false, false);

        $this->assertNotSame($tileA, $tileB);
    }

    public function testTileReturnsDifferentInstanceForDifferentFg(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $tile1 = $glyphs->tile('A', 7, 0, false, false, false);
        $tile2 = $glyphs->tile('A', 1, 0, false, false, false);

        $this->assertNotSame($tile1, $tile2);
    }

    public function testTileReturnsDifferentInstanceForBold(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $tile1 = $glyphs->tile('A', 7, 0, false, false, false);
        $tile2 = $glyphs->tile('A', 7, 0, true, false, false);

        $this->assertNotSame($tile1, $tile2);
    }

    public function testTileReturnsDifferentInstanceForUnderline(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $tile1 = $glyphs->tile('A', 7, 0, false, false, false);
        $tile2 = $glyphs->tile('A', 7, 0, false, false, true);

        $this->assertNotSame($tile1, $tile2);
    }

    public function testTileWideReturnsDoubleWidthTile(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $tile = $glyphs->tileWide('文', 7, 0, false, false, false);

        $this->assertEquals(16, imagesx($tile));
        $this->assertEquals(16, imagesy($tile));
    }

    public function testTileWideReturnsDifferentFromRegularTile(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $tileRegular = $glyphs->tile('文', 7, 0, false, false, false);
        $tileWide = $glyphs->tileWide('文', 7, 0, false, false, false);

        $this->assertNotSame($tileRegular, $tileWide);
        $this->assertEquals(imagesx($tileRegular) * 2, imagesx($tileWide));
    }

    public function testMeasureReturnsCorrectDimensions(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $narrow = $glyphs->measure('A');
        $this->assertEquals([8, 16], $narrow);

        $wide = $glyphs->measure('文');
        $this->assertEquals([16, 16], $wide);
    }

    public function testTileForSpaceCharacter(): void
    {
        $glyphs = new Glyphs(8, 16, $this->fonts);

        $tile = $glyphs->tile(' ', 7, 0, false, false, false);

        $this->assertEquals(8, imagesx($tile));
        $this->assertEquals(16, imagesy($tile));
    }
}
