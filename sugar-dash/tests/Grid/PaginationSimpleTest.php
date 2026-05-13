<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Grid;

use SugarCraft\Dash\Grid\Item;
use SugarCraft\Dash\Grid\PaginationSimple;
use SugarCraft\Dash\Grid\Sizer;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class PaginationSimpleTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Interface conformance
    // ═══════════════════════════════════════════════════════════════

    public function testPaginationSimpleImplementsSizer(): void
    {
        $pagination = PaginationSimple::new(1, 10);
        $this->assertInstanceOf(Sizer::class, $pagination);
    }

    public function testPaginationSimpleImplementsItem(): void
    {
        $pagination = PaginationSimple::new(1, 10);
        $this->assertInstanceOf(Item::class, $pagination);
    }

    // ═══════════════════════════════════════════════════════════════
    // Basic rendering
    // ═══════════════════════════════════════════════════════════════

    public function testRenderReturnsNonEmpty(): void
    {
        $pagination = PaginationSimple::new(1, 10);
        $rendered = $pagination->render();

        $this->assertNotSame('', $rendered);
    }

    public function testRenderContainsPageIndicator(): void
    {
        $pagination = PaginationSimple::new(3, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('3 / 10', $rendered);
    }

    public function testRenderContainsPrevButton(): void
    {
        $pagination = PaginationSimple::new(2, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('[‹]', $rendered);
    }

    public function testRenderContainsNextButton(): void
    {
        $pagination = PaginationSimple::new(1, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('[›]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // First page state
    // ═══════════════════════════════════════════════════════════════

    public function testFirstPagePrevButtonIsDisabled(): void
    {
        $pagination = PaginationSimple::new(1, 10);
        $rendered = $pagination->render();

        // Should show disabled prev button (different styling)
        $this->assertStringContainsString('[‹]', $rendered);
    }

    public function testFirstPageNextButtonIsEnabled(): void
    {
        $pagination = PaginationSimple::new(1, 10);
        $rendered = $pagination->render();

        // Should show enabled next button
        $this->assertStringContainsString('[›]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Last page state
    // ═══════════════════════════════════════════════════════════════

    public function testLastPageNextButtonIsDisabled(): void
    {
        $pagination = PaginationSimple::new(10, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('[›]', $rendered);
    }

    public function testLastPagePrevButtonIsEnabled(): void
    {
        $pagination = PaginationSimple::new(10, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('[‹]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Middle page state
    // ═══════════════════════════════════════════════════════════════

    public function testMiddlePageBothButtonsEnabled(): void
    {
        $pagination = PaginationSimple::new(5, 10);
        $rendered = $pagination->render();

        // Both buttons should be enabled
        $this->assertStringContainsString('[‹]', $rendered);
        $this->assertStringContainsString('[›]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Page bounds clamping
    // ═══════════════════════════════════════════════════════════════

    public function testZeroPageClampedToOne(): void
    {
        $pagination = PaginationSimple::new(0, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('1 / 10', $rendered);
    }

    public function testOversizedPageClampedToTotal(): void
    {
        $pagination = PaginationSimple::new(100, 10);
        $rendered = $pagination->render();

        $this->assertStringContainsString('10 / 10', $rendered);
    }

    public function testNegativeTotalClampedToOne(): void
    {
        $pagination = PaginationSimple::new(1, -5);
        $rendered = $pagination->render();

        $this->assertStringContainsString('1 / 1', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Size handling
    // ═══════════════════════════════════════════════════════════════

    public function testSetSizeReturnsNewInstance(): void
    {
        $original = PaginationSimple::new(1, 10);
        $resized = $original->setSize(30, 1);

        $this->assertNotSame($original, $resized);
    }

    public function testSetSizePadsOutput(): void
    {
        $pagination = PaginationSimple::new(1, 10)->setSize(50, 1);
        $rendered = $pagination->render();

        // Should be padded to 50 chars
        $this->assertGreaterThanOrEqual(50, strlen($rendered));
    }

    // ═══════════════════════════════════════════════════════════════
    // Color handling
    // ═══════════════════════════════════════════════════════════════

    public function testActiveColorAddsAnsiCodes(): void
    {
        $pagination = PaginationSimple::new(1, 10)->withActiveColor(Color::ansi(9));
        $rendered = $pagination->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    public function testInactiveColorAddsAnsiCodes(): void
    {
        $pagination = PaginationSimple::new(1, 10)->withInactiveColor(Color::ansi(8));
        $rendered = $pagination->render();

        $this->assertMatchesRegularExpression('/\x1b\[/', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Custom labels
    // ═══════════════════════════════════════════════════════════════

    public function testCustomPrevLabel(): void
    {
        $pagination = PaginationSimple::new(2, 10)->withPrevLabel('<');
        $rendered = $pagination->render();

        $this->assertStringContainsString('[<]', $rendered);
        $this->assertStringNotContainsString('[‹]', $rendered);
    }

    public function testCustomNextLabel(): void
    {
        $pagination = PaginationSimple::new(1, 10)->withNextLabel('>');
        $rendered = $pagination->render();

        $this->assertStringContainsString('[>]', $rendered);
        $this->assertStringNotContainsString('[›]', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Withers / fluent API
    // ═══════════════════════════════════════════════════════════════

    public function testWithCurrentPageReturnsNewInstance(): void
    {
        $original = PaginationSimple::new(1, 10);
        $updated = $original->withCurrentPage(5);

        $this->assertNotSame($original, $updated);
    }

    public function testWithCurrentPageAffectsOutput(): void
    {
        $original = PaginationSimple::new(1, 10);
        $updated = $original->withCurrentPage(7);

        $this->assertStringContainsString('7 / 10', $updated->render());
        $this->assertStringContainsString('1 / 10', $original->render());
    }

    public function testOriginalUnchangedAfterWithCurrentPage(): void
    {
        $original = PaginationSimple::new(1, 10);
        $original->withCurrentPage(9);

        $this->assertStringContainsString('1 / 10', $original->render());
    }

    public function testWithTotalPagesReturnsNewInstance(): void
    {
        $original = PaginationSimple::new(1, 10);
        $updated = $original->withTotalPages(20);

        $this->assertNotSame($original, $updated);
    }

    public function testWithTotalPagesAffectsOutput(): void
    {
        $original = PaginationSimple::new(1, 10);
        $updated = $original->withTotalPages(50);

        $this->assertStringContainsString('1 / 50', $updated->render());
    }

    public function testWithActiveColorReturnsNewInstance(): void
    {
        $original = PaginationSimple::new(1, 10);
        $updated = $original->withActiveColor(Color::ansi(12));

        $this->assertNotSame($original, $updated);
    }

    public function testWithInactiveColorReturnsNewInstance(): void
    {
        $original = PaginationSimple::new(1, 10);
        $updated = $original->withInactiveColor(Color::ansi(8));

        $this->assertNotSame($original, $updated);
    }

    // ═══════════════════════════════════════════════════════════════
    // Inner size calculation
    // ═══════════════════════════════════════════════════════════════

    public function testGetInnerSizeReturnsCorrectDimensions(): void
    {
        $pagination = PaginationSimple::new(5, 10);
        [$w, $h] = $pagination->getInnerSize();

        $this->assertGreaterThan(0, $w);
        $this->assertSame(1, $h);
    }

    public function testGetInnerSizeWithLargeTotalPages(): void
    {
        $pagination = PaginationSimple::new(1, 1000);
        [$w, $h] = $pagination->getInnerSize();

        // Should be wider for larger page numbers
        $this->assertGreaterThan(20, $w);
        $this->assertSame(1, $h);
    }

    // ═══════════════════════════════════════════════════════════════
    // Edge cases
    // ═══════════════════════════════════════════════════════════════

    public function testSinglePageShowsNoNavigation(): void
    {
        $pagination = PaginationSimple::new(1, 1);
        $rendered = $pagination->render();

        // Should show "1 / 1" with both buttons disabled
        $this->assertStringContainsString('1 / 1', $rendered);
    }

    public function testTwoPages(): void
    {
        $pagination = PaginationSimple::new(1, 2);
        $rendered = $pagination->render();

        $this->assertStringContainsString('1 / 2', $rendered);
        $this->assertStringContainsString('[‹]', $rendered);
        $this->assertStringContainsString('[›]', $rendered);
    }
}
