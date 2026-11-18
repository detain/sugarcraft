<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\DecodedImageCache;
use SugarCraft\Image\ImageSource;

/**
 * Rank 72 (decoded-image LRU keyed on ImageSource) and rank 71 (crop decodes
 * once): the proof is the decode counter, not timing.
 *
 * @covers \SugarCraft\Image\DecodedImageCache
 */
final class DecodedImageCacheTest extends TestCase
{
    protected function setUp(): void
    {
        DecodedImageCache::reset();
    }

    protected function tearDown(): void
    {
        DecodedImageCache::reset();
    }

    /** A distinct 2x2 PNG per $n, so each one is its own cache key. */
    private function source(int $n): ImageSource
    {
        $img = imagecreatetruecolor(2, 2);
        imagefilledrectangle($img, 0, 0, 1, 1, imagecolorallocate($img, $n, 255 - $n, 7));
        ob_start();
        imagepng($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return ImageSource::fromString($bytes);
    }

    public function testSecondCropOfTheSameSourceDoesNotDecodeAgain(): void
    {
        $sheet = $this->source(1);

        $sheet->crop(0, 0, 1, 1);
        $this->assertSame(1, DecodedImageCache::misses());

        $sheet->crop(1, 1, 1, 1);
        $sheet->resize(4, 4);
        $this->assertSame(1, DecodedImageCache::misses(), 'crop and resize on one source share one decode');
    }

    public function testEqualBytesShareOneEntryAcrossImageSourceValues(): void
    {
        $a = $this->source(9);
        $b = ImageSource::fromString($a->bytes);

        $this->assertNotSame($a, $b, 'two distinct ImageSource objects');
        $a->crop(0, 0, 1, 1);
        $b->crop(0, 0, 1, 1);

        $this->assertSame(1, DecodedImageCache::misses(), 'the key is content, not object identity');
        $this->assertSame(1, DecodedImageCache::size());
    }

    public function testCapacityIsBoundedAndTheOldestSlotIsEvicted(): void
    {
        $sources = [];
        for ($n = 0; $n <= DecodedImageCache::CAPACITY; $n++) {
            $sources[$n] = $this->source($n + 10);
            DecodedImageCache::gd($sources[$n]);
        }

        $this->assertSame(DecodedImageCache::CAPACITY, DecodedImageCache::size());
        $before = DecodedImageCache::misses();

        DecodedImageCache::gd($sources[0]);
        $this->assertSame($before + 1, DecodedImageCache::misses(), 'the least recently used entry was evicted and decodes again');
    }

    public function testAHitRefreshesRecencySoTheTouchedEntrySurvives(): void
    {
        $first = $this->source(100);
        DecodedImageCache::gd($first);
        for ($n = 1; $n < DecodedImageCache::CAPACITY; $n++) {
            DecodedImageCache::gd($this->source(100 + $n));
        }
        $this->assertSame(DecodedImageCache::CAPACITY, DecodedImageCache::size());

        // Touch the oldest entry, then push one more in: the victim is the
        // second-oldest, so the touched entry must still be a hit.
        DecodedImageCache::gd($first);
        DecodedImageCache::gd($this->source(200));
        $before = DecodedImageCache::misses();

        DecodedImageCache::gd($first);
        $this->assertSame($before, DecodedImageCache::misses(), 'the touched entry is still cached');

        DecodedImageCache::gd($this->source(101));
        $this->assertSame($before + 1, DecodedImageCache::misses(), 'the untouched second-oldest entry was the victim');
    }

    public function testUndecodableBytesThrowAndCacheNothing(): void
    {
        // Bytes that carry a PNG signature but no decodable payload: the
        // constructor accepts them (dimensions are declared), GD refuses them.
        $broken = new ImageSource("\x89PNG\r\n\x1a\nnot really", 'image/png', 2, 2);

        $this->expectException(\RuntimeException::class);
        try {
            DecodedImageCache::gd($broken);
        } finally {
            $this->assertSame(0, DecodedImageCache::size());
            $this->assertSame(0, DecodedImageCache::misses());
        }
    }
}
