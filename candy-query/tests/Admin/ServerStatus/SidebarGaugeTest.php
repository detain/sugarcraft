<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests\Admin\ServerStatus;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Color;
use SugarCraft\Query\Admin\ServerStatus\GaugeType;
use SugarCraft\Query\Admin\ServerStatus\SidebarGauge;

/**
 * Tests for SidebarGauge.
 */
final class SidebarGaugeTest extends TestCase
{
    public function testNewCreatesInstance(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Cpu, 0.5);
        $this->assertInstanceOf(SidebarGauge::class, $gauge);
    }

    public function testRatioIsClampedToOne(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Connections, 1.5);
        $this->assertEquals(1.0, $gauge->ratio());
    }

    public function testRatioIsClampedToZero(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Traffic, -0.5);
        $this->assertEquals(0.0, $gauge->ratio());
    }

    public function testRatioIsPreservedWhenInRange(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Qps, 0.75);
        $this->assertEquals(0.75, $gauge->ratio());
    }

    public function testTypeAccessor(): void
    {
        $gauge = SidebarGauge::new(GaugeType::InnoDB, 0.5);
        $this->assertSame(GaugeType::InnoDB, $gauge->type());
    }

    public function testLabelReturnsCpuForCpuType(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Cpu, 0.5);
        $this->assertSame('CPU', $gauge->label());
    }

    public function testLabelReturnsConnectionsForConnectionsType(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Connections, 0.5);
        $this->assertSame('Connections', $gauge->label());
    }

    public function testLabelReturnsTrafficForTrafficType(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Traffic, 0.5);
        $this->assertSame('Traffic', $gauge->label());
    }

    public function testLabelReturnsKeyEffForKeyEfficiencyType(): void
    {
        $gauge = SidebarGauge::new(GaugeType::KeyEfficiency, 0.5);
        $this->assertSame('Key Eff', $gauge->label());
    }

    public function testLabelReturnsQpsForQpsType(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Qps, 0.5);
        $this->assertSame('QPS', $gauge->label());
    }

    public function testLabelReturnsInnoDBForInnoDBType(): void
    {
        $gauge = SidebarGauge::new(GaugeType::InnoDB, 0.5);
        $this->assertSame('InnoDB', $gauge->label());
    }

    public function testViewReturnsString(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Connections, 0.5);
        $view = $gauge->view();
        $this->assertIsString($view);
    }

    public function testViewContainsLabel(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Connections, 0.5);
        $view = $gauge->view();
        $this->assertStringContainsString('Connections', $view);
    }

    public function testViewForCpuGaugeReturnsNonEmpty(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Cpu, 0.5);
        $view = $gauge->view();
        $this->assertNotEmpty($view);
    }

    public function testThresholdColorReturnsGreenForLowRatio(): void
    {
        $color = SidebarGauge::thresholdColor(0.3);
        $this->assertInstanceOf(Color::class, $color);
        // Green hex should be #4ade80
        $this->assertSame('#4ade80', $color->toHex());
    }

    public function testThresholdColorReturnsGreenForZeroRatio(): void
    {
        $color = SidebarGauge::thresholdColor(0.0);
        $this->assertSame('#4ade80', $color->toHex());
    }

    public function testThresholdColorReturnsGreenAtBoundary(): void
    {
        // Just under 0.6 should be green
        $color = SidebarGauge::thresholdColor(0.59);
        $this->assertSame('#4ade80', $color->toHex());
    }

    public function testThresholdColorReturnsYellowAtBoundary(): void
    {
        // At 0.6 should be yellow
        $color = SidebarGauge::thresholdColor(0.6);
        $this->assertSame('#facc15', $color->toHex());
    }

    public function testThresholdColorReturnsYellowInMiddle(): void
    {
        $color = SidebarGauge::thresholdColor(0.7);
        $this->assertSame('#facc15', $color->toHex());
    }

    public function testThresholdColorReturnsYellowJustBelowRed(): void
    {
        // Just under 0.8 should be yellow
        $color = SidebarGauge::thresholdColor(0.79);
        $this->assertSame('#facc15', $color->toHex());
    }

    public function testThresholdColorReturnsRedAtBoundary(): void
    {
        // At 0.8 should be red
        $color = SidebarGauge::thresholdColor(0.8);
        $this->assertSame('#f87171', $color->toHex());
    }

    public function testThresholdColorReturnsRedForHighRatio(): void
    {
        $color = SidebarGauge::thresholdColor(0.95);
        $this->assertSame('#f87171', $color->toHex());
    }

    public function testThresholdColorReturnsRedAtOne(): void
    {
        $color = SidebarGauge::thresholdColor(1.0);
        $this->assertSame('#f87171', $color->toHex());
    }

    public function testThresholdColorClampsNegativeToGreen(): void
    {
        $color = SidebarGauge::thresholdColor(-0.5);
        $this->assertSame('#4ade80', $color->toHex());
    }

    public function testThresholdColorClampsBeyondOneToRed(): void
    {
        $color = SidebarGauge::thresholdColor(1.5);
        $this->assertSame('#f87171', $color->toHex());
    }

    public function testWithRatioReturnsNewInstance(): void
    {
        $gauge1 = SidebarGauge::new(GaugeType::Connections, 0.5);
        $gauge2 = $gauge1->withRatio(0.75);

        $this->assertNotSame($gauge1, $gauge2);
        $this->assertSame(0.5, $gauge1->ratio());
        $this->assertSame(0.75, $gauge2->ratio());
    }

    public function testWithRatioClampsValue(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Connections, 0.5);
        $updated = $gauge->withRatio(1.5);

        $this->assertEquals(1.0, $updated->ratio());
    }

    public function testWithRatioPreservesType(): void
    {
        $gauge = SidebarGauge::new(GaugeType::Traffic, 0.3);
        $updated = $gauge->withRatio(0.6);

        $this->assertSame(GaugeType::Traffic, $updated->type());
    }

    public function testEnumCasesAreStringBacked(): void
    {
        $this->assertSame('cpu', GaugeType::Cpu->value);
        $this->assertSame('connections', GaugeType::Connections->value);
        $this->assertSame('traffic', GaugeType::Traffic->value);
        $this->assertSame('key_efficiency', GaugeType::KeyEfficiency->value);
        $this->assertSame('qps', GaugeType::Qps->value);
        $this->assertSame('innodb', GaugeType::InnoDB->value);
    }
}
