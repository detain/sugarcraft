<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, Row, RowData, Table};
use PHPUnit\Framework\TestCase;

final class TableViewportTest extends TestCase
{
    private function makeLargeTable(): Table
    {
        $rows = [];
        for ($i = 0; $i < 100; $i++) {
            $rows[] = Row::new(RowData::from(['id' => (string) $i, 'name' => "User{$i}"]));
        }

        return Table::withColumns([
            Column::new('id', 'ID', 5),
            Column::new('name', 'Name', 20),
        ])->withRows($rows);
    }

    public function testViewportHeightZeroRendersAllRows(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(0);
        $view = $t->View();

        // All 100 rows should be visible when viewportHeight is 0
        $this->assertStringContainsString('User0', $view);
        $this->assertStringContainsString('User99', $view);
    }

    public function testViewportHeightRestrictsVisibleRows(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10);
        $view = $t->View();

        // Only 10 rows should be visible
        $this->assertStringContainsString('User0', $view);
        $this->assertStringNotContainsString('User99', $view);
    }

    public function testScrollYSlicesVisibleRows(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(5)->withScrollY(10);
        $view = $t->View();

        // Rows 10-14 should be visible (User10, User11, User12, User13, User14)
        $this->assertStringContainsString('User10', $view);
        $this->assertStringNotContainsString('User0', $view);
        $this->assertStringNotContainsString('User15', $view);
    }

    public function testScrollYAccessor(): void
    {
        $t = $this->makeLargeTable()->withScrollY(25);
        $this->assertSame(25, $t->scrollY());
    }

    public function testScrollYAboveRowCountIsSafe(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(5)->withScrollY(200);
        $view = $t->View();

        // Should not render anything when scrollY exceeds row count
        $this->assertStringNotContainsString('User0', $view);
    }

    public function testWithViewportHeightReturnsNewInstance(): void
    {
        $a = $this->makeLargeTable();
        $b = $a->withViewportHeight(10);
        $this->assertNotSame($a, $b);
        // withViewportHeight does not change scrollY, scrollY should remain 0
        $this->assertSame(0, $a->scrollY());
        $this->assertSame(0, $b->scrollY());
        // Check the view shows limited rows (viewportHeight 10 on 100 rows)
        $view = $b->View();
        $this->assertStringContainsString('User0', $view);
        $this->assertStringNotContainsString('User99', $view);
    }

    public function testWithScrollYReturnsNewInstance(): void
    {
        $a = $this->makeLargeTable()->withViewportHeight(10);
        $b = $a->withScrollY(5);
        $this->assertNotSame($a, $b);
        $this->assertSame(0, $a->scrollY());
        $this->assertSame(5, $b->scrollY());
    }

    public function testViewportWithPagination(): void
    {
        $t = $this->makeLargeTable()
            ->withPageSize(20)
            ->withViewportHeight(5)
            ->withScrollY(0);

        $view = $t->View();
        // Page size is 20, viewport shows 5 rows
        $this->assertStringContainsString('User0', $view);
        $this->assertStringNotContainsString('User5', $view);
    }
}
