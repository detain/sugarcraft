<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Table;

use SugarCraft\Bits\Paginator\Paginator;
use SugarCraft\Bits\Table\Table;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    private function table(): Table
    {
        return Table::new(
            ['Name', 'Age'],
            [
                ['Alice', '30'],
                ['Bob', '25'],
                ['Carol', '40'],
                ['Dave', '35'],
                ['Eve', '28'],
                ['Frank', '45'],
                ['Grace', '32'],
                ['Henry', '27'],
            ],
            0,
            10,
        );
    }

    public function testPaginationDisabledByDefault(): void
    {
        $t = $this->table();
        $this->assertSame(0, $t->getPageSize());
        $this->assertSame(0, $t->getCurrentPage());
    }

    public function testWithPageSizeSetsPagination(): void
    {
        $t = $this->table()->withPageSize(3);
        $this->assertSame(3, $t->getPageSize());
        $this->assertNotSame($t, $t->withPageSize(3));
    }

    public function testNegativePageSizeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->table()->withPageSize(-1);
    }

    public function testZeroPageSizeDisablesPagination(): void
    {
        $t = $this->table()->withPageSize(3)->withPageSize(0);
        $this->assertSame(0, $t->getPageSize());
        // All rows visible when pagination disabled
        $view = $t->view();
        $this->assertStringContainsString('Alice', $view);
        $this->assertStringContainsString('Henry', $view);
    }

    public function testGetPaginatorReturnsCorrectState(): void
    {
        $t = $this->table()->withPageSize(3);
        $p = $t->getPaginator();
        $this->assertSame(3, $p->perPage);
        $this->assertSame(8, $p->totalItems);
        $this->assertSame(3, $p->totalPages());
    }

    public function testViewShowsOnlyCurrentPage(): void
    {
        // 8 rows, pageSize=3 → 3 pages
        $t = $this->table()->withPageSize(3);
        // Page 0: Alice, Bob, Carol
        $view0 = $t->view();
        $this->assertStringContainsString('Alice', $view0);
        $this->assertStringContainsString('Bob', $view0);
        $this->assertStringContainsString('Carol', $view0);
        $this->assertStringNotContainsString('Dave', $view0);
    }

    public function testWithPageShowsCorrectSlice(): void
    {
        $t = $this->table()->withPageSize(3);
        // Page 1: Dave, Eve, Frank
        $t1 = $t->withPage(1);
        $view1 = $t1->view();
        $this->assertStringContainsString('Dave', $view1);
        $this->assertStringContainsString('Eve', $view1);
        $this->assertStringContainsString('Frank', $view1);
        $this->assertStringNotContainsString('Alice', $view1);
    }

    public function testPageLastPartial(): void
    {
        // 8 rows, pageSize=3 → last page has 2 items
        $t = $this->table()->withPageSize(3)->withPage(2);
        $view2 = $t->view();
        $this->assertStringContainsString('Grace', $view2);
        $this->assertStringContainsString('Henry', $view2);
        $this->assertStringNotContainsString('Frank', $view2);
    }

    public function testPageClampedToLast(): void
    {
        $t = $this->table()->withPageSize(3)->withPage(99);
        $this->assertSame(2, $t->getCurrentPage());
    }

    public function testPageClampedToZero(): void
    {
        $t = $this->table()->withPageSize(3)->withPage(-5);
        $this->assertSame(0, $t->getCurrentPage());
    }

    public function testNextPageAdvances(): void
    {
        $t = $this->table()->withPageSize(3);
        $this->assertSame(0, $t->getCurrentPage());
        $t2 = $t->nextPage();
        $this->assertSame(1, $t2->getCurrentPage());
        $t3 = $t2->nextPage();
        $this->assertSame(2, $t3->getCurrentPage());
        // Can't go past last page
        $t4 = $t3->nextPage();
        $this->assertSame(2, $t4->getCurrentPage());
    }

    public function testPrevPageGoesBack(): void
    {
        $t = $this->table()->withPageSize(3)->withPage(2);
        $this->assertSame(2, $t->getCurrentPage());
        $t2 = $t->prevPage();
        $this->assertSame(1, $t2->getCurrentPage());
        $t3 = $t2->prevPage();
        $this->assertSame(0, $t3->getCurrentPage());
        // Can't go before first page
        $t4 = $t3->prevPage();
        $this->assertSame(0, $t4->getCurrentPage());
    }

    public function testPageFirstGoesToFirstPage(): void
    {
        $t = $this->table()->withPageSize(3)->withPage(2)->pageFirst();
        $this->assertSame(0, $t->getCurrentPage());
    }

    public function testPageLastGoesToLastPage(): void
    {
        $t = $this->table()->withPageSize(3)->pageLast();
        $this->assertSame(2, $t->getCurrentPage());
    }

    public function testPaginationNoOpWhenDisabled(): void
    {
        $t = $this->table(); // pagination disabled (pageSize=0)
        $t2 = $t->nextPage();
        $this->assertSame($t, $t2);
        $t3 = $t->prevPage();
        $this->assertSame($t, $t3);
    }

    public function testNextPrevPageChainsAreImmutable(): void
    {
        $t = $this->table()->withPageSize(3);
        $t2 = $t->nextPage();
        $this->assertNotSame($t, $t2);
        $this->assertSame(0, $t->getCurrentPage());
        $this->assertSame(1, $t2->getCurrentPage());
    }

    public function testPaginationChainsWithFilter(): void
    {
        // Filter reduces 8 rows to 5, pageSize=2 → 3 pages
        $t = $this->table()
            ->withFilterable(true)
            ->withFilter('e')  // matches Alice, Carol, Dave, Eve, Grace
            ->withPageSize(2);

        $p = $t->getPaginator();
        $this->assertSame(5, $p->totalItems);
        $this->assertSame(3, $p->totalPages());

        $view0 = $t->view();
        $this->assertStringContainsString('Alice', $view0);
        $this->assertStringNotContainsString('Bob', $view0);
    }

    public function testPaginationChainsWithSort(): void
    {
        $t = $this->table()
            ->withSort('Age')
            ->withPageSize(3);

        $p = $t->getPaginator();
        $this->assertSame(8, $p->totalItems);

        // Sort: Bob(25), Eve(28), Alice(30), Grace(32), Dave(35), Carol(40), Frank(45), Henry(27... wait no)
        // Actual sorted by age asc: Bob(25), Eve(28), Alice(30), Grace(32), Dave(35), Carol(40), Frank(45), Henry(27)... wait
        // Let me redo: names, ages: Alice(30), Bob(25), Carol(40), Dave(35), Eve(28), Frank(45), Grace(32), Henry(27)
        // Sorted by Age asc: Bob(25), Henry(27), Eve(28), Alice(30), Grace(32), Dave(35), Carol(40), Frank(45)
        $view0 = $t->view();
        // Page 0 should have Bob, Henry, Eve
        $this->assertStringContainsString('Bob', $view0);
        $this->assertStringContainsString('Henry', $view0);
        $this->assertStringContainsString('Eve', $view0);
    }

    public function testCursorStaysOnPageAfterNextPage(): void
    {
        $t = $this->table()->withPageSize(3);
        // Set cursor to row 2 (Carol, last item on page 0)
        $t = $t->setCursor(2);
        $t2 = $t->nextPage();
        // After nextPage, cursor should be clamped to page 1
        // Page 1 items: Dave(3), Eve(4), Frank(5) - indices 3,4,5
        // Cursor 2 is outside page 1, so it should be clamped to 3 (first of page)
        $this->assertLessThanOrEqual(5, $t2->cursor());
        $this->assertGreaterThanOrEqual(3, $t2->cursor());
    }

    public function testGetPaginatorReturnsFreshInstance(): void
    {
        $t = $this->table()->withPageSize(3)->withPage(1);
        $p1 = $t->getPaginator();
        $p2 = $t->getPaginator();
        $this->assertNotSame($p1, $p2);
        $this->assertSame(1, $p1->page);
        $this->assertSame(1, $p2->page);
    }

    public function testImmutabilityWithPageSize(): void
    {
        $t = $this->table();
        $t2 = $t->withPageSize(5);
        $this->assertNotSame($t, $t2);
        $this->assertSame(0, $t->getPageSize());
        $this->assertSame(5, $t2->getPageSize());
    }

    public function testViewOnEmptyTableWithPagination(): void
    {
        $t = Table::new(['Name'], [])->withPageSize(3);
        $view = $t->view();
        $this->assertStringContainsString('Name', $view);
        $this->assertStringNotContainsString("\n", $view);
    }

    public function testSliceBoundsFromPaginator(): void
    {
        $t = $this->table()->withPageSize(3);
        $p = $t->getPaginator();
        $this->assertSame([0, 3], $p->sliceBounds());

        $t2 = $t->withPage(1);
        $p2 = $t2->getPaginator();
        $this->assertSame([3, 6], $p2->sliceBounds());

        $t3 = $t->withPage(2);
        $p3 = $t3->getPaginator();
        $this->assertSame([6, 8], $p3->sliceBounds());
    }

    public function testNextPageDisabledOnSinglePage(): void
    {
        // Fewer rows than pageSize = single page
        $t = $this->table()->withPageSize(10);
        $this->assertSame(0, $t->getCurrentPage());
        $t2 = $t->nextPage();
        // Still on page 0 (can't advance past only page)
        $this->assertSame(0, $t2->getCurrentPage());
    }
}
