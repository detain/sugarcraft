<?php

declare(strict_types=1);

namespace SugarCraft\Query\Tests;

use SugarCraft\Query\ResultPager;
use PHPUnit\Framework\TestCase;

final class ResultPagerTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function makeRows(int $n): array
    {
        $rows = [];
        for ($i = 1; $i <= $n; $i++) {
            $rows[] = ['id' => $i, 'name' => "row{$i}"];
        }
        return $rows;
    }

    public function testTotalRowsReturnsCorrectCount(): void
    {
        $pager = new ResultPager($this->makeRows(50));
        $this->assertSame(50, $pager->totalRows());
    }

    public function testTotalPagesCalculatesCorrectly(): void
    {
        $pager = new ResultPager($this->makeRows(100), pageSize: 25);
        $this->assertSame(4, $pager->totalPages());
    }

    public function testCurrentPageIsOneBased(): void
    {
        $pager = new ResultPager($this->makeRows(100), pageSize: 25);
        $this->assertSame(1, $pager->currentPage());
        $pager = $pager->nextPage();
        $this->assertSame(2, $pager->currentPage());
    }

    public function testPageReturnsCorrectSlice(): void
    {
        $rows = $this->makeRows(100);
        $pager = new ResultPager($rows, pageSize: 10, offset: 10);
        $page = $pager->page();
        $this->assertCount(10, $page);
        $this->assertSame(11, $page[0]['id']);
        $this->assertSame(20, $page[9]['id']);
    }

    public function testNextPageAdvancesOffset(): void
    {
        $pager = new ResultPager($this->makeRows(100), pageSize: 25, offset: 0);
        $next = $pager->nextPage();
        $this->assertSame(25, $next->offset);
        $this->assertSame(2, $next->currentPage());
    }

    public function testPrevPageDecreasesOffset(): void
    {
        $pager = new ResultPager($this->makeRows(100), pageSize: 25, offset: 25);
        $prev = $pager->prevPage();
        $this->assertSame(0, $prev->offset);
        $this->assertSame(1, $prev->currentPage());
    }

    public function testHasNextPageIsFalseAtEnd(): void
    {
        $pager = new ResultPager($this->makeRows(30), pageSize: 25, offset: 25);
        $this->assertFalse($pager->hasNextPage());
        $this->assertTrue($pager->hasPrevPage());
    }

    public function testHasPrevPageIsFalseAtStart(): void
    {
        $pager = new ResultPager($this->makeRows(30), pageSize: 25, offset: 0);
        $this->assertTrue($pager->hasNextPage());
        $this->assertFalse($pager->hasPrevPage());
    }

    public function testGoToPageNavigatesCorrectly(): void
    {
        $pager = new ResultPager($this->makeRows(100), pageSize: 25, offset: 0);
        $p3 = $pager->goToPage(3);
        $this->assertSame(50, $p3->offset);
        $this->assertSame(3, $p3->currentPage());
    }

    public function testGoToPageClampsToValidRange(): void
    {
        $pager = new ResultPager($this->makeRows(100), pageSize: 25, offset: 0);
        $clamped = $pager->goToPage(99);
        $this->assertSame(4, $clamped->currentPage());

        $clamped0 = $pager->goToPage(0);
        $this->assertSame(1, $clamped0->currentPage());
    }

    public function testWithPageSizeReturnsNewPagerWithNewSize(): void
    {
        $pager = new ResultPager($this->makeRows(100), pageSize: 25, offset: 50);
        $repage = $pager->withPageSize(10);
        $this->assertSame(10, $repage->pageSize);
        $this->assertSame(100, $repage->totalRows());
    }

    public function testConstructorThrowsOnInvalidPageSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ResultPager([], pageSize: 0);
    }

    public function testEmptyRowsReturnsZeroPages(): void
    {
        $pager = new ResultPager([]);
        $this->assertSame(0, $pager->totalPages());
        $this->assertSame(0, $pager->currentPage());
        $this->assertSame([], $pager->page());
    }

    public function testSinglePagePartialResult(): void
    {
        $pager = new ResultPager($this->makeRows(5), pageSize: 25, offset: 0);
        $this->assertSame(1, $pager->totalPages());
        $this->assertSame(5, $pager->totalRows());
        $this->assertCount(5, $pager->page());
    }
}
