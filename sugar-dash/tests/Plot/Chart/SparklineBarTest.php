<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\SparklineBar;

/**
 * E731 round-85: SparklineBar carried no tests at all; this file pins the
 * resampling and width-resolution helpers it shares with SparklineArea
 * sibling via the SparklineScaling trait.
 */
final class SparklineBarTest extends TestCase
{
    public function testSparklineBarNormalizationArmsThroughSparklineScaling(): void
    {
        $resample = new \ReflectionMethod(SparklineBar::class, 'normalizeData');

        $up = SparklineBar::new([0.0, 1.0]);
        $this->assertSame([0.0, 1.0 / 3, 2.0 / 3, 1.0], $resample->invoke($up, 4));

        $down = SparklineBar::new([0.0, 1.0, 2.0, 3.0]);
        $this->assertSame([0.0, 2.0], $resample->invoke($down, 2));

        $exact = SparklineBar::new([5.0, 6.0]);
        $this->assertSame([5.0, 6.0], $resample->invoke($exact, 2));

        $this->assertSame([], $resample->invoke(SparklineBar::new([]), 4));
    }

    public function testSparklineBarWidthFallbackLegsThroughSparklineScaling(): void
    {
        $three = [1.0, 5.0, 3.0];
        $countLeg = SparklineBar::new($three)->render();
        $this->assertSame(
            $countLeg,
            SparklineBar::new($three)->withWidth(3)->render(),
            'a widthConstraint equal to the data count renders the count leg exactly'
        );
        $this->assertSame(
            $countLeg,
            SparklineBar::new($three)->setSize(3, 8)->render(),
            'an explicit size equal to the data count renders the count leg exactly'
        );
        $this->assertNotSame(
            $countLeg,
            SparklineBar::new($three)->withWidth(8)->render(),
            'a wider constraint upsamples the series'
        );
    }
}
