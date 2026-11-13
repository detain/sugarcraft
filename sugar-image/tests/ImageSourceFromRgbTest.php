<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;

/**
 * @covers \SugarCraft\Image\ImageSource::fromRgb
 */
final class ImageSourceFromRgbTest extends TestCase
{
    public function testRgb24RoundTripsExactPixelsThroughPngContainer(): void
    {
        // 2×2: red, green, blue, white.
        $bytes = "\xff\x00\x00" . "\x00\xff\x00" . "\x00\x00\xff" . "\xff\xff\xff";

        $source = ImageSource::fromRgb($bytes, 2, 2);

        $this->assertSame('image/png', $source->format);
        $this->assertSame(2, $source->width);
        $this->assertSame(2, $source->height);

        $img = imagecreatefromstring($source->bytes);
        $this->assertNotFalse($img);

        $expected = [0xFF0000, 0x00FF00, 0x0000FF, 0xFFFFFF];
        $i = 0;
        foreach ([0, 1] as $y) {
            foreach ([0, 1] as $x) {
                $this->assertSame(
                    $expected[$i++],
                    imagecolorat($img, $x, $y) & 0xFFFFFF,
                    "pixel ({$x},{$y})"
                );
            }
        }
        imagedestroy($img);
    }

    public function testRgbaCarriesAlphaThroughToThePng(): void
    {
        // 1×2: fully transparent red, fully opaque red.
        $bytes = "\xff\x00\x00\x00" . "\xff\x00\x00\xff";

        $source = ImageSource::fromRgb($bytes, 1, 2, hasAlpha: true);

        $img = imagecreatefromstring($source->bytes);
        $this->assertNotFalse($img);

        $this->assertSame(127, imagecolorat($img, 0, 0) >> 24, 'byte alpha 0 → GD 127');
        $this->assertSame(0, imagecolorat($img, 0, 1) >> 24, 'byte alpha 255 → GD 0');
        imagedestroy($img);
    }

    public function testRgbaMidAlphaScalesIntoGdRange(): void
    {
        // byte alpha 128 → intdiv((255-128)*127, 255) = 63.
        $source = ImageSource::fromRgb("\x00\xff\x00\x80", 1, 1, hasAlpha: true);

        $img = imagecreatefromstring($source->bytes);
        $this->assertNotFalse($img);

        $this->assertSame(63, imagecolorat($img, 0, 0) >> 24);
        $this->assertSame(0x00FF00, imagecolorat($img, 0, 0) & 0xFFFFFF);
        imagedestroy($img);
    }

    public function testThreeByteBufferRejectedWhenAlphaFlagExpectsFour(): void
    {
        // 3-byte buffer must NOT be accepted as RGBA of a 1-pixel image.
        $this->expectException(\InvalidArgumentException::class);

        ImageSource::fromRgb("\x01\x02\x03", 1, 1, hasAlpha: true);
    }

    public function testSizeMismatchThrowsWithExpectedAndActual(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw RGB buffer is 5 bytes; expected 12');

        ImageSource::fromRgb("\x00\x00\x00\x00\x00", 2, 2);
    }

    public function testNonPositiveDimensionsThrow(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Raw RGB dimensions must be positive');

        ImageSource::fromRgb('', 0, 4);
    }

    public function testNegativeDimensionThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ImageSource::fromRgb(str_repeat("\x00", 12), 2, -2);
    }

    public function testLoweredMaxPixelsCeilingIsHonoured(): void
    {
        // A trusted decoder may lower the bomb ceiling below MAX_PIXELS —
        // the guard must fire on the ceiling, before GD allocates.
        $this->expectException(\InvalidArgumentException::class);

        ImageSource::fromRgb(str_repeat("\x00", 4 * 4 * 3), 4, 4, false, 10);
    }

    public function testWithinLoweredCeilingStillDecodes(): void
    {
        $source = ImageSource::fromRgb(str_repeat("\x10\x20\x30", 4), 2, 2, false, 4);

        $this->assertSame(2, $source->width);
        $this->assertSame(2, $source->height);
    }

    public function testResultIsAcceptedByTheContainerPipeline(): void
    {
        // The whole point: downstream callers treat the output exactly like
        // any decoded ImageSource — fromString() re-detects and matches.
        $source = ImageSource::fromRgb(str_repeat("\x10\x20\x30", 4), 2, 2);
        $again  = ImageSource::fromString($source->bytes);

        $this->assertSame($source->format, $again->format);
        $this->assertSame($source->width, $again->width);
        $this->assertSame($source->height, $again->height);
        $this->assertSame($source->bytes, $again->bytes);
    }

    public function testRendersThroughHalfBlockLikeAnySource(): void
    {
        // 2×2: two red pixels over two green pixels (12 bytes, RGB24).
        $source = ImageSource::fromRgb("\xff\x00\x00\xff\x00\x00\x00\xff\x00\x00\xff\x00", 2, 2);
        $out    = \SugarCraft\Image\Mosaic::halfBlock()->render($source, 2, 1);

        $this->assertNotSame('', $out);
    }

    public function testFullAlphaRangeReproducesTheDocumentedQuantizerExactly(): void
    {
        // Every possible wire-alpha byte on the wire at once: the decoded
        // GD alpha of each output pixel must equal the intdiv formula the
        // factory has always documented — including the odd bytes, which
        // any container table off-by-one would expose.
        $bytes = '';
        for ($a = 0; $a < 256; $a++) {
            $bytes .= "\x10\x20\x30" . chr($a);
        }

        $source = ImageSource::fromRgb($bytes, 256, 1, hasAlpha: true);
        $img    = imagecreatefromstring($source->bytes);
        $this->assertNotFalse($img);

        for ($a = 0; $a < 256; $a++) {
            $at = imagecolorat($img, $a, 0);
            $this->assertSame(
                intdiv((255 - $a) * 127, 255),
                $at >> 24,
                "wire alpha {$a} must land on the documented GD alpha"
            );
            $this->assertSame(0x102030, $at & 0xFFFFFF, "RGB lane damaged at alpha {$a}");
        }
        imagedestroy($img);
    }

    public function testByteForByteParityWithTheLegacyPerPixelRaster(): void
    {
        // SI-34 acceptance: the C-decode ingest must emit, for every corpus
        // payload, the EXACT bytes the old imagesetpixel loop + imagepng
        // (level 6) emitted — zero digest movement for every consumer that
        // re-reads fromRgb output.
        $rgb24 = '';
        $rgba  = '';
        for ($i = 0; $i < 96 * 96; $i++) {
            $rgb24 .= chr($i & 0xFF) . chr(($i >> 3) & 0xFF) . chr(($i >> 6) & 0xFF);
            $rgba  .= chr(($i * 7) & 0xFF) . chr(($i * 13) & 0xFF) . chr(($i * 29) & 0xFF) . chr($i & 0xFF);
        }
        $opaque = "\xff\x00\x00\xff" . "\x00\xff\x00\x00" . "\x00\x00\xff\x7f" . "\xff\xff\xff\x80";
        $half   = str_repeat("\x12\x34\x56\xab", 7); // 7x1 RGBA, odd alpha everywhere

        $cases = [
            '96x96 RGB24 gradient'   => [$rgb24, 96, 96, false],
            '96x96 RGBA full-alpha'  => [$rgba, 96, 96, true],
            '2x2 opaque/odd edges'   => [$opaque, 2, 2, true],
            '7x1 constant odd alpha' => [$half, 7, 1, true],
        ];

        foreach ($cases as $label => [$bytes, $w, $h, $hasAlpha]) {
            $this->assertSame(
                $this->legacyRasterPng($bytes, $w, $h, $hasAlpha),
                ImageSource::fromRgb($bytes, $w, $h, $hasAlpha)->bytes,
                $label
            );
        }
    }

    public function testThe1024SquareRgbaFrameStillEmitsTheLegacyBytes(): void
    {
        // The scale the finding measured at: a 4 MiB frame (legal under the
        // 50 Mpx ceiling) — byte parity at volume; the perf fold itself is
        // disclosed in the SI-34 commit, never asserted here.
        $bytes = random_bytes(1024 * 1024 * 4);

        $this->assertSame(
            $this->legacyRasterPng($bytes, 1024, 1024, true),
            ImageSource::fromRgb($bytes, 1024, 1024, true)->bytes
        );
    }

    public function testFromRgbCarriesNoPerPixelGdCalls(): void
    {
        // The loop is the finding; a regression must redden exactly this pin.
        $src   = file_get_contents(__DIR__ . '/../src/ImageSource.php');
        $start = strpos((string) $src, 'public static function fromRgb');
        $this->assertNotFalse($start, 'fromRgb must exist to be pinned');
        $end = strpos((string) $src, "\n    }\n", (int) $start);
        $this->assertNotFalse($end, 'method slice must terminate');
        $body = preg_replace('!/\*.*?\*/!s', '', substr((string) $src, (int) $start, (int) $end - (int) $start)) ?? '';
        $body = preg_replace('!//.*!', '', $body) ?? '';

        $this->assertStringNotContainsString('imagesetpixel', $body, 'fromRgb must not raster per pixel');
        $this->assertStringNotContainsString('imagecolorallocatealpha', $body, 'fromRgb must not allocate per pixel');
    }

    /**
     * Reference implementation, frozen at pre-SI-34 tip: the per-pixel
     * imagesetpixel loop + imagepng(6) the factory used before the C-decode
     * ingest. Lives ONLY here, to prove the fold moved no bytes.
     */
    private function legacyRasterPng(string $bytes, int $width, int $height, bool $hasAlpha): string
    {
        $channels = $hasAlpha ? 4 : 3;
        $img      = imagecreatetruecolor($width, $height);

        if ($hasAlpha) {
            imagealphablending($img, false);
            imagesavealpha($img, true);
        }

        $offset = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $r     = ord($bytes[$offset]);
                $g     = ord($bytes[$offset + 1]);
                $b     = ord($bytes[$offset + 2]);
                $color = ($r << 16) | ($g << 8) | $b;
                if ($hasAlpha) {
                    $a     = intdiv((255 - ord($bytes[$offset + 3])) * 127, 255);
                    $color = imagecolorallocatealpha($img, $r, $g, $b, $a);
                }
                imagesetpixel($img, $x, $y, $color);
                $offset += $channels;
            }
        }

        $tmp = fopen('php://temp', 'w+b');
        imagepng($img, $tmp, 6);
        rewind($tmp);
        $out = stream_get_contents($tmp);
        fclose($tmp);
        imagedestroy($img);

        $this->assertIsString($out);

        return $out;
    }
}
