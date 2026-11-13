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

        return Table::fromColumns([
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
        $t = $this->makeLargeTable()->withViewportHeight(5)
            ->withSelectedIndex(12)->withScrollY(10);
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
        // ST-15 law: scroll cannot strand the cursor, so an absurd offset
        // parks the window at maxScroll around a tail cursor — never a blank box.
        $t = $this->makeLargeTable()->withViewportHeight(5)
            ->withSelectedIndex(99)->withScrollY(200);

        $this->assertSame(95, $t->scrollY(), 'window parks with the cursor at its top edge');
        $view = $t->View();
        $this->assertStringContainsString('User95', $view);
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
        $b = $a->withSelectedIndex(5)->withScrollY(5);
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
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(5)->withScrollY(5);
        $this->assertSame(4, $t->scrollYForKey(Table::KEY_ARROW_UP));
    }

    public function testScrollYForKeyArrowUpAtZero(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withScrollY(0);
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_ARROW_UP));
    }

    public function testScrollYForKeyArrowDown(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(5)->withScrollY(5);
        // maxScrollY = 100 - 10 = 90
        $this->assertSame(6, $t->scrollYForKey(Table::KEY_ARROW_DOWN));
    }

    public function testScrollYForKeyArrowDownAtMax(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(90)->withScrollY(90);
        $this->assertSame(90, $t->scrollYForKey(Table::KEY_ARROW_DOWN));
    }

    public function testScrollYForKeyPageUp(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(50)->withScrollY(50);
        $this->assertSame(40, $t->scrollYForKey(Table::KEY_PAGE_UP));
    }

    public function testScrollYForKeyPageUpAtZero(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(5)->withScrollY(5);
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_PAGE_UP));
    }

    public function testScrollYForKeyPageDown(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(50)->withScrollY(50);
        $this->assertSame(60, $t->scrollYForKey(Table::KEY_PAGE_DOWN));
    }

    public function testScrollYForKeyPageDownAtMax(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(85)->withScrollY(85);
        // maxScrollY = 90, so pageDown should clamp to 90
        $this->assertSame(90, $t->scrollYForKey(Table::KEY_PAGE_DOWN));
    }

    public function testScrollYForKeyHome(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(50)->withScrollY(50);
        $this->assertSame(0, $t->scrollYForKey(Table::KEY_HOME));
    }

    public function testScrollYForKeyEnd(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(50)->withScrollY(50);
        // maxScrollY = 100 - 10 = 90
        $this->assertSame(90, $t->scrollYForKey(Table::KEY_END));
    }

    public function testScrollYForKeyUnknownKeyReturnsCurrent(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(25)->withScrollY(25);
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
        $a = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(5)->withScrollY(5);
        $b = $a->handleKey(Table::KEY_ARROW_UP);
        $this->assertNotSame($a, $b);
        $this->assertSame(5, $a->scrollY());
        $this->assertSame(4, $b->scrollY());
    }

    public function testHandleKeyArrowDown(): void
    {
        // Cursor on row 6 (window [5..14]) legally slides down to [6..15].
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(6)->withScrollY(5);
        $t2 = $t->handleKey(Table::KEY_ARROW_DOWN);
        $this->assertSame(6, $t2->scrollY());
    }

    public function testHandleKeyPageDown(): void
    {
        // ST-15 law: the window may never jump past the cursor — a page-down
        // that would strand the cursor on row 50 parks the window ON it instead.
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(50)->withScrollY(45);
        $t2 = $t->handleKey(Table::KEY_PAGE_DOWN);
        $this->assertSame(50, $t2->scrollY());
    }

    public function testHandleKeyHome(): void
    {
        // Home with the cursor on row 50 parks the window with the cursor at
        // its bottom edge (41..50) rather than stranding it.
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(50)->withScrollY(50);
        $t2 = $t->handleKey(Table::KEY_HOME);
        $this->assertSame(41, $t2->scrollY());
    }

    public function testHandleKeyEnd(): void
    {
        // UXD's zero-reverse probe (finding 015b): END with the cursor on row
        // 50 must NOT park the window at rows 90-99 with the highlight lost —
        // it clamps to the cursor.
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(50)->withScrollY(50);
        $t2 = $t->handleKey(Table::KEY_END);
        $this->assertSame(50, $t2->scrollY());

        // With a tail cursor END still reaches the true bottom.
        $u = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(99);
        $this->assertSame(90, $u->handleKey(Table::KEY_END)->scrollY());
    }

    public function testHandleKeyUnknownKeyReturnsUnchangedScrollY(): void
    {
        $t = $this->makeLargeTable()->withViewportHeight(10)->withSelectedIndex(25)->withScrollY(25);
        $t2 = $t->handleKey('unknownKey');
        $this->assertSame(25, $t2->scrollY());
    }

    public function testKeyboardScrollingIntegration(): void
    {
        // ST-15 coupled law: keys move the window toward the cursor; travel
        // moves the cursor (and the window follows). Down-arrow at the top of
        // a cursor-anchored window cannot leave the highlight behind.
        $t = $this->makeLargeTable()->withViewportHeight(10);

        $t = $t->handleKey(Table::KEY_ARROW_DOWN);
        $this->assertSame(0, $t->scrollY(), 'no stranding: cursor row 0 pins the window top');

        $t = $t->withSelectedIndex(50);
        $this->assertSame(41, $t->scrollY(), 'cursor travel scrolls the window to reveal it');

        $t = $t->handleKey(Table::KEY_PAGE_DOWN);
        $this->assertSame(50, $t->scrollY(), 'page-down parks on the cursor instead of jumping past it');

        $t = $t->withSelectedIndex(99);
        $this->assertSame(90, $t->scrollY());

        $t = $t->handleKey(Table::KEY_END);
        $this->assertSame(90, $t->scrollY(), 'tail cursor keeps END at the true bottom');

        $t = $t->handleKey(Table::KEY_HOME);
        $this->assertSame(90, $t->scrollY(), 'home cannot strand the tail cursor');
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
