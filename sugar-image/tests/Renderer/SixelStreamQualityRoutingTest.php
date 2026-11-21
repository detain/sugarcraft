<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\ImageSource;
use SugarCraft\Image\Renderer\SixelRenderer;

/**
 * The quality dial routes to the PNN palette only when the colour census
 * outweighs the budget. Split from SixelStreamTest for the reason
 * SixelStreamFixtures gives.
 *
 * @covers \SugarCraft\Image\Renderer\SixelRenderer
 */
final class SixelStreamQualityRoutingTest extends TestCase
{
    use SixelStreamFixtures;

    /**
     * Arming the dial on a sparse census must NOT change bytes — the
     * router sends samples ≤ budget back through median-cut, so the opt-in
     * is inert without merge mass. On a dense census (4096 noise samples vs
     * 256 colours) the PNN path fires and replaces the median-cut palette.
     */
    public function testQualityArmedRoutesOnlyWhenTheCensusOutweighsTheBudget(): void
    {
        $flat = ImageSource::fromFile(__DIR__ . '/../fixtures/8x4_red.png');
        $armedFlat = (new SixelRenderer())->withQuality(5)->render($flat, 8, 4);
        $this->assertStringStartsWith(self::ESC . 'P', $armedFlat);
        $this->assertSame(
            sha1($armedFlat),
            'd883ac34846a2a92d12e03f16e4f793e354a4ad9',
            'quality-armed sparse render keeps the median-cut bytes',
        );

        $noise = ImageSource::fromFile(__DIR__ . '/../fixtures/500x400_noise.png');
        $median = (new SixelRenderer(Dither::Stucki, 256))->render($noise, 30, 24);
        $pnn = (new SixelRenderer(Dither::Stucki, 256))->withQuality(5)->render($noise, 30, 24);

        $this->assertStringStartsWith(self::ESC . 'P', $pnn);
        $this->assertSame(self::ESC . '\\', substr($pnn, -2), 'the PNN render is a well-formed DCS');
        $this->assertNotSame($median, $pnn, 'the dense census must route to the PNN palette');
        $this->assertSame(sha1($median), 'c79e84bfb8cfee4e0b240e73f62a5908b84f98c4', 'the unarmed render keeps its base bytes');
    }
}
