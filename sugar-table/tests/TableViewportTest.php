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

    // -------------------------------------------------------------------------
    // Keyboard scrolling helpers
    // -------------------------------------------------------------------------

    public function testKeyConstantsExist(): void
    {
        $this->assertSame('arrowUp', Table::KEY_ARROW_UP);
        $this->assertSame('arrowDown', Table::KEY_ARROW_DOWN);
        $this->assertSame('pageUp', Table::KEY_PAGE_UP);
        $this->assertSame('pageDown', Table::KEY_PAGE_DOWN);
        $this->assertSame('home', Table::KEY_HOME);
        $this->assertSame('end', Table::KEY_END);
    }

    public function testScrollYForKeyArrowUp(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(5);
        $this->assertSame(4, $t->scrollYForKey(Table::KEY_ARROW_UP));
    }

    public function testScrollYForKeyArrowUpAtZero(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(0);
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_ARROW_UP));
    }

    public function testScrollYForKeyArrowDown(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(5);
        // maxScrollY = 100 - 10 = 90
        $this->assertSame(6, $t->scrollYForKey(Table::KEY_ARROW_DOWN));
    }

    public function testScrollYForKeyArrowDownAtMax(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(90);
        $this->assertSame(90, $t->scrollYForKey(Table::KEY_ARROW_DOWN));
    }

    public function testScrollYForKeyPageUp(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(50);
        $this->assertSame(40, $t->scrollYForKey(Table::KEY_PAGE_UP));
    }

    public function testScrollYForKeyPageUpAtZero(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(5);
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_PAGE_UP));
    }

    public function testScrollYForKeyPageDown(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(50);
        $this->assertSame(60, $t->scrollYForKey(Table::KEY_PAGE_DOWN));
    }

    public function testScrollYForKeyPageDownAtMax(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(85);
        // maxScrollY = 90, so pageDown should clamp to 90
        $this->assertSame(90, $t->scrollYForKey(Table::KEY_PAGE_DOWN));
    }

    public function testScrollYForKeyHome(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(50);
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_HOME));
    }

    public function testScrollYForKeyEnd(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(50);
        // maxScrollY = 100 - 10 = 90
        $this->assertSame(90, $t->scrollYForKey(Table::KEY_END));
    }

    public function testScrollYForKeyUnknownKeyReturnsCurrent(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(25);
        $this->assertSame(25, $t->scrollYForKey('unknownKey'));
    }

    public function testScrollYForKeyWithZeroViewportHeight(): void
    {
        // When viewportHeight is 0, maxScrollY is 0, so no scrolling possible
        $t = $this->makeLargeTable()->withViewportHeight(0)->withScrollY(0);
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_ARROW_DOWN));
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_PAGE_DOWN));
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_END));
    }

    public function testHandleKeyReturnsNewInstance(): void
    {
        $a = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(5);
        $b = $a->handleKey(Table::KEY_ARROW_UP);
        $this->assertNotSame($a, $b);
        $this->assertSame(5, $a->scrollY());
        $this->assertSame(4, $b->scrollY());
    }

    public function testHandleKeyArrowDown(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(5);
        $t2 = $t->handleKey(Table::KEY_ARROW_DOWN);
        $this->assertSame(6, $t2->scrollY());
    }

    public function testHandleKeyPageDown(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(50);
        $t2 = $t->handleKey(Table::KEY_PAGE_DOWN);
        $this->assertSame(60, $t2->scrollY());
    }

    public function testHandleKeyHome(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(50);
        $t2 = $t->handleKey(Table::KEY_HOME);
        $this->assertSame(0, $t2->scrollY());
    }

    public function testHandleKeyEnd(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(50);
        $t2 = $t->handleKey(Table::KEY_END);
        $this->assertSame(90, $t2->scrollY());
    }

    public function testHandleKeyUnknownKeyReturnsUnchangedScrollY(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(25);
        $t2 = $t->handleKey('unknownKey');
        $this->assertSame(25, $t2->scrollY());
    }

    public function testKeyboardScrollingIntegration(): void
    {
        // Simulate a user pressing keys to scroll through the table
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(0);

        // Press arrow down a few times
        $t = $t->handleKey(Table::KEY_ARROW_DOWN);
        $this->assertSame(1, $t->scrollY());
        $t = $t->handleKey(Table::KEY_ARROW_DOWN);
        $this->assertSame(2, $t->scrollY());

        // Press home to go back to top
        $t = $t->handleKey(Table::KEY_HOME);
        $this->assertSame(0, $t->scrollY());

        // Press page down to jump ahead
        $t = $t->handleKey(Table::KEY_PAGE_DOWN);
        $this->assertSame(10, $t->scrollY());

        // Press end to go to the bottom
        $t = $t->handleKey(Table::KEY_END);
        $this->assertSame(90, $t->scrollY());
    }

    public function testScrollYForKeyWithFilteredRows(): void
    {
        // Apply a filter that reduces rows: "User9" matches User9, User90-99 (11 rows)
        $t = $this->makeLargeTable()
            ->withViewportHeight(5)
            ->withScrollY(0)
            ->Filter('name', 'User9'); // Only User9* rows match

        // maxScrollY should be max(0, 11 - 5) = 6
        $this->assertSame(6, $t->scrollYForKey(Table::KEY_END));
    }
}
