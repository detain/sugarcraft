<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\GdRasterizer;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;

/**
 * Asserts that the GdRasterizer's persistent Glyphs cache survives across
 * `rasterize()` calls — the core perf lever from vcr_use.md §6.
 */
final class GdRasterizerCacheTest extends TestCase
{
    private FontLoader $fonts;

    protected function setUp(): void
    {
        $this->fonts = new FontLoader();
    }

    public function testCacheSurvivesAcrossSnapshots(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');

        // Frame A — five cells using two unique (char, fg, bg) tuples.
        $frameA = $this->snapshotWithChars(['A', 'B', 'A', 'B', 'A']);
        $imageA = $rasterizer->rasterize($frameA, 8, 16, $this->fonts);
        imagedestroy($imageA);

        $afterFrameA = $rasterizer->cacheStats();
        // 2 unique tuples → 2 misses; 3 repeats → 3 hits (plus the cursor cell).
        $this->assertSame(2, $afterFrameA['misses'], 'Frame A should miss exactly twice (A and B)');

        // Frame B — re-uses (A, B) tuples plus one new char (C).
        $frameB = $this->snapshotWithChars(['A', 'B', 'A', 'B', 'C']);
        $imageB = $rasterizer->rasterize($frameB, 8, 16, $this->fonts);
        imagedestroy($imageB);

        $afterFrameB = $rasterizer->cacheStats();

        // Only the new tuple should miss on the second frame.
        $newMisses = $afterFrameB['misses'] - $afterFrameA['misses'];
        $this->assertSame(1, $newMisses, 'Frame B should only miss for the new char C');

        // Hit rate on frame B alone: 4 repeats (A, B, A, B) + cursor hit ≥ 4 hits vs 1 miss.
        $newHits = $afterFrameB['hits'] - $afterFrameA['hits'];
        $newTotalLookups = $newHits + $newMisses;
        $hitRate = $newHits / $newTotalLookups;
        $this->assertGreaterThanOrEqual(
            0.80,
            $hitRate,
            sprintf('Frame B hit rate %.2f%% must be >= 80%%', $hitRate * 100),
        );
    }

    public function testCacheInvalidatesOnCellDimensionChange(): void
    {
        $rasterizer = new GdRasterizer(14, 'DejaVuSansMono');

        // Use repeats so the first frame builds up some hits we can verify
        // get reset when the cache rebuilds at a new cell dimension.
        $frame = $this->snapshotWithChars(['A', 'B', 'A', 'B']);

        $imageA = $rasterizer->rasterize($frame, 8, 16, $this->fonts);
        imagedestroy($imageA);

        $statsAt8x16 = $rasterizer->cacheStats();
        $this->assertGreaterThan(0, $statsAt8x16['hits'], 'Original 8x16 frame must have had >0 cache hits from repeats');

        // Different cell dimensions → fingerprint mismatch → cache rebuilds.
        $frame2 = $this->snapshotWithChars(['A', 'B']);
        $imageB = $rasterizer->rasterize($frame2, 10, 18, $this->fonts);
        imagedestroy($imageB);

        $statsAt10x18 = $rasterizer->cacheStats();

        // After rebuild, the stats counters belong to the new Glyphs instance,
        // so hits should be 0 (no repeats in the 2-cell second frame).
        $this->assertSame(0, $statsAt10x18['hits'], 'Rebuilt cache should have zero hits initially');
        $this->assertGreaterThanOrEqual(2, $statsAt10x18['misses'], 'Rebuilt cache should re-miss every unique tuple');
    }

    public function testCacheDisabledRebuildsGlyphsEachFrame(): void
    {
        // First measure with cache enabled (the new default).
        $enabled = new GdRasterizer(14, 'DejaVuSansMono');
        $frame = $this->snapshotWithChars(['A', 'B', 'A', 'B', 'A']);

        $img1 = $enabled->rasterize($frame, 8, 16, $this->fonts);
        imagedestroy($img1);
        $img2 = $enabled->rasterize($frame, 8, 16, $this->fonts);
        imagedestroy($img2);

        $enabledStats = $enabled->cacheStats();
        // 2 unique tuples → 2 misses ever; the rest are hits across both frames.
        $this->assertSame(2, $enabledStats['misses'], 'Enabled cache: only 2 misses across two identical frames');

        // Now with cache disabled, each frame rebuilds Glyphs from scratch,
        // so misses are paid per frame.
        $disabled = new GdRasterizer(14, 'DejaVuSansMono');
        $disabled->setCacheDisabled(true);

        $img3 = $disabled->rasterize($frame, 8, 16, $this->fonts);
        imagedestroy($img3);
        $statsAfterFirstDisabled = $disabled->cacheStats();
        $this->assertSame(2, $statsAfterFirstDisabled['misses'], 'Disabled cache: first frame misses are the same as enabled');

        $img4 = $disabled->rasterize($frame, 8, 16, $this->fonts);
        imagedestroy($img4);
        $statsAfterSecondDisabled = $disabled->cacheStats();
        // The cacheStats() reports the CURRENT Glyphs's counters; since the
        // disabled path builds a new Glyphs per frame, frame two reports its
        // own 2 misses (not 4 cumulative).
        $this->assertSame(2, $statsAfterSecondDisabled['misses'], 'Disabled cache: each frame rebuilds Glyphs (2 misses per frame)');
    }

    /**
     * @param list<string> $chars
     */
    private function snapshotWithChars(array $chars): Snapshot
    {
        $cols = count($chars);
        $grid = new CellGrid($cols, 1);

        foreach ($chars as $i => $char) {
            $cell = new Cell($char, 7, 0);
            $grid = $grid->set(0, $i, $cell);
        }

        $cursor = new Cursor(0, 0, 0, false);

        return new Snapshot($grid, $cursor, 0.0);
    }
}
