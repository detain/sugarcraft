<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;

/**
 * @covers \SugarCraft\Image\ImageSource
 */
final class ImageSourceTest extends TestCase
{
    public function testCropThrowsOutOfBounds(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        // 4x2 image: x [0,3], y [0,1].  x+w=50+10=60 > width=4 → OOB.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Crop region .* is outside image bounds/');
        $image->crop(50, 0, 10, 2);
    }

    public function testResizeThrowsNonPositiveWidth(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Resize dimensions must be positive/');
        $image->resize(0, 10);
    }

    public function testResizeThrowsNonPositiveHeight(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Resize dimensions must be positive/');
        $image->resize(10, -1);
    }

    public function testResizeThrowsBothNonPositive(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Resize dimensions must be positive/');
        $image->resize(0, 0);
    }

    // MINOR-1 (C-10 review): the public ctor is the boundary — a hand-built
    // non-positive source must throw HERE, not deep inside Scale (C-11).

    public function testConstructorRefusesZeroWidth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dimensions 0x8 must be positive/');
        new ImageSource('bytes', 'image/png', 0, 8);
    }

    public function testConstructorRefusesNegativeHeight(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/dimensions 8x-1 must be positive/');
        new ImageSource('bytes', 'image/png', 8, -1);
    }

    public function testConstructorAcceptsOnePixelSource(): void
    {
        $image = new ImageSource('bytes', 'image/png', 1, 1);
        $this->assertSame(1, $image->width);
        $this->assertSame(1, $image->height);
    }

    public function testCropThrowsOnNegativeX(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $image->crop(-1, 0, 2, 2);
    }

    public function testCropThrowsOnNegativeY(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $image->crop(0, -1, 2, 2);
    }

    public function testCropThrowsOnZeroWidth(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $image->crop(0, 0, 0, 2);
    }

    public function testCropThrowsOnZeroHeight(): void
    {
        $image = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $this->expectException(\InvalidArgumentException::class);
        $image->crop(0, 0, 2, 0);
    }

    // ---- decompression-bomb (MAX_PIXELS) guards ------------------------

    public function testFromFileRejectsImageAboveMaxPixels(): void
    {
        // 4x2.png = 8 pixels; a ceiling of 4 must reject it BEFORE GD decodes.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum of 4 pixels/');

        ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png', maxPixels: 4);
    }

    public function testFromFileAllowsImageAtMaxPixels(): void
    {
        // 8 pixels with a ceiling of exactly 8 is allowed (strictly-greater test).
        $img = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png', maxPixels: 8);

        $this->assertSame(4, $img->width);
        $this->assertSame(8, $img->maxPixels);
    }

    public function testFromStringRejectsImageAboveMaxPixels(): void
    {
        $bytes = (string) file_get_contents(__DIR__ . '/fixtures/4x2.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum/');

        ImageSource::fromString($bytes, maxPixels: 4);
    }

    public function testDefaultMaxPixelsCeilingIsApplied(): void
    {
        $img = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $this->assertSame(ImageSource::MAX_PIXELS, $img->maxPixels);
    }

    public function testWithMaxPixelsRejectsLoweringBelowCurrentSize(): void
    {
        $img = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum/');

        $img->withMaxPixels(4);
    }

    public function testWithMaxPixelsReturnsNewInstanceWhenWithinCap(): void
    {
        $img  = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png');
        $next = $img->withMaxPixels(100);

        $this->assertSame(100, $next->maxPixels);
        $this->assertSame(4, $next->width);
        $this->assertSame(ImageSource::MAX_PIXELS, $img->maxPixels);  // original unchanged
    }

    public function testMaxPixelsCeilingPropagatesThroughResize(): void
    {
        // A ceiling carried on the source must reject an oversized resize
        // target before GD allocates the enlarged buffer.
        $img = ImageSource::fromFile(__DIR__ . '/fixtures/4x2.png', maxPixels: 8);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum/');

        $img->resize(10, 10);  // 100 pixels > ceiling of 8
    }

    /**
     * MAJOR-3: the byte ceiling has to actually guard this route. `MAX_BYTES`
     * is a public const and the README Limits row promised that oversized
     * files are refused "before GD ever sees them", but fromFile() slurped the
     * whole path with file_get_contents() first — a 70 MB file was fully read
     * into memory and only then rejected for being unreadable as an image.
     * The sparse file below costs no disk to create; the point is that the
     * refusal names the byte ceiling rather than the format.
     */
    public function testAFilePastTheByteCeilingIsRefusedBeforeItIsSlurped(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'si-toobig-');
        self::assertIsString($path);
        $handle = fopen($path, 'wb');
        self::assertIsResource($handle);
        // Sparse: the size is declared, the extents are never allocated.
        self::assertTrue(ftruncate($handle, ImageSource::MAX_BYTES + 1));
        fclose($handle);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/exceeding the maximum of 67108864 bytes/');

            ImageSource::fromFile($path);
        } finally {
            unlink($path);
        }
    }

    /**
     * MAJOR-3 (structural twin): the decoded bytes must be the bytes the
     * ceiling measured. Routing GD through the PATH meant the file was opened
     * three separate times — measure, header sniff, decode — so a rename
     * between them swapped what GD saw for what was bounded (and a reader race
     * could feed GD a larger file than the one that passed the size check).
     * A source-slice pin, per the house structural pattern: the only route
     * back to the caller's path is now the single bounded read.
     */
    public function testTheFileRouteSniffsAndDecodesFromTheValidatedBytes(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/ImageSource.php');
        $start = strpos($src, 'public static function fromFile(');
        self::assertIsInt($start);
        $end = strpos($src, "\n    public static function ", $start + 1);
        self::assertIsInt($end);
        $body = substr($src, $start, $end - $start);
        // Comments are part of the argument here (the prose names the calls it
        // removed), so the census runs on the comment-stripped slice.
        $code = (string) preg_replace('~/\*.*?\*/~s', '', $body);
        $code = (string) preg_replace('~(?m)^[ \t]*(?://|#)[^\n]*$~', '', $code);

        self::assertStringContainsString('readBoundedRegularFile($path)', $code, 'the ceiling must guard this route');
        self::assertStringContainsString('getimagesizefromstring($bytes)', $code, 'the sniff must ride the validated bytes');
        self::assertStringContainsString('imagecreatefromstring($bytes)', $code, 'the decode must ride the validated bytes');

        self::assertStringNotContainsString('file_get_contents($path', $code, 'no unbounded slurp');
        self::assertStringNotContainsString('getimagesize($path', $code, 'the path is not opened a second time');
        foreach (['imagecreatefrompng', 'imagecreatefromjpeg', 'imagecreatefromgif'] as $opener) {
            self::assertStringNotContainsString($opener . '($path', $code, "{$opener} would re-open the caller's path");
        }
    }

    /**
     * MAJOR-3: switching the decode to the string route must not change a
     * byte of what a valid file produces — the buffer stored on the source is
     * exactly the file's contents, and the geometry still comes from GD.
     */
    public function testAValidFileStillYieldsTheSameBytesAndGeometry(): void
    {
        $path = __DIR__ . '/fixtures/4x2.png';
        $img  = ImageSource::fromFile($path);

        $this->assertSame((string) file_get_contents($path), $img->bytes);
        $this->assertSame('image/png', $img->format);
        $this->assertSame(4, $img->width);
        $this->assertSame(2, $img->height);
    }

    /**
     * MAJOR-3: the download route is bounded by the same ceiling. The fetch
     * reads at most MAX_BYTES + 1 so an oversized body never lands in memory,
     * and the surplus byte is what distinguishes "exactly at the cap" from
     * "over it" — both edges pinned here (private seam, per the house
     * ReflectionMethod pattern).
     */
    public function testTheDownloadCeilingRefusesASingleSurplusByte(): void
    {
        $guard = new \ReflectionMethod(ImageSource::class, 'guardFetchedBytes');
        $guard->setAccessible(true);

        $atCap = str_repeat('x', ImageSource::MAX_BYTES);
        $this->assertSame($atCap, $guard->invoke(null, $atCap, 'https://example.test/at-cap'), 'at the cap the body is accepted');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeds the maximum of 67108864 bytes/');
        $guard->invoke(null, $atCap . 'x', 'https://example.test/over-cap');
    }
}
