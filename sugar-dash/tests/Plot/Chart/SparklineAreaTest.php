<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\SparklineArea;

/**
 * E731 round-85: SparklineArea carried no tests at all; this file pins the
 * area-side arms of the SparklineScaling trait helpers.
 */
final class SparklineAreaTest extends TestCase
{
    public function testSparklineAreaUpscaleDownscaleArmsThroughTraitCopy(): void
    {
        $resample = new \ReflectionMethod(SparklineArea::class, 'normalizeData');

        $up = SparklineArea::new([0.0, 1.0, 2.0]);
        $this->assertSame([0.0, 2.0 / 3, 4.0 / 3, 2.0], $resample->invoke($up, 4));

        $down = SparklineArea::new([0.0, 1.0, 2.0, 3.0, 4.0, 5.0]);
        $this->assertSame([0.0, 1.0, 3.0, 4.0], $resample->invoke($down, 4));

        $this->assertSame([], $resample->invoke(SparklineArea::new([]), 5));
    }

    public function testSparklineAreaWidthLegsThroughTraitCopy(): void
    {
        $data = [2.0, 7.0, 4.0];
        $countLeg = SparklineArea::new($data)->render();
        $this->assertSame(
            $countLeg,
            SparklineArea::new($data)->withWidth(3)->render(),
            'constraint leg matches the count leg when widths coincide'
        );
        $this->assertSame(
            $countLeg,
            SparklineArea::new($data)->setSize(3, 3)->render(),
            'explicit size leg matches the count leg when widths coincide'
        );
        $this->assertNotSame(
            $countLeg,
            SparklineArea::new($data)->setSize(9, 3)->render(),
            'a wider explicit size stretches the series'
        );
        $this->assertNotSame(
            $countLeg,
            SparklineArea::new($data)->withWidth(8)->render(),
            'a wider constraint upsamples the series through the constraint leg'
        );
    }
}
