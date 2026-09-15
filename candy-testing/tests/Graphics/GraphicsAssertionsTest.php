<?php

declare(strict_types=1);

namespace SugarCraft\Testing\Tests\Graphics;

use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use SugarCraft\Testing\Graphics\GraphicsAssertions;
use SugarCraft\Testing\Graphics\Mode;
use SugarCraft\Testing\Graphics\PixelGrid;

/**
 * @covers \SugarCraft\Testing\Graphics\GraphicsAssertions
 */
final class GraphicsAssertionsTest extends TestCase
{
    public function testAssertRendersToSixelUniformRed(): void
    {
        // Rebuild the expected raster fully red and compare against the decoder.
        $rows = [];
        for ($y = 0; $y < 40; $y++) {
            $rows[] = array_fill(0, 80, [255, 0, 0]);
        }

        GraphicsAssertions::assertRendersTo(
            Fixture::bytes('sixel_red.six'),
            Mode::Sixel,
            PixelGrid::fromRgbGrid($rows),
        );

        self::assertTrue(true);
    }

    public function testAssertRendersToAcceptsRawArrayGrid(): void
    {
        $grid = GraphicsAssertions::renderGrid(Fixture::bytes('sixel_red.six'), Mode::Sixel);
        $rows = [];
        for ($y = 0; $y < $grid->height(); $y++) {
            $row = [];
            for ($x = 0; $x < $grid->width(); $x++) {
                $row[] = $grid->pixel($x, $y);
            }
            $rows[] = $row;
        }

        GraphicsAssertions::assertRendersTo(Fixture::bytes('sixel_red.six'), Mode::Sixel, $rows);
        self::assertTrue(true);
    }

    public function testAssertRendersToWithinTolerance(): void
    {
        $grid = GraphicsAssertions::renderGrid(Fixture::bytes('sixel_red.six'), Mode::Sixel);
        $rows = [];
        for ($y = 0; $y < $grid->height(); $y++) {
            $row = [];
            for ($x = 0; $x < $grid->width(); $x++) {
                [$r, $g, $b] = $grid->pixel($x, $y) ?? [0, 0, 0];
                $row[] = [$r - 3, $g + 2, $b];
            }
            $rows[] = $row;
        }

        // Off-by-a-few per channel passes at tolerance 4, would fail at 0.
        GraphicsAssertions::assertRendersTo(Fixture::bytes('sixel_red.six'), Mode::Sixel, $rows, 4);
        self::assertTrue(true);
    }

    public function testAssertRendersToToleranceIsEnforced(): void
    {
        // The same 3/2-channel offset that passes at tolerance 4 must fail at 0,
        // proving the tolerance value is actually honoured by the comparison.
        $grid = GraphicsAssertions::renderGrid(Fixture::bytes('sixel_red.six'), Mode::Sixel);
        $rows = [];
        for ($y = 0; $y < $grid->height(); $y++) {
            $row = [];
            for ($x = 0; $x < $grid->width(); $x++) {
                [$r, $g, $b] = $grid->pixel($x, $y) ?? [0, 0, 0];
                $row[] = [$r - 3, $g + 2, $b];
            }
            $rows[] = $row;
        }

        $this->expectException(AssertionFailedError::class);
        GraphicsAssertions::assertRendersTo(Fixture::bytes('sixel_red.six'), Mode::Sixel, $rows, 0);
    }

    public function testAssertRendersToFailsOnColourMismatch(): void
    {
        $rows = [];
        for ($y = 0; $y < 40; $y++) {
            $rows[] = array_fill(0, 80, [0, 0, 255]); // blue, but the image is red
        }

        $this->expectException(AssertionFailedError::class);
        GraphicsAssertions::assertRendersTo(Fixture::bytes('sixel_red.six'), Mode::Sixel, PixelGrid::fromRgbGrid($rows), 0);
    }

    public function testAssertRendersToFailsOnDimensionMismatch(): void
    {
        $rows = [];
        for ($y = 0; $y < 10; $y++) {
            $rows[] = array_fill(0, 10, [255, 0, 0]);
        }

        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('dimensions differ');
        GraphicsAssertions::assertRendersTo(Fixture::bytes('sixel_red.six'), Mode::Sixel, PixelGrid::fromRgbGrid($rows));
    }

    public function testAssertInlineImageDimensions(): void
    {
        GraphicsAssertions::assertInlineImageDimensions(Fixture::bytes('sixel_red.six'), Mode::Sixel, 80, 40);
        GraphicsAssertions::assertInlineImageDimensions(Fixture::bytes('kitty_red.kitty'), Mode::Kitty, 8, 4);
        GraphicsAssertions::assertInlineImageDimensions(Fixture::bytes('iterm2_red.iterm2'), Mode::Iterm2, 8, 4);
        self::assertTrue(true);
    }

    public function testAssertInlineImageDimensionsSkipsNullAxis(): void
    {
        GraphicsAssertions::assertInlineImageDimensions(Fixture::bytes('sixel_red.six'), Mode::Sixel, 80, null);
        self::assertTrue(true);
    }

    public function testAssertInlineImageDimensionsFailsOnWrongSize(): void
    {
        $this->expectException(AssertionFailedError::class);
        $this->expectExceptionMessage('width');
        GraphicsAssertions::assertInlineImageDimensions(Fixture::bytes('sixel_red.six'), Mode::Sixel, 999, 40);
    }

    public function testAssertProtocolRoundTripPassesForEveryFixture(): void
    {
        foreach (['sixel_red.six', 'sixel_checker.six'] as $name) {
            GraphicsAssertions::assertProtocolRoundTrip(Fixture::bytes($name), Mode::Sixel);
        }
        foreach (['kitty_red.kitty', 'kitty_chunked.kitty', 'kitty_zlib.kitty', 'kitty_place.kitty'] as $name) {
            GraphicsAssertions::assertProtocolRoundTrip(Fixture::bytes($name), Mode::Kitty);
        }
        GraphicsAssertions::assertProtocolRoundTrip(Fixture::bytes('iterm2_red.iterm2'), Mode::Iterm2);
        self::assertTrue(true);
    }

    public function testAssertProtocolRoundTripPropagatesDecodeFailure(): void
    {
        $this->expectException(\SugarCraft\Testing\Graphics\MalformedGraphicsException::class);
        GraphicsAssertions::assertProtocolRoundTrip("\x1bP0;1;0q\"1;1;4;4#0;2;100;0;0#0\x00\x1b\\", Mode::Sixel);
    }

    public function testRenderGridFromKittyUsesGd(): void
    {
        if (!function_exists('imagecreatefromstring')) {
            self::markTestSkipped('ext-gd is required to rasterise a Kitty PNG');
        }

        $grid = GraphicsAssertions::renderGrid(Fixture::bytes('kitty_red.kitty'), Mode::Kitty);

        self::assertSame(8, $grid->width());
        self::assertSame(4, $grid->height());
    }
}
