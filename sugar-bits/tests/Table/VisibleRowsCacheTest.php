<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use SugarCraft\Bits\Table\SortDirection;
use SugarCraft\Bits\Table\Table;
use PHPUnit\Framework\TestCase;

/**
 * Pins E736/3.3: the visibleRows() projection is memoized per immutable
 * instance, and every state change (rows/filter/sort) mints a fresh
 * instance whose cache cannot carry the previous projection.
 */
final class VisibleRowsCacheTest extends TestCase
{
    private function table(): Table
    {
        return Table::new(
            ['Name', 'Role'],
            [['Alice', 'dev'], ['Bob', 'ops'], ['Carol', 'dev']],
        );
    }

    public function testRepeatedRendersAreByteIdentical(): void
    {
        $t = $this->table()->withSort('Name', SortDirection::Desc);
        $this->assertSame($t->view(), $t->view(), 'memoized projection must be deterministic');
    }

    public function testCursorMovesNeverStaleTheProjection(): void
    {
        $t = $this->table()->withSort('Name');
        $before = $t->view();
        $moved  = $t->setCursor(2);
        $this->assertSame($before, $moved->view(), 'cursor is not part of the sorted+filtered projection');
        $this->assertSame(['Carol', 'dev'], $moved->selectedRow());
    }

    public function testSetRowsRebuildsTheProjection(): void
    {
        $old = $this->table();
        $new = $old->setRows([['Zed', 'qa']]);
        $this->assertSame(['Zed', 'qa'], $new->selectedRow());
        $this->assertStringContainsString('Zed', $new->view());
        $this->assertStringNotContainsString('Alice', $new->view(), 'stale cache would still render the old rows');
        $this->assertStringContainsString('Alice', $old->view(), 'the original instance must be untouched');
    }

    public function testFilterChangeRebuildsTheProjection(): void
    {
        $t = $this->table()->withFilterable(true)->withFilter('dev');
        $this->assertStringNotContainsString('Bob', $t->view());
        $t2 = $t->withFilter('ops');
        $this->assertStringContainsString('Bob', $t2->view());
        $this->assertStringNotContainsString('Alice', $t2->view(), 'stale cache would keep the dev-filtered rows');
        $this->assertSame(['Bob', 'ops'], $t2->selectedRow());
    }

    public function testSortChangeRebuildsTheProjection(): void
    {
        $asc  = $this->table()->withSort('Name', SortDirection::Asc);
        $desc = $asc->withSort('Name', SortDirection::Desc);
        // assertGreaterThan(expected, actual): actual > expected.
        $this->assertGreaterThan(
            mb_strpos($asc->view(), 'Alice'),
            mb_strpos($asc->view(), 'Carol'),
        );
        $this->assertLessThan(
            mb_strpos($desc->view(), 'Alice'),
            mb_strpos($desc->view(), 'Carol'),
            'stale cache would still render the ascending order',
        );
    }
}
