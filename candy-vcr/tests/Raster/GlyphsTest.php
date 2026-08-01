<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\Glyphs;
use SugarCraft\Vt\Theme;

/**
 * Tests for Glyphs cache and rendering methods.
 */
final class GlyphsTest extends TestCase
{
    private function createGlyphs(): Glyphs
    {
        return new Glyphs(
            cellW: 8,
            cellH: 16,
            fonts: new \SugarCraft\Vcr\Raster\FontLoader(),
            fontFamily: 'JetBrainsMono',
            fontSize: 14,
            theme: Theme::tokyoNight(),
        );
    }

    public function testAccessors(): void
    {
        $g = $this->createGlyphs();

        $this->assertSame(8, $g->cellWidth());
        $this->assertSame(16, $g->cellHeight());
        $this->assertSame('JetBrainsMono', $g->fontFamily());
        $this->assertSame(14, $g->fontSize());
        $this->assertInstanceOf(Theme::class, $g->theme());
    }

    public function testCacheStatsInitial(): void
    {
        $g = $this->createGlyphs();
        $stats = $g->cacheStats();

        $this->assertSame(0, $stats['hits']);
        $this->assertSame(0, $stats['misses']);
        $this->assertSame(0, $stats['evictions']);
    }

    public function testTileCaching(): void
    {
        $g = $this->createGlyphs();

        // First call is a miss
        $tile1 = $g->tile('A', 15, 0, false, false, false);
        $stats1 = $g->cacheStats();
        $this->assertSame(0, $stats1['hits']);
        $this->assertSame(1, $stats1['misses']);

        // Second call with same params is a hit
        $tile2 = $g->tile('A', 15, 0, false, false, false);
        $stats2 = $g->cacheStats();
        $this->assertSame(1, $stats2['hits']);
        $this->assertSame(1, $stats2['misses']);

        // Same image returned for cache hit
        $this->assertSame($tile1, $tile2);
    }

    public function testTileWithDifferentParamsAreDifferent(): void
    {
        $g = $this->createGlyphs();

        $tile1 = $g->tile('A', 15, 0, false, false, false);
        $tile2 = $g->tile('A', 0, 15, false, false, false); // different colors
        $tile3 = $g->tile('B', 15, 0, false, false, false); // different char

        $stats = $g->cacheStats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(3, $stats['misses']);
    }

    public function testTileWideCharCaching(): void
    {
        $g = $this->createGlyphs();

        // Wide char uses separate cache
        $tile1 = $g->tileWide('日', 15, 0, false, false, false);
        $stats1 = $g->cacheStats();
        $this->assertSame(0, $stats1['hits']);
        $this->assertSame(1, $stats1['misses']);

        // Second call is a hit
        $tile2 = $g->tileWide('日', 15, 0, false, false, false);
        $stats2 = $g->cacheStats();
        $this->assertSame(1, $stats2['hits']);
        $this->assertSame(1, $stats2['misses']);
    }

    public function testTileWideAndNarrowAreSeparate(): void
    {
        $g = $this->createGlyphs();

        // Same char but different cache key for wide vs narrow
        $tile1 = $g->tile('A', 15, 0, false, false, false);
        $tile2 = $g->tileWide('A', 15, 0, false, false, false);

        $stats = $g->cacheStats();
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(2, $stats['misses']);
    }

    public function testMeasure(): void
    {
        $g = $this->createGlyphs();

        // Narrow char
        [$w1, $h1] = $g->measure('A');
        $this->assertSame(8, $w1);
        $this->assertSame(16, $h1);

        // Wide char (CJK)
        [$w2, $h2] = $g->measure('日');
        $this->assertSame(16, $w2); // 2x cell width
        $this->assertSame(16, $h2);
    }

    public function testBoldItalicUnderlineAttrs(): void
    {
        $g = $this->createGlyphs();

        // Different attributes should produce different tiles
        $normal = $g->tile('A', 15, 0, false, false, false);
        $bold = $g->tile('A', 15, 0, true, false, false);
        $italic = $g->tile('A', 15, 0, false, true, false);
        $underline = $g->tile('A', 15, 0, false, false, true);

        $stats = $g->cacheStats();
        // 4 misses (4 different attribute combinations)
        $this->assertSame(0, $stats['hits']);
        $this->assertSame(4, $stats['misses']);
    }
}
