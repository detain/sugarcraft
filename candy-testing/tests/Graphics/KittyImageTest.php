<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Graphics\KittyImage;

/**
 * @covers \SugarCraft\Testing\Graphics\KittyImage
 */
final class KittyImageTest extends TestCase
{
    public function testFormatAccessorExposesTheRawControlValue(): void
    {
        self::assertSame('12', KittyImage::fromTransmit(['a' => 'T', 'f' => '12'], 'payload')->format());
        self::assertNull(KittyImage::fromTransmit(['a' => 'T'], 'payload')->format(), 'an omitted f is not a format');
    }

    public function testOnlyZlibCompressionKeyReportsCompressed(): void
    {
        // `o=z` is the single signal this decoder inflates on: every `f`
        // format value — the upstream raw-pixel codes (`24` RGB, `32` RGBA),
        // both PNG spellings, the retired `1` spelling, anything else — and an
        // absent `o` must not claim it.
        foreach (['0', '1', '2', '12', '24', '32', '100'] as $format) {
            self::assertFalse(
                KittyImage::fromTransmit(['a' => 'T', 'f' => $format], 'payload')->compressed(),
                "f={$format} without o=z is not a zlib transmission",
            );
        }
        self::assertFalse(KittyImage::fromTransmit(['a' => 'T', 'o' => 't'], 'payload')->compressed(), 'o=t (zstd) is not zlib');

        self::assertTrue(KittyImage::fromTransmit(['a' => 'T', 'f' => '100', 'o' => 'z'], 'payload')->compressed());
    }

    public function testPngPassthroughCoversBothPngSpellings(): void
    {
        // `100` is the upstream protocol code for a PNG payload; `12` is the
        // synonym this decoder accepts alongside it (nothing in this monorepo
        // emits `12`). Both must report passthrough so neither misreads.
        self::assertTrue(KittyImage::fromTransmit(['f' => '12'], 'payload')->pngPassthrough());
        self::assertTrue(KittyImage::fromTransmit(['f' => '100'], 'payload')->pngPassthrough());
    }

    public function testPngPassthroughIsFalseForEveryOtherFormat(): void
    {
        foreach (['0', '1', '2', '3', '24', '32', '33', '999', 'png'] as $format) {
            self::assertFalse(
                KittyImage::fromTransmit(['f' => $format], 'payload')->pngPassthrough(),
                "f={$format} is not PNG passthrough",
            );
        }

        self::assertFalse(KittyImage::fromTransmit(['a' => 'p'], '')->pngPassthrough(), 'an omitted f declares nothing');
    }

    public function testZIndexIsNeverACompressionSignal(): void
    {
        // candy-mosaic's `KittyOptions::transmit()->withZIndex(1)` emits `z=1`
        // next to an uncompressed `f=100` PNG; reading that `z` as compression
        // would reject the monorepo's own output, so `z` stays orthogonal to
        // both format predicates.
        $image = KittyImage::fromTransmit(['a' => 'T', 'f' => '100', 'z' => '1'], 'payload');

        self::assertSame(1, $image->zIndex());
        self::assertTrue($image->pngPassthrough());
        self::assertFalse($image->compressed());
    }

    public function testFormatConstantsMatchTheBehaviourTheyGate(): void
    {
        // Deliberately not a constants-vs-constants echo: every code is checked
        // against the predicates it is documented to select, so swapping `12`
        // and `100` in the format table or respelling the compression value
        // would fail here.
        self::assertSame('z', KittyImage::COMPRESSION_ZLIB);
        self::assertSame('12', KittyImage::FORMAT_PNG_ALT);
        self::assertSame('100', KittyImage::FORMAT_PNG);
        self::assertSame(
            [KittyImage::FORMAT_PNG_ALT, KittyImage::FORMAT_PNG],
            KittyImage::PNG_PASSTHROUGH_FORMATS,
        );

        $zlib = KittyImage::fromTransmit(['a' => 'T', 'f' => '100', 'o' => KittyImage::COMPRESSION_ZLIB], 'payload');
        self::assertTrue($zlib->compressed(), 'o=z is the zlib row');
        self::assertTrue($zlib->pngPassthrough(), 'f=100 still declares the PNG format');

        foreach ([KittyImage::FORMAT_PNG_ALT, KittyImage::FORMAT_PNG] as $format) {
            $png = KittyImage::fromTransmit(['a' => 'T', 'f' => $format], 'payload');
            self::assertTrue($png->pngPassthrough(), "f={$format} must decode as PNG passthrough");
            self::assertFalse($png->compressed(), "f={$format} without o=z must never be inflated");
        }
    }
}
