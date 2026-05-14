<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Plot\Chart;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Plot\Chart\GaugeChart;

final class GaugeChartTest extends TestCase
{
    public function testNewCreatesGauge(): void
    {
        $gauge = GaugeChart::new(50.0, 0.0, 100.0);
        $this->assertNotNull($gauge);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $gauge = GaugeChart::new(50.0, 0.0, 100.0);
        $rendered = $gauge->render();
        $this->assertNotSame('', $rendered);
    }

    public function testPercentCreatesGauge(): void
    {
        $gauge = GaugeChart::percent(75.0);
        $rendered = $gauge->render();
        $this->assertNotSame('', $rendered);
    }

    public function testCpuCreatesGauge(): void
    {
        $gauge = GaugeChart::cpu(45.0);
        $rendered = $gauge->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $gauge = GaugeChart::new(50.0, 0.0, 100.0);
        [$width, $height] = $gauge->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithValueReturnsNewInstance(): void
    {
        $gauge = GaugeChart::new(50.0, 0.0, 100.0);
        $newGauge = $gauge->withValue(75.0);
        $this->assertNotSame($gauge, $newGauge);
    }

    public function testWithRangeReturnsNewInstance(): void
    {
        $gauge = GaugeChart::new(50.0, 0.0, 100.0);
        $newGauge = $gauge->withRange(0.0, 50.0);
        $this->assertNotSame($gauge, $newGauge);
    }

    public function testWithFormatReturnsNewInstance(): void
    {
        $gauge = GaugeChart::new(50.0, 0.0, 100.0);
        $newGauge = $gauge->withFormat('percent');
        $this->assertNotSame($gauge, $newGauge);
    }
}