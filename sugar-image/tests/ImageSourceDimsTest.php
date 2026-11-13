<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\ImageSource;

/**
 * SI-32 (finding 040): intake reads dimensions from the header instead of
 * paying a full GD decode just to ask `imagesx()`/`imagesy()` a question the
 * sniff above it had already answered — the old shape made every PNG/JPEG
 * display cost ≥2 complete decodes (intake's + each renderer's).
 *
 * The header becomes authoritative because libpng/libjpeg/giflib ALLOCATE
 * from the very header fields PHP's getimagesizefromstring() reports, so:
 *  - PARITY — every payload GD decodes is sized identically by the header
 *    parser (pinned here across the fixture corpus and this lib's own
 *    encoders, including an adversarial truncated-IDAT and a lied-IHDR);
 *    a dimensions answer that moved across the SI-32 cut goes red here.
 *  - DEFERRAL — bytes that carry a parseable header but a broken body used
 *    to die at intake with the (now dead) gd_load_failed key. They are now
 *    accepted at intake, sized from the header, and refused by the door that
 *    actually decodes — crop()/resize()'s existing `=== false` leg, in its
 *    existing gd_load_failed_from_string grammar. The refusal moved; it did
 *    not vanish.
 *  - REFUSAL — unsized bytes keep their intake deaths: the file route's
 *    unsupported_format leg is untouched, and the string route refuses
 *    named-but-unsized containers the same way (measured: GD cannot decode
 *    those either, so no honest payload changed doors).
 *
 * @covers \SugarCraft\Image\ImageSource
 */
final class ImageSourceDimsTest extends TestCase
{
    public function testCorpusHeaderDimensionsEqualDecodeDimensionsAndTheSourcesAnswer(): void
    {
        $seen = 0;
        foreach (glob(__DIR__ . '/fixtures/*') ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }
            $bytes = @file_get_contents($file);
            if ($bytes === false) {
                continue;
            }
            $header = @getimagesizefromstring($bytes);
            $gd = @imagecreatefromstring($bytes);
            if ($header === false) {
                continue; // unsized bytes: not this law's evidence
            }
            if ($gd === false) {
                continue; // sized-but-undecodable: the deferral arms cover it
            }
            $seen++;
            $declared = [(int) $header[0], (int) $header[1]];
            $decoded = [imagesx($gd), imagesy($gd)];
            imagedestroy($gd);

            self::assertSame($decoded, $declared, basename($file) . ': header lied about the decode');

            // And the constructed sources answer with those same numbers on
            // BOTH byte routes (fromString for the buffer, fromFile for disk).
            $fromString = ImageSource::fromString($bytes);
            self::assertSame($declared[0], $fromString->width, basename($file));
            self::assertSame($declared[1], $fromString->height, basename($file));
            $fromFile = ImageSource::fromFile($file);
            self::assertSame($declared, [$fromFile->width, $fromFile->height], basename($file));
        }

        // Anti-vacuous floor: the corpus is 12 PNGs + 3 GIFs; a fixture-dir
        // wipe must not green this law by seeing nothing.
        self::assertGreaterThanOrEqual(12, $seen, 'the parity census went vacuously small');
    }

    public function testTheLibsOwnEncodersAreHeaderSizedToTheSameNumbers(): void
    {
        // The corpus is what upstream ships; this arm crafts what THIS lib's
        // ingestion routes produce (fromGd re-encodes PNG/BMP, animation
        // frames GIF) and demands the same parity, so the law is not a
        // fixture accident.
        $gd = imagecreatetruecolor(5, 3);
        self::assertInstanceOf(\GdImage::class, $gd);

        $cases = [];
        ob_start();
        imagepng($gd, $f1 = tempnam(sys_get_temp_dir(), 'sid') . '.png');
        imagewebp($gd, $f2 = tempnam(sys_get_temp_dir(), 'sid') . '.webp', 90);
        imagebmp($gd, $f3 = tempnam(sys_get_temp_dir(), 'sid') . '.bmp');
        imagejpeg($gd, $f4 = tempnam(sys_get_temp_dir(), 'sid') . '.jpg', 90);
        imagegif($gd, $f5 = tempnam(sys_get_temp_dir(), 'sid') . '.gif');
        ob_end_clean();
        foreach ([$f1, $f2, $f3, $f4, $f5] as $path) {
            $bytes = @file_get_contents($path);
            @unlink($path);
            if ($bytes !== false) {
                $cases[] = $bytes;
            }
        }
        imagedestroy($gd);

        self::assertGreaterThanOrEqual(3, count($cases), 'encoder availability vanished');
        foreach ($cases as $bytes) {
            $header = @getimagesizefromstring($bytes);
            $decoded = @imagecreatefromstring($bytes);
            if ($header === false || $decoded === false) {
                continue; // codec-less host: the corpus arm is the floor
            }
            self::assertSame([5, 3], [(int) $header[0], (int) $header[1]]);
            self::assertSame([imagesx($decoded), imagesy($decoded)], [(int) $header[0], (int) $header[1]]);
            imagedestroy($decoded);
            $source = ImageSource::fromString($bytes);
            self::assertSame([5, 3], [$source->width, $source->height]);
        }
    }

    public function testATruncatedBodyIsAcceptedByHeaderAndRefusedAtThePixelDoor(): void
    {
        // The deferral, pinned at BOTH ends. A valid 4x2 PNG cut at byte 40
        // keeps a perfectly parseable IHDR (the probe that opened this row:
        // getimagesize says 4x2, GD says nothing) — intake no longer argues
        // about a decode it will not run; the first door that needs pixels
        // still refuses, in its existing grammar.
        $png = (string) file_get_contents(__DIR__ . '/fixtures/4x2.png');
        $truncated = substr($png, 0, 40);

        $source = ImageSource::fromString($truncated);
        self::assertSame(4, $source->width);
        self::assertSame(2, $source->height);

        try {
            $source->crop(0, 0, 2, 2);
            self::fail('the pixel door accepted bytes it cannot decode');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('GD failed to decode image bytes', $e->getMessage());
        }

        try {
            $source->resize(2, 1);
            self::fail('the pixel door accepted bytes it cannot decode');
        } catch (\RuntimeException $e) {
            self::assertStringContainsString('GD failed to decode image bytes', $e->getMessage());
        }

        // The same bytes via the file route keep the same posture (and the
        // refusal is a typed throw, not a warning spray: failOnWarning would
        // already have turned any leaked libpng notice into a failure).
        $path = tempnam(sys_get_temp_dir(), 'sid') . '.png';
        file_put_contents($path, $truncated);
        try {
            $fromFile = ImageSource::fromFile($path);
            self::assertSame([4, 2], [$fromFile->width, $fromFile->height]);
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('GD failed to decode image bytes');
            $fromFile->crop(0, 0, 2, 2);
        } finally {
            @unlink($path);
        }
    }

    public function testALiedSmallHeaderCannotHideABiggerAllocation(): void
    {
        // The bomb-safety half of the law: GD allocates from the HEADER, so a
        // payload whose IDAT carries more pixels than a (CRC-correct) shrunken
        // IHDR declares decodes AT THE DECLARED SIZE — the declared-pixel
        // guard therefore bounds every future allocation exactly as the old
        // decode-then-reguard did. Measured on this build: libpng refuses
        // "Too much image data" outright; either answer (refusal or a decode
        // at ≤ declared) leaves no over-ceiling buffer behind.
        $png = (string) file_get_contents(__DIR__ . '/fixtures/4x2.png');
        $data = pack('N', 2) . pack('N', 1) . substr($png, 24, 5);
        $chunk = 'IHDR' . $data;
        $liar = substr($png, 0, 8) . pack('N', 13) . $chunk . pack('N', crc32($chunk)) . substr($png, 33);

        $header = @getimagesizefromstring($liar);
        self::assertIsArray($header, 'precondition: the lie must be header-parseable');
        self::assertSame([2, 1], [(int) $header[0], (int) $header[1]]);

        // A ceiling of 2 pixels equals the declared count: accepted. The SAME
        // bytes under a ceiling of 1 must die at the declared guard — the
        // guard's position is the whole bomb law now.
        $source = ImageSource::fromString($liar, maxPixels: 2);
        self::assertSame([2, 1], [$source->width, $source->height]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/exceed the maximum of 1 pixels/');
        ImageSource::fromString($liar, maxPixels: 1);
    }

    public function testNamedButUnsizedBytesAreRefusedBeforeConstruction(): void
    {
        // A GIF signature that names the container while the logical screen
        // descriptor is cut short: the magic list says image/gif, the header
        // parser has no dimensions, and nothing at intake decodes to invent
        // them. (Measured: GD refuses these too — the refusal simply no
        // longer pretends a load was attempted.)
        $bytes = "GIF89a\x0a\x00\x0a\x00";

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported image format/');

        ImageSource::fromString($bytes);
    }

    public function testAZeroSideHeaderLieIsRefusedByTheConstructorDoorAtIntake(): void
    {
        // getimagesizefromstring() trusts a CRC-correct IHDR that says
        // width=0 (measured). The guard passes it (a zero count is not "over
        // the maximum"), and the public-ctor positive-dimensions refusal —
        // the same door fromFile/fromGd already stand behind — kills it at
        // intake. Loud, typed, on the byte routes both.
        $png = (string) file_get_contents(__DIR__ . '/fixtures/4x2.png');
        $data = pack('N', 0) . substr($png, 20, 9);
        $chunk = 'IHDR' . $data;
        $liar = substr($png, 0, 8) . pack('N', 13) . $chunk . pack('N', crc32($chunk)) . substr($png, 33);

        $path = tempnam(sys_get_temp_dir(), 'sid') . '.png';
        file_put_contents($path, $liar);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessageMatches('/must be positive/');
            ImageSource::fromFile($path);
        } finally {
            @unlink($path);
        }
    }

    public function testTheHeaderlessBombStillDiesAtTheUnnamedFormatDoor(): void
    {
        // The refusal that made the old post-decode re-guard redundant for
        // the ONLY dimensions that ever diverged (128 bytes of "\x00\xff":
        // no header, GD invents a 264 Mpx canvas) stays exactly where SI-07
        // left it: before construction, naming the real cause.
        $bytes = str_repeat("\x00\xff", 64);

        foreach ([4, 1_000_000, ImageSource::MAX_PIXELS, -1] as $ceiling) {
            try {
                ImageSource::fromString($bytes, maxPixels: $ceiling);
                self::fail("ceiling {$ceiling}: headerless bomb accepted");
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Unsupported image format: [memory-bytes]', $e->getMessage());
            }
        }
    }
}
