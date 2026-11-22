<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * What the band-stream tests share. Finding #17's pins used to be one file of
 * about fifty seconds; the expensive renders now sit in files of their own
 * (SixelStreamConcatenationTest, SixelStreamFramingTest,
 * SixelStreamBandDiffTest, SixelStreamQualityRoutingTest) so ParaTest, which
 * hands a worker one whole file at a time, can run them side by side.
 */
trait SixelStreamFixtures
{
    private const ESC = "\x1b";

    /**
     * Collect every `$write` argument from a band stream in order.
     *
     * @return list<string>
     */
    private function collect(SixelRenderer $renderer, ImageSource $image, int $width, ?int $height = null): array
    {
        $chunks = [];
        $renderer->encodeBandStream($image, $width, static function (string $chunk) use (&$chunks): void {
            $chunks[] = $chunk;
        }, $height);

        return $chunks;
    }

    /**
     * @return array<string, array{0: SixelRenderer, 1: string, 2: int, 3: int|null}>
     */
    public static function corpus(): array
    {
        $plain = new SixelRenderer();
        $atkinson = new SixelRenderer(Dither::Atkinson);
        $ordered = new SixelRenderer(Dither::FloydSteinberg);
        $fewColors = new SixelRenderer(Dither::None, 16);
        $truecolorish = new SixelRenderer(Dither::Stucki, 256);
        $tallCells = new SixelRenderer(Dither::None, 256, 10, 20);

        return [
            // width 8 → pixelW 80 (non-multiple of 6), height 4 → partial last band.
            'flat red, default cells' => [$plain, __DIR__ . '/../fixtures/8x4_red.png', 8, 4],
            'opaque noise, truecolor dither' => [$truecolorish, __DIR__ . '/../fixtures/500x400_noise.png', 30, 24],
            'transparent hole, atkinson' => [$atkinson, __DIR__ . '/../fixtures/2x2_transparent_halfblock.png', 12, null],
            'few colours, no dither' => [$fewColors, __DIR__ . '/../fixtures/500x400_noise.png', 17, null],
            'aspect-derived height' => [$ordered, __DIR__ . '/../fixtures/8x4_red.png', 13, null],
            'tall cell box' => [$tallCells, __DIR__ . '/../fixtures/500x400_noise.png', 9, 7],
        ];
    }
}
