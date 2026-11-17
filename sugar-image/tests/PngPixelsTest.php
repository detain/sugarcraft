<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ApngDecoder;
use SugarCraft\Image\Codec\RawRgba;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\PngPixels;

/**
 * The public still-PNG pixel decoder: exact samples (no GD 7-bit alpha), all
 * five scanline filters, tRNS keys, palettes, and the null-not-throw contract
 * for everything it declines. The byte-walk cases were MaskIngest's own
 * fixtures before the decoder moved here.
 *
 * @covers \SugarCraft\Image\PngPixels
 * @covers \SugarCraft\Image\Codec\RawRgba
 */
final class PngPixelsTest extends TestCase
{
    private const SIG = "\x89PNG\r\n\x1a\n";

    /** Samples per pixel by colour type, the filter's byte distance. */
    private const CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    private static function ihdr(int $w, int $h, int $colorType, int $interlace = 0, int $depth = 8): string
    {
        return self::chunk('IHDR', pack('NNCCCCC', $w, $h, $depth, $colorType, 0, 0, $interlace));
    }

    private static function paeth(int $a, int $b, int $c): int
    {
        $p = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        return ($pa <= $pb && $pa <= $pc) ? $a : ($pb <= $pc ? $b : $c);
    }

    /**
     * Forward-filter raw rows, cycling the filter type across rows. The
     * predictor reads the UNFILTERED neighbours, as the PNG encoder does.
     *
     * @param list<int> $types
     */
    private static function filter(string $raw, int $w, int $h, int $channels, array $types): string
    {
        $stride = $w * $channels;
        $out = '';
        for ($y = 0; $y < $h; $y++) {
            $type = $types[$y % count($types)];
            $out .= chr($type);
            for ($i = 0; $i < $stride; $i++) {
                $x = ord($raw[$y * $stride + $i]);
                $left = $i >= $channels ? ord($raw[$y * $stride + $i - $channels]) : 0;
                $up = $y > 0 ? ord($raw[($y - 1) * $stride + $i]) : 0;
                $upLeft = ($i >= $channels && $y > 0) ? ord($raw[($y - 1) * $stride + $i - $channels]) : 0;
                $pred = match ($type) {
                    0 => 0,
                    1 => $left,
                    2 => $up,
                    3 => intdiv($left + $up, 2),
                    default => self::paeth($left, $up, $upLeft),
                };
                $out .= chr(($x - $pred) & 0xFF);
            }
        }

        return $out;
    }

    /**
     * A complete 8-bit, non-interlaced PNG for a colour type, with the given
     * per-row filter types and ancillary chunks placed before IDAT.
     *
     * @param list<int> $types
     */
    private static function png(int $w, int $h, int $colorType, string $raw, array $types = [0], string $ancillary = ''): string
    {
        return self::SIG
            . self::ihdr($w, $h, $colorType)
            . $ancillary
            . self::chunk('IDAT', gzcompress(self::filter($raw, $w, $h, self::CHANNELS[$colorType], $types)))
            . self::chunk('IEND', '');
    }

    /** @return list<int> */
    private static function bytes(string $rgba): array
    {
        return array_values(unpack('C*', $rgba) ?: []);
    }

    public function testRgbaDecodesWithoutQuantisingAlpha(): void
    {
        // GD reads 8-bit alpha as 7 bits, so 254 comes back opaque there. The
        // decoder must return the stored samples, including 1 and 254.
        $raw = pack('C*', 10, 20, 30, 254, 0, 0, 0, 0, 255, 255, 255, 1, 9, 8, 7, 128);

        $decoded = PngPixels::decode(self::png(2, 2, 6, $raw));

        self::assertInstanceOf(RawRgba::class, $decoded);
        self::assertSame(2, $decoded->width);
        self::assertSame(2, $decoded->height);
        self::assertSame($raw, $decoded->rgba);
    }

    public function testGrayscaleExpandsToEqualChannelsWithOpaqueAlpha(): void
    {
        $decoded = PngPixels::decode(self::png(2, 1, 0, pack('C*', 0, 200)));

        self::assertNotNull($decoded);
        self::assertSame(pack('C*', 0, 0, 0, 255, 200, 200, 200, 255), $decoded->rgba);
    }

    public function testGrayAlphaExpandsAndKeepsItsAlpha(): void
    {
        $decoded = PngPixels::decode(self::png(1, 1, 4, pack('C*', 90, 253)));

        self::assertNotNull($decoded);
        self::assertSame(pack('C*', 90, 90, 90, 253), $decoded->rgba);
    }

    public function testRgbExpandsWithOpaqueAlpha(): void
    {
        $decoded = PngPixels::decode(self::png(2, 1, 2, pack('C*', 1, 2, 3, 4, 5, 6)));

        self::assertNotNull($decoded);
        self::assertSame(pack('C*', 1, 2, 3, 255, 4, 5, 6, 255), $decoded->rgba);
    }

    /**
     * Each of the five filter types must reverse to the samples it encoded.
     * Rows are non-constant so a wrong predictor cannot survive by accident.
     */
    public function testEachScanlineFilterTypeUnfiltersExactly(): void
    {
        $w = 3;
        $h = 5;
        $raw = '';
        for ($i = 0; $i < $w * $h * 4; $i++) {
            $raw .= chr(($i * 37 + 11) & 0xFF);
        }

        foreach ([0, 1, 2, 3, 4] as $type) {
            $decoded = PngPixels::decode(self::png($w, $h, 6, $raw, [$type]));
            self::assertNotNull($decoded, "filter {$type} declined");
            self::assertSame($raw, $decoded->rgba, "filter {$type} did not unfilter to the encoded samples");
        }
    }

    public function testMixedFilterTypesAcrossRowsUnfilterExactly(): void
    {
        $w = 4;
        $h = 5;
        $raw = '';
        for ($i = 0; $i < $w * $h * 3; $i++) {
            $raw .= chr(($i * 91 + 7) & 0xFF);
        }

        $decoded = PngPixels::decode(self::png($w, $h, 2, $raw, [4, 3, 2, 1, 0]));

        self::assertNotNull($decoded);
        $expected = '';
        for ($p = 0; $p < $w * $h; $p++) {
            $expected .= substr($raw, $p * 3, 3) . "\xff";
        }
        self::assertSame($expected, $decoded->rgba);
    }

    public function testGrayTrnsKeyMarksExactMatchesTransparent(): void
    {
        $decoded = PngPixels::decode(self::png(3, 1, 0, pack('C*', 0x40, 0x41, 0x40), [0], self::chunk('tRNS', pack('n', 0x40))));

        self::assertNotNull($decoded);
        self::assertSame(pack('C*', 0x40, 0x40, 0x40, 0, 0x41, 0x41, 0x41, 255, 0x40, 0x40, 0x40, 0), $decoded->rgba);
    }

    public function testRgbTrnsKeyMatchesOnlyTheExactTriple(): void
    {
        $decoded = PngPixels::decode(self::png(2, 1, 2, pack('C*', 10, 20, 30, 10, 20, 31), [0], self::chunk('tRNS', pack('n3', 10, 20, 30))));

        self::assertNotNull($decoded);
        self::assertSame(pack('C*', 10, 20, 30, 0, 10, 20, 31, 255), $decoded->rgba);
    }

    public function testTrnsKeyWithANonZeroHighByteMatchesNothing(): void
    {
        // A 16-bit key whose high byte is set cannot equal any 8-bit sample.
        $decoded = PngPixels::decode(self::png(1, 1, 0, pack('C', 1), [0], self::chunk('tRNS', pack('n', 0x0101))));

        self::assertNotNull($decoded);
        self::assertSame(pack('C*', 1, 1, 1, 255), $decoded->rgba);
    }

    public function testPaletteExpandsThroughPlteAndPartialTrns(): void
    {
        $plte = self::chunk('PLTE', pack('C*', 255, 0, 0, 0, 255, 0));
        // Only index 0 carries an alpha; index 1 is absent from tRNS and stays opaque.
        $trns = self::chunk('tRNS', pack('C', 254));

        $decoded = PngPixels::decode(self::png(2, 1, 3, pack('C*', 0, 1), [0], $plte . $trns));

        self::assertNotNull($decoded);
        self::assertSame(pack('C*', 255, 0, 0, 254, 0, 255, 0, 255), $decoded->rgba);
    }

    public function testPaletteIndexPastPlteDeclines(): void
    {
        $plte = self::chunk('PLTE', pack('C*', 1, 2, 3));

        self::assertNull(PngPixels::decode(self::png(1, 1, 3, pack('C', 2), [0], $plte)));
    }

    public function testPaletteWithoutPlteDeclines(): void
    {
        self::assertNull(PngPixels::decode(self::png(1, 1, 3, pack('C', 0))));
    }

    public function testInterlacedStreamDeclines(): void
    {
        $stream = self::SIG . self::ihdr(1, 1, 6, 1)
            . self::chunk('IDAT', gzcompress(pack('C*', 0, 1, 2, 3, 4)))
            . self::chunk('IEND', '');

        self::assertNull(PngPixels::decode($stream));
    }

    public function testSixteenBitStreamDeclines(): void
    {
        $stream = self::SIG . self::ihdr(1, 1, 6, 0, 16)
            . self::chunk('IDAT', gzcompress(str_repeat("\0", 9)))
            . self::chunk('IEND', '');

        self::assertNull(PngPixels::decode($stream));
    }

    public function testUnknownColourTypeDeclines(): void
    {
        $stream = self::SIG . self::ihdr(1, 1, 5)
            . self::chunk('IDAT', gzcompress(str_repeat("\0", 2)))
            . self::chunk('IEND', '');

        self::assertNull(PngPixels::decode($stream));
    }

    /**
     * Regression: MaskIngest's byte walk accepted a chunk whose CRC did not
     * match. The public decoder verifies every chunk and declines instead.
     */
    public function testBadChunkCrcDeclines(): void
    {
        $idat = pack('N', 8) . 'IDAT' . gzcompress("\0\x01\x02\x03\x04") . pack('N', 0);
        $stream = self::SIG . self::ihdr(1, 1, 6) . $idat . self::chunk('IEND', '');

        self::assertNull(PngPixels::decode($stream));
    }

    public function testTruncatedStreamDeclines(): void
    {
        $png = self::png(2, 2, 6, str_repeat("\x7f", 16));

        self::assertNull(PngPixels::decode(substr($png, 0, strlen($png) - 6)));
    }

    public function testNonPngBytesDecline(): void
    {
        self::assertNull(PngPixels::decode(''));
        self::assertNull(PngPixels::decode("\x89PNG"));
        self::assertNull(PngPixels::decode("GIF89a\x01\x00\x01\x00"));
    }

    public function testMissingIdatDeclines(): void
    {
        self::assertNull(PngPixels::decode(self::SIG . self::ihdr(1, 1, 6) . self::chunk('IEND', '')));
    }

    /** IHDR must be the first chunk; the animated decoder requires the same. */
    public function testIhdrNotFirstDeclines(): void
    {
        $stream = self::SIG
            . self::chunk('tEXt', "a\0b")
            . self::ihdr(1, 1, 6)
            . self::chunk('IDAT', gzcompress("\0\x01\x02\x03\x04"))
            . self::chunk('IEND', '');

        self::assertNull(PngPixels::decode($stream));
    }

    public function testUnknownFilterByteDeclines(): void
    {
        // Filter byte 5 is not one of the five defined types.
        $stream = self::SIG . self::ihdr(1, 1, 2)
            . self::chunk('IDAT', gzcompress(pack('C*', 5, 1, 2, 3)))
            . self::chunk('IEND', '');

        self::assertNull(PngPixels::decode($stream));
    }

    public function testZeroDimensionDeclines(): void
    {
        self::assertNull(PngPixels::decode(self::SIG . self::ihdr(0, 1, 6) . self::chunk('IDAT', gzcompress('')) . self::chunk('IEND', '')));
    }

    /**
     * The header is checked before any inflate, so a huge declared size with a
     * tiny body is refused without allocating for it.
     */
    public function testOversizedHeaderDeclinesBeforeInflating(): void
    {
        $stream = self::SIG . self::ihdr(5000, 5000, 6) . self::chunk('IDAT', gzcompress('')) . self::chunk('IEND', '');
        self::assertNull(PngPixels::decode($stream));

        $wide = self::SIG . self::ihdr(20000, 1, 6) . self::chunk('IDAT', gzcompress('')) . self::chunk('IEND', '');
        self::assertNull(PngPixels::decode($wide));
    }

    public function testExplicitPixelCeilingIsHonoured(): void
    {
        $png = self::png(2, 2, 6, str_repeat("\x10", 16));

        self::assertNull(PngPixels::decode($png, 3));
        self::assertNotNull(PngPixels::decode($png, 4));
    }

    public function testNonPositivePixelCeilingDeclinesEverything(): void
    {
        self::assertNull(PngPixels::decode(self::png(1, 1, 6, "\x01\x02\x03\x04"), 0));
    }

    /**
     * A lying IHDR with an IDAT that inflates far past its scanline budget is
     * cut off at the budget and declined, not expanded in full.
     *
     * A bare null would also come back from an uncapped inflate, since the length
     * check rejects the full expansion afterwards. So the memory limit is set to
     * leave headroom well under the 40 MiB an uncapped inflate would allocate: the
     * capped path declines, and the uncapped one exhausts the limit.
     */
    public function testIdatBombIsCutAtTheScanlineBudget(): void
    {
        $bomb = gzcompress(str_repeat("\0", 40 * 1024 * 1024));
        $stream = self::SIG . self::ihdr(2, 2, 6) . self::chunk('IDAT', $bomb) . self::chunk('IEND', '');

        $original = (string) ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));
        try {
            self::assertNull(PngPixels::decode($stream));
        } finally {
            ini_set('memory_limit', $original);
        }
    }

    /**
     * The row loop unfilters row by row, so a bad filter byte on the last row
     * declines after the earlier rows have been read, with no partial frame
     * returned.
     */
    public function testAMalformedLateRowDeclinesAfterTheEarlierRows(): void
    {
        $filtered = self::filter(str_repeat("\x10", 64 * 4), 1, 64, 4, [0]);
        $filtered[63 * 5] = "\x09"; // row 63's filter byte: 9 is not a PNG filter type

        $stream = self::SIG . self::ihdr(1, 64, 6)
            . self::chunk('IDAT', gzcompress($filtered))
            . self::chunk('IEND', '');

        self::assertNull(PngPixels::decode($stream));
    }

    /**
     * 4096x4096 RGBA is 67,112,960 scanline bytes, one row-width over the raw
     * ceiling. It is declined on the header alone, before any inflate, so the
     * tiny IDAT here never matters.
     */
    public function testRgbaAtTheRawCeilingDeclinesOnePastIt(): void
    {
        $stream = self::SIG . self::ihdr(4096, 4096, 6) . self::chunk('IDAT', gzcompress('')) . self::chunk('IEND', '');

        self::assertNull(PngPixels::decode($stream));
        self::assertSame(67_108_864, PngPixels::MAX_RAW_BYTES);
    }

    /**
     * The default pixel ceiling is the loader's admission cap, so a still PNG
     * that ImageSource admits is decodable here too.
     */
    public function testDefaultPixelCeilingIsTheLoaderAdmissionCap(): void
    {
        self::assertSame(ImageSource::MAX_PIXELS, PngPixels::MAX_PIXELS);
    }

    public function testApngBaseImageIsTheIdatFrame(): void
    {
        $raw = pack('C*', 1, 2, 3, 4);
        $stream = self::SIG . self::ihdr(1, 1, 6)
            . self::chunk('acTL', pack('NN', 1, 0))
            . self::chunk('fcTL', pack('NNNNNnnCC', 0, 1, 1, 0, 0, 1, 10, 0, 0))
            . self::chunk('IDAT', gzcompress(self::filter($raw, 1, 1, 4, [0])))
            . self::chunk('IEND', '');

        $decoded = PngPixels::decode($stream);

        self::assertNotNull($decoded);
        self::assertSame($raw, $decoded->rgba);
    }

    /**
     * Regression: the shared expansion ignored tRNS for gray and RGB, so an
     * APNG keyed by tRNS showed the key colour opaque. The frame path now
     * shares PngPixels' expansion and honours the key.
     */
    public function testApngFramePathHonoursAGrayTrnsKey(): void
    {
        $stream = self::SIG . self::ihdr(3, 1, 0)
            . self::chunk('tRNS', pack('n', 0x40))
            . self::chunk('acTL', pack('NN', 1, 0))
            . self::chunk('fcTL', pack('NNNNNnnCC', 0, 3, 1, 0, 0, 1, 10, 0, 0))
            . self::chunk('IDAT', gzcompress(self::filter(pack('C*', 0x40, 0x41, 0x40), 3, 1, 1, [0])))
            . self::chunk('IEND', '');

        $frames = ApngDecoder::decode($stream, 0);

        self::assertCount(1, $frames);
        $alpha = array_map(static fn (int $i): int => ord($frames[0]['rgba'][$i * 4 + 3]), [0, 1, 2]);
        self::assertSame([0, 255, 0], $alpha);
    }

    /**
     * Regression: the RGB half of the APNG tRNS change. On master the frame path
     * ignored the colour key for type 2 as well, so the exact key triple stayed
     * opaque. Only the exact triple turns transparent; a near miss keeps alpha 255.
     */
    public function testApngFramePathHonoursAnRgbTrnsKey(): void
    {
        $stream = self::SIG . self::ihdr(2, 1, 2)
            . self::chunk('tRNS', pack('n3', 10, 20, 30))
            . self::chunk('acTL', pack('NN', 1, 0))
            . self::chunk('fcTL', pack('NNNNNnnCC', 0, 2, 1, 0, 0, 1, 10, 0, 0))
            . self::chunk('IDAT', gzcompress(self::filter(pack('C*', 10, 20, 30, 10, 20, 31), 2, 1, 3, [0])))
            . self::chunk('IEND', '');

        $frames = ApngDecoder::decode($stream, 0);

        self::assertCount(1, $frames);
        self::assertSame(pack('C*', 10, 20, 30, 0, 10, 20, 31, 255), $frames[0]['rgba']);
    }

    public function testRawRgbaRejectsAShortBuffer(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RawRgba::new(2, 2, 'short');
    }

    // ─── Fixtures moved from candy-code MaskIngest (mask classification inputs) ───

    private static function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/fixtures/mask/' . $name . '.png');
    }

    /** @return list<int> the RGBA quad at pixel index $i */
    private static function quad(RawRgba $p, int $i): array
    {
        return array_values(unpack('C4', substr($p->rgba, $i * 4, 4)) ?: []);
    }

    public function testTheAlphaFixtureDecodesToExactlyTransparentAndOpaquePixels(): void
    {
        $p = PngPixels::decode(self::fixture('mask-alpha'));

        self::assertNotNull($p);
        self::assertSame([8, 8], [$p->width, $p->height]);
        self::assertSame([0, 0, 0, 0], self::quad($p, 0), 'first column block is fully transparent');
        self::assertSame([255, 255, 255, 255], self::quad($p, 4), 'second block is opaque white');
    }

    public function testTheAlpha254FixtureKeepsTheStoredNearOpaqueSampleGdWouldDrop(): void
    {
        $p = PngPixels::decode(self::fixture('mask-alpha254'));

        self::assertNotNull($p);
        self::assertSame(254, self::quad($p, 0)[3], 'alpha 254 survives; GD reads it back as opaque');
        self::assertSame(255, self::quad($p, 1)[3]);
    }

    public function testTheLumaFixtureDecodesToOpaqueGrayscale(): void
    {
        $p = PngPixels::decode(self::fixture('mask-luma'));

        self::assertNotNull($p);
        foreach (range(0, $p->width * $p->height - 1) as $i) {
            [$r, $g, $b, $a] = self::quad($p, $i);
            self::assertTrue($r === $g && $g === $b, "pixel {$i} is achromatic");
            self::assertSame(255, $a, "pixel {$i} is opaque");
        }
    }

    public function testTheNeitherFixtureDecodesToOpaqueChromaticPixels(): void
    {
        $p = PngPixels::decode(self::fixture('mask-neither'));

        self::assertNotNull($p);
        self::assertSame([255, 0, 0, 255], self::quad($p, 0));
    }

    public function testTheCorruptFixtureDeclinesWithoutThrowing(): void
    {
        self::assertNull(PngPixels::decode(self::fixture('corrupt')));
    }

    // ─── Direct coverage for the shared container primitives ───────────────

    public function testWalkChunksReturnsEachChunkInOrderUpToIend(): void
    {
        $stream = self::chunk('IHDR', 'abc') . self::chunk('IDAT', '') . self::chunk('IEND', '') . 'trailing';

        self::assertSame(
            [
                ['type' => 'IHDR', 'data' => 'abc'],
                ['type' => 'IDAT', 'data' => ''],
                ['type' => 'IEND', 'data' => ''],
            ],
            PngPixels::walkChunks($stream, 0),
        );
    }

    public function testWalkChunksRefusesAChunkWhoseCrcDoesNotMatch(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PngPixels::walkChunks(pack('N', 3) . 'IHDR' . 'abc' . pack('N', 0), 0);
    }

    public function testWalkChunksRefusesANonLetterChunkType(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PngPixels::walkChunks(self::chunk("IH\x001", ''), 0);
    }

    public function testParseIhdrReadsGeometryAndChannelCount(): void
    {
        self::assertSame([3, 2, 4, 6], PngPixels::parseIhdr(pack('NNCCCCC', 3, 2, 8, 6, 0, 0, 0)));
    }

    public function testParseIhdrRefusesSixteenBitDepth(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PngPixels::parseIhdr(pack('NNCCCCC', 1, 1, 16, 0, 0, 0, 0));
    }

    public function testParseIhdrRefusesAnInterlacedHeader(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PngPixels::parseIhdr(pack('NNCCCCC', 1, 1, 8, 2, 0, 0, 1));
    }

    public function testParsePaletteReadsRgbTriplesFromPlte(): void
    {
        $chunks = [['type' => 'IHDR', 'data' => ''], ['type' => 'PLTE', 'data' => "\x01\x02\x03\xFF\x00\x7F"]];

        self::assertSame([1, 2, 3, 255, 0, 127], PngPixels::parsePalette($chunks));
        self::assertSame([], PngPixels::parsePalette([]));
    }

    public function testParsePaletteRefusesATornEntry(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PngPixels::parsePalette([['type' => 'PLTE', 'data' => "\x01\x02"]]);
    }

    public function testParseTrnsReturnsPerIndexAlphaOrNothing(): void
    {
        self::assertSame([0, 128], PngPixels::parseTrns([['type' => 'tRNS', 'data' => "\x00\x80"]]));
        self::assertSame([], PngPixels::parseTrns([]));
    }

    public function testInflateAndUnfilterReversesTheSubFilterAndExpandsRgb(): void
    {
        // One 2x1 RGB row, Sub filter: the second pixel is stored as a delta from the first.
        $zlib = gzcompress("\x01" . pack('C*', 10, 20, 30, 1, 1, 1));

        self::assertSame(
            pack('C*', 10, 20, 30, 255, 11, 21, 31, 255),
            PngPixels::inflateAndUnfilter($zlib, 2, 1, 3, 2, [], []),
        );
    }

    public function testInflateAndUnfilterRefusesAStreamOfTheWrongLength(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PngPixels::inflateAndUnfilter(gzcompress("\x00"), 1, 1, 1, 0, [], []);
    }
}
