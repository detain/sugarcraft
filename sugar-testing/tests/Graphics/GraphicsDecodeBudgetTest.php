<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Graphics\DecodeLimits;
use SugarCraft\Testing\Graphics\KittyStream;
use SugarCraft\Testing\Graphics\MalformedGraphicsException;
use SugarCraft\Testing\Graphics\SixelStream;

/**
 * The decoders parse hostile-ish captured wire bytes, so every allocation
 * must sit behind a budget that raises a catchable
 * MalformedGraphicsException rather than a killed runner. (Measured in
 * review: a `"10000;10000` sixel header pre-allocated a 100M-cell raster,
 * and a corrupt inflate used to surface as a bare PHP warning.)
 *
 * The 64 MiB inflate-bomb leg is deliberately NOT pinned end-to-end —
 * materialising one costs a ~65 MB string on every run; the per-slice cap
 * it guards is the same accrual check exercised by the truncation leg's
 * streaming loop.
 */
final class GraphicsDecodeBudgetTest extends TestCase
{
    public function testSixelHugeDeclaredRasterIsRefusedBeforeAllocation(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('decode budget');

        SixelStream::decode("\x1bP0;1;0q\"1;1;10000;10000#0;2;100;0;0#0~\x1b\\");
    }

    public function testSixelOverDigitLiteralIsRefusedNotSaturated(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('longer than');

        // 10 digits — an (int) cast would silently saturate past the cap.
        SixelStream::decode("\x1bP0;1;0q\"1;1;1234567890;4#0;2;100;0;0#0~\x1b\\");
    }

    public function testRasterExactlyInsideBudgetStillDecodes(): void
    {
        $sixel = SixelStream::decode("\x1bP0;1;0q\"1;1;4;4\x1b\\");

        self::assertSame([4, 4], [$sixel->width(), $sixel->height()]);
    }

    public function testTruncatedZlibPayloadRefusesInsteadOfReturningPartialImage(): void
    {
        $truncated = substr(gzcompress('hello world hello world'), 0, 8);

        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('inflate');

        KittyStream::decode("\x1b_Ga=T,f=100,o=z;" . base64_encode($truncated) . "\x1b\\");
    }

    public function testFullZlibPayloadStillRoundTripsThroughTheStreamingLoop(): void
    {
        $png = $this->tinyPng();
        $kitty = KittyStream::decode("\x1b_Ga=T,f=100,o=z;" . base64_encode(gzcompress($png)) . "\x1b\\");

        self::assertSame($png, $kitty->image()->png(), 'slice-by-slice inflate must reassemble exactly');
    }

    public function testPngDeclaringMoreThanThePixelBudgetIsRefusedBeforeGdAllocates(): void
    {
        // A PNG header is enough: getimagesizefromstring() reads IHDR even
        // when the rest of the payload never arrives, and imagecreatefromstring
        // would have died allocating instead of refusing.
        $bomb = "\x89PNG\r\n\x1a\n" . "\x00\x00\x00\x0DIHDR" . pack('N', 100_000) . pack('N', 100_000) . "\x08\x06\x00\x00\x00";

        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('decode budget');

        DecodeLimits::assertMaterialisablePng($bomb, 'graphics.payload_not_image');
    }

    public function testNonImageBytesRefuseWithTheCallersOwnKey(): void
    {
        $this->expectException(MalformedGraphicsException::class);
        $this->expectExceptionMessage('does not decode');

        DecodeLimits::assertMaterialisablePng('definitely not a png', 'graphics.kitty.payload_not_image');
    }

    public function testSmallRealPngPassesTheMaterialiseGate(): void
    {
        DecodeLimits::assertMaterialisablePng($this->tinyPng(), 'graphics.payload_not_image');

        $this->expectNotToPerformAssertions();
    }

    private function tinyPng(): string
    {
        $image = imagecreatetruecolor(2, 2);
        if (!$image instanceof \GdImage) {
            throw new \RuntimeException('GD is required to build the ok-leg PNG');
        }
        imagesetpixel($image, 0, 0, 0x00FF0000);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
