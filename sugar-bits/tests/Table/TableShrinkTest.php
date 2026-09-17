<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use SugarCraft\Bits\Table\Column;
use SugarCraft\Bits\Table\Table;
use PHPUnit\Framework\TestCase as FrameworkTestCase;

/**
 * Pins the proportional column-shrink introduced by E736/2.3: total excess
 * over the width budget is shed floor(excess × width / total) per column,
 * remainder right-to-left — never below zero, never more than a column has.
 */
final class TableShrinkTest extends FrameworkTestCase
{
    /**
     * Build the same table twice: once auto-shrunk under a width budget,
     * once with explicit per-column widths equal to the expected
     * proportional result. Byte equality proves the formula AND the
     * truncation path in one shot (unconstrained render shares no cache).
     *
     * @param list<string>       $headers
     * @param list<list<string>> $rows
     * @param list<int>          $expected
     */
    private function assertShrinksTo(array $headers, array $rows, int $budgetWidth, array $expected): void
    {
        $shrunk = Table::new($headers, $rows, $budgetWidth, 10)->view();
        $columns = [];
        foreach ($headers as $i => $title) {
            $columns[] = new Column($title, $expected[$i]);
        }
        $manual = Table::new($headers, $rows, 0, 10)->setColumns($columns);
        $this->assertSame($manual->view(), $shrunk, 'shrink must land exactly on the proportional widths');
        foreach (explode("\n", $shrunk) as $line) {
            $plain = preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line;
            $this->assertLessThanOrEqual($budgetWidth, mb_strlen($plain), 'line must fit the budget');
        }
    }

    public function testSkewedColumnsShrinkProportionallyNotRightToLeftToZero(): void
    {
        // widths [10, 2]; width 7 → gutter 1 → budget 6; excess 6.
        // floor: col0 −5 → 5, col1 −1 → 1. The old round-robin loop
        // would have produced [6, 0] — this is the behavior delta pinned.
        $this->assertShrinksTo(
            ['Name', 'X'],
            [['aaaaaaaaaa', 'bb']],
            7,
            [5, 1],
        );
    }

    public function testIndivisibleRemainderGoesRightToLeft(): void
    {
        // widths [4, 4, 4]; width 10 → gutter 2 → budget 8; excess 4.
        // floor −1 each → [3,3,3], remainder 1 handed to the rightmost.
        $this->assertShrinksTo(
            ['A', 'B', 'C'],
            [['aaaa', 'bbbb', 'cccc']],
            10,
            [3, 3, 2],
        );
    }

    public function testSingleColumnAbsorbsEntireExcess(): void
    {
        $this->assertShrinksTo(['Only'], [['abcdefghij']], 4, [4]);
    }

    public function testEmptyRowsStillRenderHeadersWithoutCrashing(): void
    {
        // widths [1,1,1]; width 4 → gutter 2 → budget 2; excess 1:
        // floors are 0 each → the single remainder goes right-to-left,
        // trimming col 2. A width-0 column cannot be force-built via
        // setColumns (0 means auto), so assert the raw line instead.
        $view  = Table::new(['A', 'B', 'C'], [], 4, 10)->view();
        $plain = preg_replace('/\e\[[0-9;]*m/', '', $view) ?? $view;
        $this->assertSame('A B ', $plain);
    }

    public function testZeroBudgetCollapsesEveryColumnToZero(): void
    {
        $view = Table::new(['A', 'B', 'C'], [['xxxx', 'yyyy', 'zzzz']], 1, 10)->view();
        foreach (explode("\n", $view) as $line) {
            $plain = preg_replace('/\e\[[0-9;]*m/', '', $line) ?? $line;
            $this->assertSame('  ', $plain, 'all-zero columns leave only the two gutters');
        }
    }

    public function testUnconstrainedTableIsNeverShrunk(): void
    {
        $view = Table::new(['Name'], [['aaaaaaaaaa']], 0, 10)->view();
        $this->assertStringContainsString('aaaaaaaaaa', $view);
    }
}
