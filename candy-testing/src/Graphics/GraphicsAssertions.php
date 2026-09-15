<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Graphics;

use PHPUnit\Framework\Assert;
use RuntimeException;
use SugarCraft\Testing\Lang;

/**
 * Assertions that decode a terminal graphics stream and compare it to intent.
 *
 * These are the test-facing surface of the {@see SixelStream}, {@see KittyStream}
 * and {@see Iterm2Stream} decoders: give them a raw byte stream plus a
 * {@see Mode} and they assert the rendered pixels, the inline image dimensions,
 * or that the stream survives a semantic round-trip through its own decoder.
 *
 * Pixel-from-PNG paths require ext-gd; every such entry point fails loudly when
 * it is missing rather than silently passing.
 */
final class GraphicsAssertions extends Assert
{
    private const PNG_MAGIC = "\x89PNG\r\n\x1a\n";

    /**
     * Assert that a stream renders to the expected RGB raster.
     *
     * @param string                          $stream    raw protocol bytes
     * @param Mode                            $mode      protocol to decode with
     * @param PixelGrid|list<list<array{int,int,int}|null>> $expected expected cells (null = transparent)
     * @param int                             $tolerance max per-channel deviation (0 = exact)
     */
    public static function assertRendersTo(string $stream, Mode $mode, PixelGrid|array $expected, int $tolerance = 0, string $message = ''): void
    {
        $expected = $expected instanceof PixelGrid ? $expected : PixelGrid::fromRgbGrid($expected);
        $actual = self::renderGrid($stream, $mode);

        self::assertSame(
            $expected->width() . 'x' . $expected->height(),
            $actual->width() . 'x' . $actual->height(),
            self::describe($message, 'raster dimensions differ'),
        );

        $firstMismatch = self::findFirstMismatch($expected, $actual, $tolerance);
        self::assertNull(
            $firstMismatch,
            self::describe($message, (string) $firstMismatch),
        );
    }

    /**
     * Assert the pixel dimensions the stream renders at (either axis may be null to skip).
     */
    public static function assertInlineImageDimensions(string $stream, Mode $mode, ?int $width, ?int $height, string $message = ''): void
    {
        [$actualWidth, $actualHeight] = self::renderedDimensions($stream, $mode);

        if ($width !== null) {
            self::assertSame($width, $actualWidth, self::describe($message, "width: expected {$width}, got {$actualWidth}"));
        }
        if ($height !== null) {
            self::assertSame($height, $actualHeight, self::describe($message, "height: expected {$height}, got {$actualHeight}"));
        }
    }

    /**
     * Assert the stream decodes cleanly and stays internally consistent.
     *
     * Sixel: raster matches the declared header and every painted cell references
     * a defined colour. Kitty / iTerm2: the reassembled payload is a real PNG and
     * its pixel size agrees with any declared `s`/`v` hint.
     */
    public static function assertProtocolRoundTrip(string $stream, Mode $mode, string $message = ''): void
    {
        match ($mode) {
            Mode::Sixel => self::roundTripSixel($stream, $message),
            Mode::Kitty => self::roundTripKitty($stream, $message),
            Mode::Iterm2 => self::roundTripIterm2($stream, $message),
        };
    }

    public static function renderGrid(string $stream, Mode $mode): PixelGrid
    {
        return match ($mode) {
            Mode::Sixel => SixelStream::decode($stream)->grid(),
            Mode::Kitty => self::gridFromPng(KittyStream::decode($stream)->image()->png()),
            Mode::Iterm2 => self::gridFromPng(Iterm2Stream::decode($stream)->png()),
        };
    }

    /**
     * @return array{int, int}
     */
    private static function renderedDimensions(string $stream, Mode $mode): array
    {
        return match ($mode) {
            Mode::Sixel => self::sixelDimensions($stream),
            Mode::Kitty => KittyStream::decode($stream)->image()->pixelDimensions(),
            Mode::Iterm2 => Iterm2Stream::decode($stream)->pixelDimensions(),
        };
    }

    /**
     * Decode a Sixel stream once and read both declared dimensions.
     *
     * @return array{int, int}
     */
    private static function sixelDimensions(string $stream): array
    {
        $sixel = SixelStream::decode($stream);

        return [$sixel->width(), $sixel->height()];
    }

    private static function gridFromPng(string $png): PixelGrid
    {
        if (!function_exists('imagecreatefromstring')) {
            throw new RuntimeException(Lang::t('graphics.gd.unavailable'));
        }

        $image = imagecreatefromstring($png);
        if (!$image instanceof \GdImage) {
            throw new MalformedGraphicsException(Lang::t('graphics.payload_not_image'));
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $rows = [];
        for ($y = 0; $y < $height; $y++) {
            $row = [];
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $row[] = $color['alpha'] >= 64
                    ? null
                    : [$color['red'], $color['green'], $color['blue']];
            }
            $rows[] = $row;
        }

        return PixelGrid::fromRgbGrid($rows);
    }

    private static function roundTripSixel(string $stream, string $message): void
    {
        $sixel = SixelStream::decode($stream);
        $grid = $sixel->grid();
        $palette = $sixel->palette();

        self::assertSame(
            $sixel->width(),
            $grid->width(),
            self::describe($message, 'decoded width does not match the declared raster'),
        );
        self::assertSame(
            $sixel->height(),
            $grid->height(),
            self::describe($message, 'decoded height does not match the declared raster'),
        );
        self::assertNotEmpty(
            $grid->paintedPixels(),
            self::describe($message, 'raster decoded with no painted pixels'),
        );

        foreach ($grid->paintedPixels() as $cell) {
            self::assertContains(
                $cell,
                array_values($palette),
                self::describe($message, 'a rendered pixel references an undefined palette entry'),
            );
        }
    }

    private static function roundTripKitty(string $stream, string $message): void
    {
        $image = KittyStream::decode($stream)->image();

        if ($image->action() === 'p' || !$image->hasPayload()) {
            return; // a placement carries no image data by design
        }

        self::assertStringStartsWith(
            self::PNG_MAGIC,
            $image->png(),
            self::describe($message, 'reassembled payload is not a PNG'),
        );

        [$realWidth, $realHeight] = $image->pixelDimensions();
        $declaredWidth = $image->pixelWidth();
        $declaredHeight = $image->pixelHeight();

        if ($declaredWidth !== null) {
            self::assertSame($declaredWidth, $realWidth, self::describe($message, 'declared `s` width disagrees with the PNG'));
        }
        if ($declaredHeight !== null) {
            self::assertSame($declaredHeight, $realHeight, self::describe($message, 'declared `v` height disagrees with the PNG'));
        }
    }

    private static function roundTripIterm2(string $stream, string $message): void
    {
        $iterm2 = Iterm2Stream::decode($stream);

        if ($iterm2->command() !== 'File' || !$iterm2->hasPayload()) {
            return; // a control sequence (Pop, delete, …) carries no image
        }

        self::assertStringStartsWith(
            self::PNG_MAGIC,
            $iterm2->png(),
            self::describe($message, 'payload is not a PNG'),
        );

        [$realWidth, $realHeight] = $iterm2->pixelDimensions();
        self::assertGreaterThan(0, $realWidth, self::describe($message, 'PNG reports zero width'));
        self::assertGreaterThan(0, $realHeight, self::describe($message, 'PNG reports zero height'));
    }

    /**
     * @return string|null human-readable description of the first mismatch
     */
    private static function findFirstMismatch(PixelGrid $expected, PixelGrid $actual, int $tolerance): ?string
    {
        for ($y = 0; $y < $expected->height(); $y++) {
            for ($x = 0; $x < $expected->width(); $x++) {
                $want = $expected->pixel($x, $y);
                $got = $actual->pixel($x, $y);

                if ($want === null || $got === null) {
                    if ($want !== $got) {
                        return "pixel {$x},{$y}: expected " . self::cell($want) . ', got ' . self::cell($got);
                    }
                    continue;
                }

                foreach ([0, 1, 2] as $channel) {
                    if (abs($want[$channel] - $got[$channel]) > $tolerance) {
                        return "pixel {$x},{$y}: expected " . self::cell($want) . ', got ' . self::cell($got)
                            . " (tolerance {$tolerance})";
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param array{int, int, int}|null $cell
     */
    private static function cell(?array $cell): string
    {
        return $cell === null ? 'transparent' : sprintf('rgb(%d,%d,%d)', $cell[0], $cell[1], $cell[2]);
    }

    private static function describe(string $message, string $detail): string
    {
        return $message === '' ? $detail : $message . "\n" . $detail;
    }
}
