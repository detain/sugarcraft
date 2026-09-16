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

    public function testOnlyZlibFormatReportsCompressed(): void
    {
        // `f=1` is the single code this decoder inflates on: the upstream
        // raw-pixel codes (`24` RGB, `32` RGBA), both PNG spellings and any
        // other value must not claim it.
        foreach (['0', '2', '12', '24', '32', '100'] as $format) {
            self::assertFalse(
                KittyImage::fromTransmit(['a' => 'T', 'f' => $format], 'payload')->compressed(),
                "f={$format} is not a zlib transmission",
            );
        }

        self::assertTrue(KittyImage::fromTransmit(['a' => 'T', 'f' => '1'], 'payload')->compressed());
    }

    public function testPngPassthroughCoversBothPngSpellings(): void
    {
        // `100` is the upstream protocol code for a PNG payload; `12` is a
        // synonym some external tooling emits (no SugarCraft producer uses it).
        // The decoder treats them identically so neither spelling misreads.
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
        // against the predicates it is documented to select, so swapping `1`,
        // `12` and `100` in the constant table would fail here.
        self::assertSame('1', KittyImage::FORMAT_ZLIB);
        self::assertSame('12', KittyImage::FORMAT_PNG_ALT);
        self::assertSame('100', KittyImage::FORMAT_PNG);
        self::assertSame(
            [KittyImage::FORMAT_PNG_ALT, KittyImage::FORMAT_PNG],
            KittyImage::PNG_PASSTHROUGH_FORMATS,
        );

        $zlib = KittyImage::fromTransmit(['a' => 'T', 'f' => KittyImage::FORMAT_ZLIB], 'payload');
        self::assertTrue($zlib->compressed(), 'f=1 is the zlib row');
        self::assertFalse($zlib->pngPassthrough());

        foreach ([KittyImage::FORMAT_PNG_ALT, KittyImage::FORMAT_PNG] as $format) {
            $png = KittyImage::fromTransmit(['a' => 'T', 'f' => $format], 'payload');
            self::assertTrue($png->pngPassthrough(), "f={$format} must decode as PNG passthrough");
            self::assertFalse($png->compressed(), "f={$format} must never be inflated");
        }
    }
}
