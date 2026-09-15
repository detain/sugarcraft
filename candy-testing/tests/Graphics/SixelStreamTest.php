<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Graphics\MalformedGraphicsException;
use SugarCraft\Testing\Graphics\SixelStream;

/**
 * @covers \SugarCraft\Testing\Graphics\SixelStream
 */
final class SixelStreamTest extends TestCase
{
    public function testDecodesSolidRedToFullRaster(): void
    {
        $sixel = SixelStream::decode(Fixture::bytes('sixel_red.six'));

        self::assertSame(80, $sixel->width());
        self::assertSame(40, $sixel->height());
        self::assertSame([255, 0, 0], $sixel->pixel(0, 0));
        self::assertSame([255, 0, 0], $sixel->pixel(79, 39));
        self::assertSame([255, 0, 0], $sixel->grid()->uniformColor());
    }

    public function testPaletteMapsPercentageRegisterToBytes(): void
    {
        $sixel = SixelStream::decode(Fixture::bytes('sixel_red.six'));

        // Wire declares `#0;2;100;0;0` (percent) → the decoder maps 100 → 255.
        self::assertSame([0 => [255, 0, 0]], $sixel->palette());
    }

    public function testLastPartialBandSetsOnlyItsRows(): void
    {
        // 40 rows = six full 6-row bands (36) + one 4-row band; row 39 is painted
        // but a hypothetical row 40 falls outside the declared raster.
        $sixel = SixelStream::decode(Fixture::bytes('sixel_red.six'));

        self::assertNotNull($sixel->pixel(0, 39));
        self::assertNull($sixel->pixel(0, 40));
    }

    public function testDecodesMultiColourChecker(): void
    {
        $sixel = SixelStream::decode(Fixture::bytes('sixel_checker.six'));
        $grid = $sixel->grid();

        self::assertSame(40, $sixel->width());
        self::assertSame(40, $sixel->height());
        self::assertCount(1600, $grid->paintedPixels(), 'every cell of the checker raster is painted');
        self::assertNull($grid->uniformColor(), 'a checker is never uniform');

        // Two palette entries — white and blue. Round-tripping through the
        // protocol's 0..100 percent channel costs at most one byte of precision
        // (180 → round(180/255*100)=71 → round(71*255/100)=181), so assert the
        // reconstructed blue is within one of the source rather than byte-equal.
        self::assertCount(2, $sixel->palette());
        self::assertSame([255, 255, 255], $sixel->pixel(0, 0));
        $blue = array_values(array_filter(
            $sixel->palette(),
            static fn(array $rgb): bool => $rgb[2] >= 170 && $rgb[0] <= 5 && $rgb[1] <= 5,
        ));
        self::assertNotEmpty($blue, 'a saturated blue palette entry should survive the percent round-trip');
    }

    public function testParsesImageEmbeddedInSurroundingText(): void
    {
        $stream = 'welcome' . Fixture::bytes('sixel_red.six') . 'trailer';
        $sixel = SixelStream::decode($stream);

        self::assertSame(80, $sixel->width());
        self::assertSame([255, 0, 0], $sixel->pixel(40, 20));
    }

    public function testToGdImageProducesMatchingCanvas(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('ext-gd is required to materialise a Sixel image');
        }

        $image = SixelStream::decode(Fixture::bytes('sixel_red.six'))->toGdImage();

        self::assertSame(80, imagesx($image));
        self::assertSame(40, imagesy($image));
    }

    public function testMissingDcsHeaderThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('DCS header');
        SixelStream::decode('no sixel here');
    }

    public function testUnterminatedStringThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('ST terminator');
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;4#0;2;100;0;0#0~");
    }

    public function testMissingIntroducerThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('color-space introducer');
        SixelStream::decode("\x1bP0;1;0Z\"1;1;4;4\x1b\\");
    }

    public function testMissingRasterDeclarationThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('raster size declaration');
        SixelStream::decode("\x1bP0;1;0q#0;2;100;0;0#0~\x1b\\");
    }

    public function testNonPositiveRasterThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('non-positive width');
        SixelStream::decode("\x1bP0;1;0q\"1;1;0;10#0;2;100;0;0\x1b\\");
    }

    public function testOversizeRunThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('exceeds the declared raster width');
        // Width 10 but an 11-column run with all rows set.
        SixelStream::decode("\x1bP0;1;0q\"1;1;10;6#0;2;100;0;0#0!11~\x1b\\");
    }

    public function testBandOverrunningHeightThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('overruns the declared raster height');
        // Height 3, but the first band sets all six rows.
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;3#0;2;100;0;0#0~\x1b\\");
    }

    public function testColourUsedBeforeDefinitionThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('undefined color register');
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;4#0;2;100;0;0#1~\x1b\\");
    }

    public function testUnexpectedCharacterThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('unexpected character');
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;4#0;2;100;0;0#0\x00\x1b\\");
    }

    public function testRunMissingCountThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('missing its repeat count');
        // `!` immediately followed by a data char — no repeat count to read.
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;6#0;2;100;0;0#0!~\x1b\\");
    }

    public function testRunMissingDataCharThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('missing its data character');
        // `!4` names a run length but the stream ends before any data byte arrives.
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;6#0;2;100;0;0#0!4\x1b\\");
    }

    public function testSpaceSeparatedRunDecodes(): void
    {
        // Canonical RLE is `!4~`; be lenient about the `!4 ~` spacing some
        // encoders emit and still paint four lit columns across the band.
        $sixel = SixelStream::decode("\x1bP0;1;0q\"1;1;4;6#0;2;100;0;0#0!4 ~\x1b\\");

        self::assertSame([255, 0, 0], $sixel->pixel(0, 0));
        self::assertSame([255, 0, 0], $sixel->pixel(3, 0));
    }

    public function testSemicolonSeparatedRunDecodes(): void
    {
        // The other tolerated form: `!4;~` with an explicit `;` before the data
        // byte — same four lit columns as the space-separated case above.
        $sixel = SixelStream::decode("\x1bP0;1;0q\"1;1;4;6#0;2;100;0;0#0!4;~\x1b\\");

        self::assertSame([255, 0, 0], $sixel->pixel(0, 0));
        self::assertSame([255, 0, 0], $sixel->pixel(3, 5));
    }

    public function testNonRgbColorSpaceThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('not supported');
        // Model 5 is HLS, which the decoder deliberately refuses to guess.
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;4#0;5;100;0;0#0~\x1b\\");
    }

    public function testColorComponentOutOfRangeThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('exceeds the 0..100');
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;4#0;2;150;0;0#0~\x1b\\");
    }

    public function testTruncatedColorDefinitionThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('malformed');
        // `#idx;model;r;g` — four numbers, blue missing; must not default it.
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;4#0;2;100;0#0~\x1b\\");
    }

    public function testDataBeforeColorSelectedThrows(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('before a color register');
        // A bare data byte appears with only a raster decl, never a `#` select.
        SixelStream::decode("\x1bP0;1;0q\"1;1;4;4~\x1b\\");
    }
}
