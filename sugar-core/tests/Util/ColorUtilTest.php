<?php

declare(strict_types=1);

namespace SugarCraft\Core\Tests\Util;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\ColorUtil;

/**
 * First tests for {@see ColorUtil} (finding sugar-core-067 leg: utility
 * classes shipped with zero tests). The docblock names the consumers —
 * sugar-image's color grouping — so the pins here are exact-value, not
 * approximate: a drift in the luma coefficients silently reorders palette
 * quantization downstream.
 */
final class ColorUtilTest extends TestCase
{
    public function testLumaEndPoints(): void
    {
        $this->assertSame(0, ColorUtil::luma(0, 0, 0), 'black stays 0');
        $this->assertSame(255, ColorUtil::luma(255, 255, 255), 'white stays 255: the weights sum to exactly 256, so the >>8 is exact at the top');
    }

    public function testLumaPrimaryChannelsAreExact(): void
    {
        // BT.601 integer weights (77, 150, 29): measured full-intensity arms.
        $this->assertSame(76, ColorUtil::luma(255, 0, 0));   // 19635 >> 8
        $this->assertSame(149, ColorUtil::luma(0, 255, 0));  // 38250 >> 8
        $this->assertSame(28, ColorUtil::luma(0, 0, 255));   //  7395 >> 8
        // The identity that makes the white arm exact: coefficient sum == 256.
        $this->assertSame(256, 77 + 150 + 29);
    }

    public function testLumaRanksChannelsByHumanBrightness(): void
    {
        // The docblock promise: green > red > blue at equal intensity.
        $this->assertGreaterThan(ColorUtil::luma(255, 0, 0), ColorUtil::luma(0, 255, 0));
        $this->assertGreaterThan(ColorUtil::luma(0, 0, 255), ColorUtil::luma(255, 0, 0));
        // Mid-grey is monotone in all three channels.
        $this->assertSame(ColorUtil::luma(128, 128, 128), ((128 * 77) + (128 * 150) + (128 * 29)) >> 8);
    }

    public function testSquaredDistanceIdentitySymmetryAndValues(): void
    {
        $this->assertSame(0, ColorUtil::squaredDistance([10, 20, 30], [10, 20, 30]));
        $this->assertSame(
            ColorUtil::squaredDistance([255, 0, 0], [0, 255, 0]),
            ColorUtil::squaredDistance([0, 255, 0], [255, 0, 0]),
            'distance must be symmetric — palette grouping walks both directions',
        );
        $this->assertSame(130_050, ColorUtil::squaredDistance([255, 0, 0], [0, 255, 0])); // 2 * 255^2
        $this->assertSame(59, ColorUtil::squaredDistance([0, 0, 0], [1, 3, 7]));           // 1 + 9 + 49
    }

    public function testSquaredDistancePreservesTrueDistanceOrdering(): void
    {
        // The whole point of skipping sqrt (docblock): ordering is
        // preserved, so nearest-color comparison needs no root.
        $near = ColorUtil::squaredDistance([0, 0, 0], [10, 10, 10]);
        $far  = ColorUtil::squaredDistance([0, 0, 0], [20, 20, 20]);
        $this->assertLessThan($far, $near);
        $this->assertLessThan(\sqrt($far), \sqrt($near));
        $this->assertSame(300, $near);
        $this->assertSame(1200, $far);
    }
}
