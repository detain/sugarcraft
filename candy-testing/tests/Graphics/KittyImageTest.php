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
        // `f=1` is the single code that means "inflate me"; `f=0` (raw pixels),
        // `f=12`/`f=100` (PNG passthrough) and the JPEG codes must not claim it.
        foreach (['0', '2', '12', '24', '32', '100'] as $format) {
            self::assertFalse(
                KittyImage::fromTransmit(['a' => 'T', 'f' => $format], 'payload')->compressed(),
                "f={$format} is not a zlib transmission",
            );
        }

        self::assertTrue(KittyImage::fromTransmit(['a' => 'T', 'f' => '1'], 'payload')->compressed());
    }

    public function testPngPassthroughCoversCanonicalAndLegacyCodes(): void
    {
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
        // candy-mosaic's `KittyOptions::withZIndex(1)` emits `z=1` next to an
        // uncompressed PNG; reading that as compression would reject its own
        // output, so `z` must stay orthogonal to both format predicates.
        $image = KittyImage::fromTransmit(['a' => 'T', 'f' => '12', 'z' => '1'], 'payload');

        self::assertSame(1, $image->zIndex());
        self::assertTrue($image->pngPassthrough());
        self::assertFalse($image->compressed());
    }

    public function testFormatTableMatchesTheKittyProtocolCodes(): void
    {
        // Pinned against the kitty graphics protocol "image format" table, which
        // has no 100 — that value is candy-mosaic's legacy alias of 12.
        self::assertSame('0', KittyImage::FORMAT_RAW);
        self::assertSame('1', KittyImage::FORMAT_ZLIB);
        self::assertSame('12', KittyImage::FORMAT_PNG_PASSTHROUGH);
        self::assertSame('100', KittyImage::FORMAT_LEGACY_PNG_ALIAS);
        self::assertSame(
            [KittyImage::FORMAT_PNG_PASSTHROUGH, KittyImage::FORMAT_LEGACY_PNG_ALIAS],
            KittyImage::PNG_PASSTHROUGH_FORMATS,
        );
    }
}
