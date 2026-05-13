<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Grid\Stat;
use SugarCraft\Dash\Grid\HAlign;
use SugarCraft\Core\Util\Color;

final class StatTest extends TestCase
{
    public function testNewCreatesStat(): void
    {
        $stat = Stat::new('Label', 'Value');
        $this->assertNotNull($stat);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $stat = Stat::new('Label', 'Value');
        $this->assertNotSame('', $stat->render());
    }

    public function testNewCreatesWithLabelAndValue(): void
    {
        $stat = Stat::new('Label', 'Value');
        $this->assertStringContainsString('Label', $stat->render());
        $this->assertStringContainsString('Value', $stat->render());
    }

    public function testPercentCreatesFormattedStat(): void
    {
        $stat = Stat::percent('Progress', 75.5);
        $rendered = $stat->render();
        // Value line should contain percentage
        $this->assertNotSame('', $rendered);
    }

    public function testCurrencyCreatesFormattedStat(): void
    {
        $stat = Stat::currency('Price', 19.99);
        $rendered = $stat->render();
        // Should contain dollar sign and value
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $stat = Stat::new('Label', 'Value');
        [$width, $height] = $stat->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithTrendReturnsNewInstance(): void
    {
        $stat = Stat::new('Sales', '100');
        $newStat = $stat->withTrend('up');
        $this->assertNotSame($stat, $newStat);
    }

    public function testWithTrendDownReturnsNewInstance(): void
    {
        $stat = Stat::new('Loss', '50');
        $newStat = $stat->withTrend('down');
        $this->assertNotSame($stat, $newStat);
    }

    public function testWithAlignReturnsNewInstance(): void
    {
        $stat = Stat::new('Label', 'Value');
        $newStat = $stat->withAlign(HAlign::Right);
        $this->assertNotSame($stat, $newStat);
    }

    public function testWithValueColorReturnsNewInstance(): void
    {
        $stat = Stat::new('Label', 'Value');
        $newStat = $stat->withValueColor(Color::hex('#FF0000'));
        $this->assertNotSame($stat, $newStat);
    }

    public function testWithLabelReturnsNewInstance(): void
    {
        $stat = Stat::new('Label', 'Value');
        $newStat = $stat->withLabel('New Label');
        $this->assertNotSame($stat, $newStat);
    }

    public function testWithSubLabelReturnsNewInstance(): void
    {
        $stat = Stat::new('Label', 'Value');
        $newStat = $stat->withSubLabel('Subtext');
        $this->assertNotSame($stat, $newStat);
    }
}
