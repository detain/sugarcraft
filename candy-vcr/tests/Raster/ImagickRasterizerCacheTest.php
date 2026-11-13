<?php

declare(strict_types=1);

namespace SugarCraft\Vcr\Tests\Raster;

use PHPUnit\Framework\TestCase;
use SugarCraft\Vcr\Raster\FontLoader;
use SugarCraft\Vcr\Raster\ImagickRasterizer;
use SugarCraft\Vterm\Cell;
use SugarCraft\Vterm\Buffer\Buffer;
use SugarCraft\Vterm\Cursor;
use SugarCraft\Vterm\Snapshot;

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
        $this->expectNotToPerformAssertions();

        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $frame = $this->snapshotWithChars(['A', 'B']);

        $image = $rasterizer->rasterize($frame, 8, 16, $this->fonts);
        $image->clear();

        // Triggering destruct shouldn't throw even with Imagick resources to free.
        unset($rasterizer);
    }

    /**
     * Clone-count probe (finding 015): a cache hit must hand out the stored
     * instance itself, not a per-cell copy. Before the fix this returned
     * `clone $tile`, re-copying the pixel cache once per grid cell per frame.
     */
    public function testCacheHitReturnsTheSharedInstance(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $rasterizer->rasterize($this->snapshotWithChars(['A']), 8, 16, $this->fonts)->clear();

        $cacheProperty = new \ReflectionProperty($rasterizer, 'tileCache');
        $cacheProperty->setAccessible(true);
        /** @var array<string, \Imagick> $cache */
        $cache = $cacheProperty->getValue($rasterizer);
        $cached = $cache[array_key_first($cache)];
        $this->assertInstanceOf(\Imagick::class, $cached, 'one tile must be cached after the miss');

        $getTile = new \ReflectionMethod($rasterizer, 'getTile');
        $getTile->setAccessible(true);
        $returned = $getTile->invoke($rasterizer, new Cell('A', 7, 0), 8, 16, $this->fonts, 8, false);

        $this->assertSame($cached, $returned, 'a hit must reuse the cached instance, not clone it');
        $this->assertSame(1, $rasterizer->cacheStats()['hits'], 'the direct reflection call must have hit the cache');
    }

    /**
     * The contract that makes the shared-instance return safe: compositing a
     * tile repeatedly must leave the cached tile bit-for-bit intact. Mirrors
     * the real tape workload — the same tile instance composited across whole
     * frames, nothing else touching the wand between reads.
     */
    public function testCachedTileSurvivesCompositingUnmutated(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $rasterizer->rasterize($this->snapshotWithChars(['A', 'B']), 8, 16, $this->fonts)->clear();

        $cacheProperty = new \ReflectionProperty($rasterizer, 'tileCache');
        $cacheProperty->setAccessible(true);
        /** @var array<string, \Imagick> $cache */
        $cache = $cacheProperty->getValue($rasterizer);
        $tileA = $cache[array_key_first($cache)];
        // Blob first: the first encoding read normalizes the pixel cache, which
        // is what makes the value stable afterwards (measured).
        $blob = (string) $tileA->getImageBlob();

        for ($frame = 0; $frame < 5; $frame++) {
            $rasterizer->rasterize($this->snapshotWithChars(['A', 'A', 'B']), 8, 16, $this->fonts)->clear();
        }

        $this->assertSame($blob, (string) $tileA->getImageBlob(), 'cached tile pixels must be stable across frames');
    }

    /**
     * Pixel-identical acceptance: the cache-disabled path (fresh tile per
     * lookup) and the shared-instance hit path must render the same bytes.
     */
    public function testCacheEnabledAndDisabledRenderPixelIdentical(): void
    {
        $rasterizer = new ImagickRasterizer(14, 'DejaVuSansMono');
        $frame = $this->snapshotWithChars(['A', 'B', 'A', 'C', 'B', 'A']);

        $withCache = $rasterizer->rasterize($frame, 8, 16, $this->fonts);
        $againWithCache = $rasterizer->rasterize($frame, 8, 16, $this->fonts);

        $rasterizer->setCacheDisabled(true);
        $withoutCache = $rasterizer->rasterize($frame, 8, 16, $this->fonts);

        $this->assertSame(
            $withCache->getImageSignature(),
            $againWithCache->getImageSignature(),
            'second pass (all shared-instance hits) must render pixel-identically',
        );
        $this->assertSame(
            $withCache->getImageSignature(),
            $withoutCache->getImageSignature(),
            'shared cached tiles must render pixel-identically to fresh per-lookup tiles',
        );

        $withCache->clear();
        $againWithCache->clear();
        $withoutCache->clear();
    }

    /**
     * @param list<string> $chars
     */
    private function snapshotWithChars(array $chars): Snapshot
    {
        $cols = count($chars);
        $grid = new Buffer($cols, 1);

        foreach ($chars as $i => $char) {
            $cell = new Cell($char, 7, 0);
            $grid->put(0, $i, $cell);
        }

        $cursor = new Cursor(0, 0, 0, false);

        return new Snapshot($grid, $cursor, 0.0);
    }
}
