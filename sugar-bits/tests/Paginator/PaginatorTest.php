<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Paginator;

use SugarCraft\Bits\Paginator\Paginator;
use SugarCraft\Bits\Paginator\Type;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use PHPUnit\Framework\TestCase;

final class PaginatorTest extends TestCase
{
    public function testInitialState(): void
    {
        $p = Paginator::new();
        $this->assertSame(0, $p->page);
        $this->assertSame(10, $p->perPage);
        $this->assertSame(0, $p->totalItems);
        $this->assertSame(0, $p->totalPages());
        $this->assertTrue($p->onFirstPage());
        $this->assertTrue($p->onLastPage());
    }

    public function testTotalPagesRoundsUp(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(25);
        $this->assertSame(3, $p->totalPages());
    }

    public function testNextAndPrevPage(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(30);
        $this->assertSame(0, $p->page);
        $p = $p->nextPage();
        $this->assertSame(1, $p->page);
        $p = $p->nextPage();
        $this->assertSame(2, $p->page);
        $p = $p->nextPage(); // can't go past last
        $this->assertSame(2, $p->page);
        $this->assertTrue($p->onLastPage());
        $p = $p->prevPage();
        $this->assertSame(1, $p->page);
    }

    public function testSliceBounds(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(25);
        $this->assertSame([0, 10], $p->sliceBounds());
        $this->assertSame([10, 20], $p->nextPage()->sliceBounds());
        $this->assertSame([20, 25], $p->withPage(2)->sliceBounds()); // last page short
    }

    public function testSliceBoundsZeroItems(): void
    {
        $this->assertSame([0, 0], Paginator::new()->sliceBounds());
    }

    public function testDotsView(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(30); // 3 pages
        $this->assertSame('● ○ ○', $p->view());
        $this->assertSame('○ ● ○', $p->nextPage()->view());
        $this->assertSame('○ ○ ●', $p->withPage(2)->view());
    }

    public function testArabicView(): void
    {
        $p = Paginator::new()
            ->withPerPage(10)
            ->withTotalItems(40)
            ->withType(Type::Arabic);
        $this->assertSame('1/4', $p->view());
        $this->assertSame('2/4', $p->nextPage()->view());
    }

    public function testCustomDots(): void
    {
        $p = Paginator::new()
            ->withPerPage(10)
            ->withTotalItems(20)
            ->withDots('*', '-');
        $this->assertSame('* -', $p->view());
    }

    public function testKeyRightAdvances(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(20);
        [$next, ] = $p->update(new KeyMsg(KeyType::Right));
        $this->assertSame(1, $next->page);
    }

    public function testKeyHViaVim(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(20)->withPage(1);
        [$next, ] = $p->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame(0, $next->page);
    }

    public function testTotalItemsDecreaseClampsPage(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(50)->withPage(4);
        $p = $p->withTotalItems(15); // now only 2 pages
        $this->assertSame(1, $p->page);
    }

    public function testItemsOnPageStandardPage(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(25);
        $this->assertSame(10, $p->itemsOnPage());
    }

    public function testItemsOnPageLastPagePartial(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(25)->withPage(2);
        $this->assertSame(5, $p->itemsOnPage());
    }

    public function testItemsOnPageEmpty(): void
    {
        $p = Paginator::new();
        $this->assertSame(0, $p->itemsOnPage());
    }

    public function testSetTotalPagesPinsTotal(): void
    {
        $p = Paginator::new()->withPerPage(10)->setTotalPages(7);
        $this->assertSame(7, $p->totalPages());
    }

    public function testWithArabicFormatCustom(): void
    {
        $p = Paginator::new()
            ->withPerPage(10)
            ->withTotalItems(30)
            ->withType(Type::Arabic)
            ->withArabicFormat('Page %d of %d');
        $this->assertSame('Page 1 of 3', $p->view());
    }

    public function testArabicFormatDefault(): void
    {
        $p = Paginator::new()
            ->withPerPage(10)
            ->withTotalItems(30)
            ->withType(Type::Arabic);
        $this->assertSame('1/3', $p->view());
    }

    public function testFirstPageHelper(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(30)->withPage(2);
        $p2 = $p->firstPage();
        $this->assertSame(0, $p2->page);
        // Original unaffected.
        $this->assertSame(2, $p->page);
    }

    public function testLastPageHelper(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(30);
        $p2 = $p->lastPage();
        $this->assertSame(2, $p2->page); // 30 items / 10 per page = 3 pages (0,1,2)
    }

    public function testKeyHomeGoesToFirstPage(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(30)->withPage(2);
        [$next, ] = $p->update(new KeyMsg(KeyType::Home));
        $this->assertSame(0, $next->page);
    }

    public function testKeyEndGoesToLastPage(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(30);
        [$next, ] = $p->update(new KeyMsg(KeyType::End));
        $this->assertSame(2, $next->page);
    }
}
