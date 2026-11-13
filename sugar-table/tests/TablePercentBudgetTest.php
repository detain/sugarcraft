<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, ColumnWidth, Row, RowData, Table};

/** OG-9 / ST-61: over-subscribed Percent sets terminate inside the declared width; strict mode throws instead. */
final class TablePercentBudgetTest extends TestCase
{
    private function table(float $a, float $b): Table
    {
        return Table::fromColumns([
            Column::new('a', 'A', 5)->withWidthMode(ColumnWidth::Percent, $a),
            Column::new('b', 'B', 5)->withWidthMode(ColumnWidth::Percent, $b),
        ])->withRows([Row::new(RowData::from(['a' => 'x', 'b' => 'y']))]);
    }

    /** @return list<int> */
    private function cellWidths(Table $t): array
    {
        return \array_map(static fn (string $l): int => \mb_strlen((string) \preg_replace('/\e\[[0-9;]*m/', '', $l)), \explode("\n", $t->view()));
    }

    public function testTwoSixtyPercentColumnsStayInsideTheTotalWidth(): void
    {
        $t = $this->table(60.0, 60.0);
        $solved = $t->computeColumnWidths($t->totalWidth());
        $this->assertLessThanOrEqual($t->totalWidth(), \array_sum($solved) + 1);
        $this->assertCount(1, \array_unique($this->cellWidths($t)), 'uniform box');
        $this->assertSame($this->cellWidths($this->table(50.0, 50.0)), $this->cellWidths($t), 'same box as the normalised 50/50 set');
    }

    public function testPinnedOverSubscriptionStillClampsToThePin(): void
    {
        $this->assertSame([202, 202, 202, 202, 202], $this->cellWidths($this->table(60.0, 60.0)->withWidth(200)));
    }

    public function testSetsAtOrUnderOneHundredAreUntouched(): void
    {
        $this->assertSame([30, 70], \array_values($this->table(30.0, 70.0)->computeColumnWidths(100)));
    }

    public function testStrictPercentThrowsAtConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('120%');
        $this->table(60.0, 60.0)->withStrictPercent();
    }

    public function testStrictPercentAcceptsAtOrUnder100AndGuardsColumnSwaps(): void
    {
        $strict = $this->table(50.0, 50.0)->withStrictPercent();
        $this->assertNotSame('', $strict->view());
        $this->expectException(\InvalidArgumentException::class);
        $strict->withColumns([
            Column::new('a', 'A', 5)->withWidthMode(ColumnWidth::Percent, 80.0),
            Column::new('b', 'B', 5)->withWidthMode(ColumnWidth::Percent, 80.0),
        ]);
    }

    public function testStrictPercentIgnoresHiddenColumns(): void
    {
        $t = $this->table(60.0, 60.0)->withHiddenCols([1])->withStrictPercent();
        $this->assertTrue($t->withStrictPercent(false)->view() !== '');
        $this->assertNotSame('', $t->view());
    }
}
