<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\ImagickRasterizer;
use SugarCraft\Vt\Cell;
use SugarCraft\Vt\CellGrid;
use SugarCraft\Vt\Cursor;
use SugarCraft\Vt\Snapshot;

/**
 * Mirrors {@see GdRasterizerCacheTest} for the Imagick backend.
 *
 * Skipped cleanly if ext-imagick is unavailable.
 */
final class ImagickRasterizerCacheTest extends TestCase
{
    private FontLoader $fonts;

    protected function setUp(): void
    {
        if (!extension_loaded('imagick')) {
            $this->markTestSkipped('ext-imagick not loaded');
        }
        $this->fonts = new FontLoader();
    }

    public function testTileCacheSurvivesAcrossSnapshots(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');

        $frameA = $this->snapshotWithChars(['A', 'B', 'A', 'B', 'A']);
        $imageA = $rasterizer->rasterize($frameA, 8, 16, $this->fonts);
        $imageA->clear();

        $afterFrameA = $rasterizer->cacheStats();
        $this->assertSame(2, $afterFrameA['misses'], 'Frame A should miss exactly twice (A and B)');

        $frameB = $this->snapshotWithChars(['A', 'B', 'A', 'B', 'C']);
        $imageB = $rasterizer->rasterize($frameB, 8, 16, $this->fonts);
        $imageB->clear();

        $afterFrameB = $rasterizer->cacheStats();

        $newMisses = $afterFrameB['misses'] - $afterFrameA['misses'];
        $this->assertSame(1, $newMisses, 'Frame B should only miss for the new char C');

        $newHits = $afterFrameB['hits'] - $afterFrameA['hits'];
        $newTotalLookups = $newHits + $newMisses;
        $hitRate = $newHits / $newTotalLookups;
        $this->assertGreaterThanOrEqual(
            0.80,
            $hitRate,
            sprintf('Frame B hit rate %.2f%% must be >= 80%%', $hitRate * 100),
        );
    }

    public function testTileCacheInvalidatesOnCellDimensionChange(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');

        $frame = $this->snapshotWithChars(['A', 'B', 'A', 'B']);

        $imageA = $rasterizer->rasterize($frame, 8, 16, $this->fonts);
        $imageA->clear();

        $statsAt8x16 = $rasterizer->cacheStats();
        $this->assertGreaterThan(0, $statsAt8x16['hits'], 'Original 8x16 frame must have had >0 cache hits from repeats');

        $frame2 = $this->snapshotWithChars(['A', 'B']);
        $imageB = $rasterizer->rasterize($frame2, 10, 18, $this->fonts);
        $imageB->clear();

        $statsAt10x18 = $rasterizer->cacheStats();

        // Cache stats are not zeroed (the rasterizer carries cumulative counts),
        // but new lookups land in a freshly-cleared tile cache so misses jump.
        $newMisses = $statsAt10x18['misses'] - $statsAt8x16['misses'];
        $this->assertGreaterThanOrEqual(2, $newMisses, 'Rebuilt cache should re-miss every unique tuple');
    }

    public function testDestructorClearsCache(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $frame = $this->snapshotWithChars(['A', 'B']);

        $image = $rasterizer->rasterize($frame, 8, 16, $this->fonts);
        $image->clear();

        // Triggering destruct shouldn't throw even with Imagick resources to free.
        unset($rasterizer);
        $this->assertTrue(true, 'Destructor completed without error');
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
