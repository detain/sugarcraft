<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Codec;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Codec\TiffUncompressed;

/**
 * F-13: the GD-free iTerm2 TIFF container.
 *
 * The golden fixture tests/fixtures/tiff_uncompressed_2x2.tiff was
 * hand-generated from the chafa layout (chafa/internal/chafa-iterm2-
 * renderer.c build_ansi, :280-330) by an independent script — NOT by
 * this encoder — so agreement is a real cross-check, not a self-pin.
 * sugar-image goldens have no update-env; regenerating means re-running
 * that generator on purpose (disclosed per plan §0.14).
 */
final class TiffUncompressedTest extends TestCase
{
    /** The 2×2 fixture pixels: red, green, blue (opaque) + yellow (A=0). */
    private const GOLDEN_RGBA =
        "\xff\x00\x00\xff" . "\x00\xff\x00\xff" . "\x00\x00\xff\xff" . "\xff\xff\x00\x00";

    private const GOLDEN_PATH = __DIR__ . '/../fixtures/tiff_uncompressed_2x2.tiff';

    public function testTheEncoderReproducesTheHandGeneratedGoldenByteForByte(): void
    {
        $golden = file_get_contents(self::GOLDEN_PATH);
        self::assertIsString($golden);

        self::assertSame($golden, TiffUncompressed::encode(self::GOLDEN_RGBA, 2, 2));
    }

    public function testTheLittleEndianHeaderCarriesMagicIiAndVersionFortyTwo(): void
    {
        $tiff = TiffUncompressed::encode(self::GOLDEN_RGBA, 2, 2);

        self::assertSame('II', substr($tiff, 0, 2));
        self::assertSame([42], array_values(unpack('v', substr($tiff, 2, 2))));
        // IFD pointer: past the 8-byte header and the 16-byte strip.
        self::assertSame(24, unpack('V', substr($tiff, 4, 4))[1]);
    }

    public function testTheIfdIsTheFixedElevenTagAscendingChafaRoster(): void
    {
        $tiff   = TiffUncompressed::encode(self::GOLDEN_RGBA, 2, 2);
        $parsed = $this->parseIfd($tiff);

        self::assertSame([256, 257, 258, 262, 273, 274, 277, 278, 279, 284, 338], array_keys($parsed));
    }

    public function testEveryFixedFieldValueMatchesTheChafaContract(): void
    {
        $tiff   = TiffUncompressed::encode(self::GOLDEN_RGBA, 2, 2);
        $parsed = $this->parseIfd($tiff);

        // type 3 = SHORT, type 4 = LONG; data = [type, count, value-or-offset].
        self::assertSame([4, 1, 2], $parsed[256], 'ImageWidth');
        self::assertSame([4, 1, 2], $parsed[257], 'ImageLength');
        self::assertSame([3, 4, 162], $parsed[258], 'BitsPerSample → external quad');
        self::assertSame([3, 1, 2], $parsed[262], 'PhotometricInterpretation RGB');
        self::assertSame([4, 1, 8], $parsed[273], 'StripOffsets');
        self::assertSame([3, 1, 1], $parsed[274], 'Orientation top-left');
        self::assertSame([3, 1, 4], $parsed[277], 'SamplesPerPixel');
        self::assertSame([4, 1, 2], $parsed[278], 'RowsPerStrip');
        self::assertSame([4, 1, 16], $parsed[279], 'StripByteCounts');
        self::assertSame([3, 1, 1], $parsed[284], 'PlanarConfiguration contiguous');
        self::assertSame([3, 1, 2], $parsed[338], 'ExtraSamples unassociated alpha');

        // Next-IFD terminator right after the 11th tag, then the bits quad.
        self::assertSame(0, unpack('V', substr($tiff, 158, 4))[1]);
        self::assertSame([8, 8, 8, 8], array_values(unpack('v4', substr($tiff, 162, 8))));
    }

    public function testTheStripRoundTripsThroughAnIndependentParser(): void
    {
        // Decode-via-own-parser: locate the strip purely from the IFD's
        // StripOffsets/StripByteCounts tags and require the exact input
        // pixel stream back.
        $tiff   = TiffUncompressed::encode(self::GOLDEN_RGBA, 2, 2);
        $parsed = $this->parseIfd($tiff);
        [$offset, $length] = [$parsed[273][2], $parsed[279][2]];

        self::assertSame(self::GOLDEN_RGBA, substr($tiff, $offset, $length));
    }

    public function testAGeometryCrossingTheU32OffsetCeilingIsRefusedLoudly(): void
    {
        // 46341² × 4 > 2^32 — refused on dimensions alone, long before
        // any buffer of that size could exist.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('crosses the 32-bit TIFF offset ceiling');

        TiffUncompressed::encode('x', 46341, 46341);
    }

    public function testABufferThatIsNotWTimesHTimesFourIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        TiffUncompressed::encode("\xff\x00\x00", 2, 2);
    }

    /**
     * @return array<int,array{0:int,1:int,2:int}> tag id → [type, count, data]
     */
    private function parseIfd(string $tiff): array
    {
        $ifdOffset = unpack('V', substr($tiff, 4, 4))[1];
        $count     = unpack('v', substr($tiff, $ifdOffset, 2))[1];

        $tags = [];
        for ($i = 0; $i < $count; $i++) {
            $entry = unpack('vtag/vtype/Vcount/Vdata', substr($tiff, $ifdOffset + 2 + $i * 12, 12));
            $tags[(int) $entry['tag']] = [(int) $entry['type'], (int) $entry['count'], (int) $entry['data']];
        }

        return $tags;
    }
}
