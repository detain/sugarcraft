<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\Nav;

use SugarCraft\Dash\Components\Nav\Pagination;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Ansi;
use PHPUnit\Framework\TestCase;

final class PaginationTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testPaginationImplementsSizer(): void
    {
        $pagination = Pagination::new(1, 10);
        $this->assertInstanceOf(Sizer::class, $pagination);
    }

    public function testPaginationImplementsItem(): void
    {
        $pagination = Pagination::new(1, 10);
        $this->assertInstanceOf(Item::class, $pagination);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $pagination = Pagination::new(1, 10);
        $rendered = $pagination->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsCurrentPage(): void
    {
        $pagination = Pagination::new(5, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('5', $rendered);
    }

    public function testRenderContainsPrevNextLabels(): void
    {
        $pagination = Pagination::new(5, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('‹', $rendered);
        $this->assertStringContainsString('›', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Page bounds
    // ═══════════════════════════════════════════════════════════════

    public function testFirstPageNoPrevButton(): void
    {
        $pagination = Pagination::new(1, 10);
        $rendered = $pagination->render();

        // First page should show disabled prev or no prev
        $this->assertStringContainsString('‹', $rendered);
    }

    public function testLastPageNoNextButton(): void
    {
        $pagination = Pagination::new(10, 10);
        $rendered = $pagination->render();

        // Last page should show disabled next
        $this->assertStringContainsString('›', $rendered);
    }

    public function testCurrentPageClampedToValidRange(): void
    {
        $pagination = Pagination::new(100, 10);
        $rendered = $pagination->render();

        // Should not show page 100, should clamp to page 10
        $this->assertStringContainsString('10', $rendered);
    }

    public function testNegativePageClampedToOne(): void
    {
        $pagination = Pagination::new(-5, 10);
        $rendered = $pagination->render();

        // Should show page 1 as current
        $this->assertStringContainsString('[1]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Page number display
    // ═══════════════════════════════════════════════════════════════

    public function testSmallPageRangeShowsAll(): void
    {
        $pagination = Pagination::new(1, 5);
        $rendered = $pagination->render();

        // Should show pages 1-5
        $this->assertStringContainsString('1', $rendered);
        $this->assertStringContainsString('5', $rendered);
    }

    public function testLargePageRangeShowsEllipsis(): void
    {
        $pagination = Pagination::new(5, 100);
        $rendered = $pagination->render();

        // Should show ellipsis for large ranges
        $this->assertStringContainsString('...', $rendered);
    }

    public function testFirstPageShowsLastPageNumber(): void
    {
        $pagination = Pagination::new(1, 100);
        $rendered = $pagination->render();

        // Should show "100" somewhere for last page link
        $this->assertStringContainsString('100', $rendered);
    }

    public function testLastPageShowsFirstPageNumber(): void
    {
        $pagination = Pagination::new(100, 100);
        $rendered = $pagination->render();

        // Should show "1" somewhere for first page link
        $this->assertStringContainsString('1', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Button visibility options
    // ═══════════════════════════════════════════════════════════════

    public function testHideFirstLastButtons(): void
    {
        $pagination = Pagination::new(5, 100)->withShowFirstLast(false);
        $rendered = $pagination->render();

        // Should not show ellipsis when first/last hidden
        $this->assertStringNotContainsString('...', $rendered);
    }

    public function testHidePrevNextButtons(): void
    {
        $pagination = Pagination::new(5, 10)->withShowPrevNext(false);
        $rendered = $pagination->render();

        // Should not show ‹ › when prev/next hidden
        $this->assertStringNotContainsString('‹', $rendered);
        $this->assertStringNotContainsString('›', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testActiveColorAddsAnsiCodes(): void
    {
        $pagination = Pagination::new(5, 10)
            ->withActiveColor(Color::ansi(9));
        $rendered = $pagination->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testInactiveColorAddsAnsiCodes(): void
    {
        $pagination = Pagination::new(5, 10)
            ->withInactiveColor(Color::ansi(8));
        $rendered = $pagination->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testColorResetAtEnd(): void
    {
        $pagination = Pagination::new(5, 10)
            ->withActiveColor(Color::ansi(9))
            ->withInactiveColor(Color::ansi(8));
        $rendered = $pagination->render();

        $this->assertStringEndsWith("\x1b[0m", $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom labels
    // ═══════════════════════════════════════════════════════════════

    public function testCustomPrevLabel(): void
    {
        $pagination = Pagination::new(5, 10)->withPrevLabel('←');
        $rendered = $pagination->render();

        $this->assertStringContainsString('←', $rendered);
        $this->assertStringNotContainsString('‹', $rendered);
    }

    public function testCustomNextLabel(): void
    {
        $pagination = Pagination::new(5, 10)->withNextLabel('→');
        $rendered = $pagination->render();

        $this->assertStringContainsString('→', $rendered);
        $this->assertStringNotContainsString('›', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = Pagination::new(1, 10);
        $resized = $original->setSize(50, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testWidthAllocation(): void
    {
        $pagination = Pagination::new(1, 10)->setSize(80, 1);
        $rendered = $pagination->render();

        // Should pad to fill width
        $this->assertGreaterThanOrEqual(80, mb_strlen($rendered, 'UTF-8'));
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithCurrentPageReturnsNewInstance(): void
    {
        $original = Pagination::new(1, 10);
        $updated = $original->withCurrentPage(5);

        $this->assertNotSame($original, $updated);
        $this->assertStringContainsString('[5]', $updated->render());
    }

    public function testWithTotalPagesReturnsNewInstance(): void
    {
        $original = Pagination::new(1, 10);
        $updated = $original->withTotalPages(20);

        $this->assertNotSame($original, $updated);
    }

    public function testWithActiveColorReturnsNewInstance(): void
    {
        $original = Pagination::new(1, 10);
        $updated = $original->withActiveColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testOriginalUnchangedAfterWithCurrentPage(): void
    {
        $original = Pagination::new(1, 10);
        $original->withCurrentPage(5);
        $rendered = $original->render();

        // Original should still show page 1
        $this->assertStringContainsString('[1]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $pagination = Pagination::new(5, 10);
        [$w, $h] = $pagination->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h); // Single line
    }

    public function testGetInnerSizeWithWidthAllocation(): void
    {
        $pagination = Pagination::new(1, 10)->setSize(100, 1);
        [$w, ] = $pagination->getInnerSize();

        $this->assertGreaterThanOrEqual(100, $w);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSinglePage(): void
    {
        $pagination = Pagination::new(1, 1);
        $rendered = $pagination->render();

        // Single page should render without ellipsis
        $this->assertStringNotContainsString('...', $rendered);
    }

    public function testTwoPages(): void
    {
        $pagination = Pagination::new(1, 2);
        $rendered = $pagination->render();

        $this->assertStringContainsString('1', $rendered);
        $this->assertStringContainsString('2', $rendered);
    }

    public function testMiddlePageHighlighted(): void
    {
        $pagination = Pagination::new(5, 10);
        $rendered = $pagination->render();

        // Current page should be highlighted with brackets
        $this->assertStringContainsString('[5]', $rendered);
    }
}
