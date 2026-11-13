<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Paginator;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Widgets\Paginator\Paginator;
use SugarCraft\Widgets\Paginator\Type;

/**
 * Pins for the 2026-11 review fixes: windowed Dots geometry (C6),
 * page clamp on shrinking per-page (M3), the immutable totalPages
 * setter name (N6), boundary validation of the Arabic format (M21),
 * and the honest empty render (m25).
 */
final class PaginatorReviewPinsTest extends TestCase
{
    public function testEmptyListRendersNothing(): void
    {
        $this->assertSame('', Paginator::new()->view());
        $this->assertSame('', Paginator::new()->withType(Type::Arabic)->view());
    }

    public function testSmallCountsStayByteIdentical(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(30);
        $this->assertSame('● ○ ○', $p->view());
        $this->assertSame('○ ○ ●', $p->withPage(2)->view());

        $nine = Paginator::new()->withPerPage(1)->withTotalItems(9);
        $this->assertSame('● ○ ○ ○ ○ ○ ○ ○ ○', $nine->view());
    }

    public function testDotsRowIsWindowedBeyondTheCap(): void
    {
        $huge = Paginator::new()->withPerPage(10)->withTotalItems(100000); // 10 000 pages
        $view = $huge->view();
        // 11 first-slot glyphs (9 dots + 2 ellipses at most) — the old
        // renderer emitted 10 000 dots here.
        $this->assertLessThanOrEqual(11, substr_count($view, ' ') + 1);
        $this->assertStringContainsString('…', $view);
        $this->assertTrue($huge->onFirstPage());
        $this->assertStringStartsWith('●', $view);

        $last = $huge->withPage(9999)->view();
        $this->assertStringEndsWith('●', $last);

        $mid = $huge->withPage(5000);
        $midView = $mid->view();
        $this->assertStringContainsString('●', $midView);
        $this->assertStringStartsWith('○', $midView);
        $this->assertStringEndsWith('○', $midView);
        $this->assertLessThanOrEqual(40, Width::string($midView));
    }

    public function testPerPageShrinkKeepsPageSliceCoherent(): void
    {
        $p = Paginator::new()->withTotalItems(500)->withPerPage(10)->withPage(45);
        $this->assertSame([450, 460], $p->sliceBounds());

        $shrunk = $p->withPerPage(100); // 5 pages: stale 45 must clamp to 4
        $this->assertSame(4, $shrunk->page);
        $this->assertSame([400, 500], $shrunk->sliceBounds());
        $this->assertTrue($shrunk->onLastPage());
    }

    public function testWithTotalPagesIsTheImmutableNameAndAliasAgrees(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalPages(3);
        $this->assertSame(3, $p->totalPages());
        $this->assertSame(30, $p->totalItems);
        $this->assertEquals($p, Paginator::new()->withPerPage(10)->setTotalPages(3));

        $method = new \ReflectionMethod(Paginator::class, 'setTotalPages');
        $this->assertTrue(
            in_array('deprecated', array_map(
                static fn(\ReflectionAttribute $a): string => $a->getName(),
                $method->getAttributes(),
            ), true)
            || str_contains((string) $method->getDocComment(), '@deprecated'),
            'setTotalPages must be marked deprecated (N6)',
        );
    }

    public function testArabicFormatIsProbedAtTheBoundary(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(30)
            ->withType(Type::Arabic)->withArabicFormat('Page %d of %d');
        $this->assertSame('Page 1 of 3', $p->view());

        foreach (['%z', '%d/%d/%d', '%d%', '%s/%s'] as $bad) {
            try {
                $p->withArabicFormat($bad);
                $this->fail("format {$bad} must be refused");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('sprintf', $e->getMessage());
            }
        }
    }

    public function testArabicViewClampsDisplayedPage(): void
    {
        $p = Paginator::new()->withPerPage(10)->withTotalItems(25)
            ->withType(Type::Arabic)->withPage(2);
        $this->assertSame('3/3', $p->view());
    }
}
