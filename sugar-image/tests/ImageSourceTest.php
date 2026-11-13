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
     * MAJOR-3 (structural twin), re-anchored for SI-32: the bytes the header
     * walk measures must be the bytes the ceiling bounded. Routing checks
     * through the PATH meant the file was opened three separate times —
     * measure, header sniff, decode — so a rename between them swapped what
     * the later legs saw for what was bounded. Since SI-32 there is no
     * intake decode at all: the single bounded read plus one sniff is the
     * whole route. A source-slice pin, per the house structural pattern.
     */
    public function testTheFileRouteSniffsFromTheValidatedBytesAndNeverDecodes(): void
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
        self::assertStringNotContainsString('imagecreatefromstring(', $code, 'intake must not pay a pixel decode for dimensions (SI-32)');

        self::assertStringNotContainsString('file_get_contents($path', $code, 'no unbounded slurp');
        self::assertStringNotContainsString('getimagesize($path', $code, 'the path is not opened a second time');
        foreach (['imagecreatefrompng', 'imagecreatefromjpeg', 'imagecreatefromgif'] as $opener) {
            self::assertStringNotContainsString($opener . '($path', $code, "{$opener} would re-open the caller's path");
        }
    }

    /**
     * MAJOR-3: switching the read to the string route must not change a
     * byte of what a valid file produces — the buffer stored on the source is
     * exactly the file's contents. (SI-32: the geometry now comes from the
     * header both routes already parsed, which the corpus parity arms in
     * ImageSourceDimsTest prove equals the old GD answer wherever GD
     * succeeds — so the dimensions this test asserts are unchanged law.)
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

    public function testAHeaderlessDecompressionBombIsRefusedBeforeGDMaterialisesIt(): void
    {
        // 128 bytes that declare no dimensions and name no format: measured on
        // this build, getimagesizefromstring() says FALSE while libgd decodes a
        // 16256×16256 (264 Mpx) canvas from them. The header-only guard skipped
        // the unmeasurable arm, so the pixel ceiling was bypassable by simply
        // shipping bytes no header parser recognises.
        $bytes = str_repeat("\x00\xff", 64);

        foreach ([4, 1_000_000, ImageSource::MAX_PIXELS, -1] as $ceiling) {
            try {
                $source = ImageSource::fromString($bytes, maxPixels: $ceiling);
                self::fail("ceiling {$ceiling}: headerless bomb accepted at {$source->width}x{$source->height}");
            } catch (\InvalidArgumentException $e) {
                // The refusal names the real cause — an unnamed format refused
                // at the boundary — not a pixel-count accusation against
                // dimensions no header ever declared. It also fires with the
                // ceiling disabled (-1): format anonymity, not budget size, is
                // what the refusal polices.
                self::assertStringContainsString('Unsupported image format: [memory-bytes]', $e->getMessage());
                self::assertStringNotContainsString('exceed the maximum', $e->getMessage());
            }
        }
    }

    /**
     * Structural pin for the SI-32 header law (RE-ANCHOR, disclosed: this
     * row replaced the old "decoded-dimensions re-guard before construction"
     * pin, which pinned the intake decode this step deliberately removed).
     * The new order: the header sniff measures, the declared guard refuses
     * oversized canvases, construction carries the header numbers — and no
     * byte route pays a GD decode here. Removal of the declared guard could
     * not go red behaviourally on honest fixtures (the corpus parity arms
     * in ImageSourceDimsTest prove header dims equal GD dims wherever GD
     * succeeds), so this walks the source text and pins ORDER, not just
     * presence.
     */
    public function testBothByteRoutesGuardDeclaredDimensionsBeforeConstruction(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/ImageSource.php');

        foreach (['fromFile', 'fromString'] as $route) {
            $start = strpos($src, "public static function {$route}(");
            self::assertIsInt($start, "route {$route} vanished from the source");
            $end = strpos($src, "\n    }\n", $start);
            self::assertIsInt($end, "no method end found for {$route}");
            $slice = substr($src, $start, $end - $start);
            // The SI-32 law comments name the calls they forbade; run the
            // census on the comment-stripped slice so prose cannot poison it.
            $code = (string) preg_replace('~/\*.*?\*/~s', '', $slice);
            $code = (string) preg_replace('~(?m)^[ \t]*(?://|#)[^\n]*$~', '', $code);

            $sniff = strpos($code, '@getimagesizefromstring($bytes)');
            $guard = strpos($code, 'self::guardPixelCount(');
            $ctor  = strpos($code, 'return new self($bytes, $format, $width, $height, $maxPixels);');
            self::assertIsInt($sniff, "{$route}: header sniff vanished");
            self::assertIsInt($guard, "{$route}: declared-dimensions guard vanished");
            self::assertIsInt($ctor, "{$route}: construction seam vanished");
            self::assertLessThan($guard, $sniff, "{$route}: the guard must measure header dimensions");
            self::assertLessThan($ctor, $guard, "{$route}: the guard must run before the source is constructed");
            self::assertStringNotContainsString('imagecreatefromstring(', $code, "{$route}: intake must not decode pixels for dimensions (SI-32)");
            self::assertStringNotContainsString('imagesx(', $code, "{$route}: no GD dimension read may live at intake (SI-32)");

            if ($route === 'fromString') {
                $refuse  = strpos($code, "=== 'application/octet-stream'");
                $unsized = strpos($code, 'if ($info === false) {');
                self::assertIsInt($refuse, 'fromString: unnamed-format refusal removed');
                self::assertIsInt($unsized, 'fromString: named-but-unsized refusal removed (SI-32)');
                self::assertLessThan($unsized, $refuse, 'fromString: container refusals must fire before the dims-missing arm');
                self::assertLessThan($ctor, $unsized, 'fromString: unsized bytes must be refused before construction');
            }
        }
    }

    /**
     * n33: the in-memory route was the one byte intake without a ceiling —
     * fromFile() bounds the read and guardFetchedBytes() bounds the URL body,
     * but a caller handing an over-ceiling buffer straight to fromString()
     * went unpunished. The cap rides the same self::MAX_BYTES and the refusal
     * the same 'exceeding the maximum of' wording family as its siblings.
     */
    public function testBytesPastTheCeilingAreRefusedByNameNotByDecode(): void
    {
        $over = str_repeat('x', ImageSource::MAX_BYTES + 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceeding the maximum of 67108864 bytes/');

        ImageSource::fromString($over);
    }

    /**
     * Exactly MAX_BYTES of undecodable filler must wave through the size
     * guard and still come back as a FORMAT refusal — the companion arm to
     * the surplus-byte pin above, so an off-by-one (>=) or a swallowed cap
     * mutation flips this test even where the over-cap arm stays green.
     */
    public function testTheStringCeilingIsInclusiveAtItsCap(): void
    {
        $atCap = str_repeat('x', ImageSource::MAX_BYTES);

        try {
            ImageSource::fromString($atCap);
            self::fail('at-cap filler must not decode');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Unsupported image format: [memory-bytes]', $e->getMessage());
            self::assertStringNotContainsString('exceeding the maximum', $e->getMessage());
        }
    }

    /**
     * Structural ordering pin, per the house source-slice precedent (see
     * testTheFileRouteSniffsFromTheValidatedBytesAndNeverDecodes and
     * testBothByteRoutesGuardDeclaredDimensionsBeforeConstruction): the
     * strlen guard must sit ahead of BOTH byte-consuming seams inside
     * fromString() — the header sniff and the construction — so no input
     * size buys a sniff (or anything after it) before the refusal.
     * Re-anchored for SI-32: the GD allocation seam this pin used to watch
     * no longer exists on the string route; the sniff is now the first
     * consumer of the buffer, and the refusal-before-construction arm took
     * over the guard's teeth.
     */
    public function testTheStringCeilingIsCheckedBeforeAnyByteConsumingSeam(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../src/ImageSource.php');
        $start = strpos($src, 'public static function fromString(');
        self::assertIsInt($start, 'the string intake route vanished');
        $end = strpos($src, "\n    }\n", $start);
        self::assertIsInt($end, 'the string intake body did not terminate');
        $slice = substr($src, $start, $end - $start);

        $ceiling = strpos($slice, 'strlen($bytes) > self::MAX_BYTES');
        $sniff   = strpos($slice, '@getimagesizefromstring($bytes)');
        $ctor    = strpos($slice, 'return new self(');
        self::assertIsInt($ceiling, 'the byte ceiling guard vanished from the string route');
        self::assertIsInt($sniff, 'the header sniff seam vanished from the string route');
        self::assertIsInt($ctor, 'the construction seam vanished from the string route');
        self::assertLessThan($sniff, $ceiling, 'fromString: ceiling must precede the header sniff');
        self::assertLessThan($ctor, $sniff, 'fromString: the sniff must precede construction');
        self::assertStringNotContainsString('@imagecreatefromstring($bytes)', $slice, 'fromString: intake must not decode pixels (SI-32)');
    }

    public function testFromGdInternalHandoffKeepsEveryPixelLossless(): void
    {
        // SI-33 (finding 041): the deflate level of the fromGd re-encode is
        // tuning; the pixels are the contract. Whatever the internal PNG
        // level, the handoff must survive a full decode with every pixel
        // byte-identical — the lossless half of "lossless PNG semantics".
        $gd = imagecreatetruecolor(6, 4);
        $seen = [];
        for ($x = 0; $x < 6; $x++) {
            for ($y = 0; $y < 4; $y++) {
                $color = imagecolorallocate($gd, ($x * 40) % 256, ($y * 77 + $x * 13) % 256, 255 - $x * 20);
                imagesetpixel($gd, $x, $y, $color);
                $seen[$x][$y] = imagecolorat($gd, $x, $y) & 0xFFFFFF;
            }
        }

        $source = ImageSource::fromGd($gd, 'image/png');
        $back   = imagecreatefromstring($source->bytes);
        $this->assertInstanceOf(\GdImage::class, $back);

        foreach ($seen as $x => $row) {
            foreach ($row as $y => $rgb) {
                $this->assertSame($rgb, imagecolorat($back, $x, $y) & 0xFFFFFF, "pixel $x,$y");
            }
        }

        imagedestroy($gd);
        imagedestroy($back);
    }

    public function testTheInternalHandoffLevelIsANamedConstant(): void
    {
        // The magic 9 that max-deflated every animation frame becomes one
        // readable decision; the pin keeps it a decision (a defensible
        // zlib-default band) rather than a number drifting back blind.
        $ref = new \ReflectionClass(ImageSource::class);
        $this->assertTrue($ref->hasConstant('INTERNAL_PNG_LEVEL'));
        $level = $ref->getConstant('INTERNAL_PNG_LEVEL');
        $this->assertIsInt($level);
        $this->assertGreaterThanOrEqual(0, $level);
        $this->assertLessThanOrEqual(9, $level);

        // The constant must actually BE the call: a defined-but-unused
        // level is how magic numbers come back.
        $fromGd = new \ReflectionMethod(ImageSource::class, 'fromGd');
        $body   = implode("\n", array_slice(
            file($fromGd->getFileName()),
            $fromGd->getStartLine() - 1,
            $fromGd->getEndLine() - $fromGd->getStartLine() + 1
        ));
        $this->assertStringContainsString('imagepng($resource, $tmp, self::INTERNAL_PNG_LEVEL)', $body);
        $this->assertStringNotContainsString('imagepng($resource, $tmp, 9)', $body);
    }
}
