<?php

declare(strict_types=1);

namespace SugarCraft\Mosaic\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Mosaic\AdaptiveImage;
use SugarCraft\Mosaic\ImageSource;
use SugarCraft\Mosaic\Mosaic;
use SugarCraft\Mosaic\PrecomputedImage;

final class AdaptiveImageTest extends TestCase
{
    public function testRenderCachesResult(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $adaptive = new AdaptiveImage($image, $mosaic);

        $first = $adaptive->render(4, 2);
        $this->assertNotEmpty($first);

        // Second render of same size should hit cache.
        $second = $adaptive->render(4, 2);
        $this->assertSame($first, $second);
    }

    public function testCacheIsSizeKeyed(): void
    {
        $mosaic = new Mosaic(
            new \SugarCraft\Mosaic\Renderer\HalfBlockRenderer(),
            \SugarCraft\Mosaic\Capability::universal(),
            null,
            null,
            null,
        );
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $adaptive = new AdaptiveImage($image, $mosaic, maxCache: 4);

        $out4x2 = $adaptive->render(4, 2);
        $out8x4 = $adaptive->render(8, 4);
        $out4x2Again = $adaptive->render(4, 2);

        // Different sizes must produce different output.
        $this->assertNotSame($out4x2, $out8x4);
        // Same size as before must return identical bytes (from cache).
        $this->assertSame($out4x2, $out4x2Again);
    }

    public function testLruEviction(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        // maxCache = 2
        $adaptive = new AdaptiveImage($image, $mosaic, maxCache: 2);

        $adaptive->render(1, 1);
        $adaptive->render(2, 1);
        $adaptive->render(3, 1);

        // Cache should have evicted the oldest entry (1x1).
        $this->assertSame(2, $adaptive->cacheSize());
    }

    public function testLruPromotesRecentAccess(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        // maxCache = 2
        $adaptive = new AdaptiveImage($image, $mosaic, maxCache: 2);

        $adaptive->render(1, 1);
        $adaptive->render(2, 1);

        // Touch 1x1 again — it becomes most recent.
        $adaptive->render(1, 1);

        // Now render a new entry (3x1) — should evict 2x1, not 1x1.
        $adaptive->render(3, 1);

        $this->assertSame(2, $adaptive->cacheSize());
        // 1x1 should still be cached (was touched more recently).
        $out1x1 = $adaptive->render(1, 1);
        $this->assertNotEmpty($out1x1);
    }

    public function testClearCache(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $adaptive = new AdaptiveImage($image, $mosaic, maxCache: 4);

        $adaptive->render(4, 2);
        $adaptive->render(8, 4);
        $this->assertSame(2, $adaptive->cacheSize());

        $adaptive->clearCache();
        $this->assertSame(0, $adaptive->cacheSize());
    }

    public function testPrecomputeReturnsPrecomputedImage(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $pre = $mosaic->precompute($image, 8, 4);

        $this->assertInstanceOf(PrecomputedImage::class, $pre);
        $this->assertSame(8, $pre->cellWidth());
        $this->assertSame(4, $pre->cellHeight());
        $this->assertNotEmpty($pre->bytes());
    }

    public function testPrecomputeIsCached(): void
    {
        $mosaic = Mosaic::sixel();
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $pre1 = $mosaic->precompute($image, 8, 4);
        $pre2 = $mosaic->precompute($image, 8, 4);

        // Same size should hit cache — same object instance or same bytes.
        $this->assertSame($pre1->bytes(), $pre2->bytes());
    }

    public function testAdaptiveFromMosaic(): void
    {
        $mosaic = Mosaic::sixel()->withScale(\SugarCraft\Mosaic\Scale::Crop);
        $image  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $adaptive = $mosaic->adaptive($image);
        $this->assertInstanceOf(AdaptiveImage::class, $adaptive);

        $bytes = $adaptive->render(8, 4);
        $this->assertNotEmpty($bytes);
    }
}
