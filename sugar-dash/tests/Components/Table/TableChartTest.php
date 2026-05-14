<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Table;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Components\Table\TableChart;

final class TableChartTest extends TestCase
{
    public function testNewCreatesTable(): void
    {
        $table = TableChart::new(
            ['Name', 'Value'],
            [['Alice', '100'], ['Bob', '200']]
        );

        $this->assertNotNull($table);
    }

    public function testRenderReturnsNonEmpty(): void
    {
        $table = TableChart::new(
            ['Name', 'Value'],
            [['Alice', '100']]
        );

        $rendered = $table->render();
        $this->assertNotSame('', $rendered);
    }

    public function testSampleCreatesTable(): void
    {
        $table = TableChart::sample();
        $rendered = $table->render();
        $this->assertNotSame('', $rendered);
    }

    public function testGetInnerSizeReturnsDimensions(): void
    {
        $table = TableChart::new(
            ['Name', 'Value'],
            [['Alice', '100']]
        );

        [$width, $height] = $table->getInnerSize();
        $this->assertGreaterThan(0, $width);
        $this->assertGreaterThan(0, $height);
    }

    public function testWithRowsReturnsNewInstance(): void
    {
        $table = TableChart::new(['Name'], []);
        $newTable = $table->withRows([['Alice']]);
        $this->assertNotSame($table, $newTable);
    }

    public function testWithShowHeaderReturnsNewInstance(): void
    {
        $table = TableChart::new(['Name'], []);
        $newTable = $table->withShowHeader(false);
        $this->assertNotSame($table, $newTable);
    }

    public function testWithZebraStripingReturnsNewInstance(): void
    {
        $table = TableChart::new(['Name'], []);
        $newTable = $table->withZebraStriping(false);
        $this->assertNotSame($table, $newTable);
    }

    public function testWithBorderStyleReturnsNewInstance(): void
    {
        $table = TableChart::new(['Name'], []);
        $newTable = $table->withBorderStyle('bold');
        $this->assertNotSame($table, $newTable);
    }
}