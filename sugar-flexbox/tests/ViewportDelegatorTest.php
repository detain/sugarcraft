<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Scrollbar;
use SugarCraft\Flexbox\Viewport;

/**
 * Tests for Viewport delegator methods not covered in other test files.
 */
final class ViewportDelegatorTest extends TestCase
{
    // ---- Horizontal navigation delegators ---------------------------------

    public function testScrollLeftReturnsNewInstance(): void
    {
        // Create content that exceeds viewport width so horizontal scroll is possible
        $wideContent = str_repeat("Very long line content here\n", 10);
        $vp = Viewport::withContent($wideContent, 20, 10);
        $vp2 = $vp->scrollLeft(2);

        $this->assertNotSame($vp, $vp2);
    }

    public function testScrollRightReturnsNewInstance(): void
    {
        $wideContent = str_repeat("Very long line content here\n", 10);
        $vp = Viewport::withContent($wideContent, 20, 10);
        $vp2 = $vp->scrollRight(3);

        $this->assertNotSame($vp, $vp2);
    }

    public function testAtLeftmostReturnsTrueAtZeroOffset(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertTrue($vp->atLeftmost());
    }

    public function testAtRightmostReturnsTrueWhenScrolledToEnd(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 5)), 20, 10);
        // Content fits within viewport width, so at rightmost
        $this->assertTrue($vp->atRightmost());
    }

    public function testHorizontalScrollPercent(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $percent = $vp->horizontalScrollPercent();

        $this->assertIsFloat($percent);
        $this->assertGreaterThanOrEqual(0.0, $percent);
        $this->assertLessThanOrEqual(1.0, $percent);
    }

    public function testWithHorizontalStepReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withHorizontalStep(5);

        $this->assertNotSame($vp, $vp2);
    }

    // ---- Mouse wheel delegators -------------------------------------------

    public function testWithMouseWheelEnabledReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withMouseWheelEnabled(true);

        $this->assertNotSame($vp, $vp2);
    }

    public function testWithMouseWheelDeltaReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withMouseWheelDelta(5);

        $this->assertNotSame($vp, $vp2);
    }

    // ---- Scrollbar delegators ---------------------------------------------

    public function testWithSmoothScrollReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withSmoothScroll(true);

        $this->assertNotSame($vp, $vp2);
    }

    public function testWithScrollbarRunesReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $vp2 = $vp->withScrollbarRunes('█', '░');

        $this->assertNotSame($vp, $vp2);
    }

    /**
     * The unwrap contract that makes {@see Viewport::withVerticalScrollbar()}
     * type-checkable (docs-fix-1023: the signature used to silently demand the
     * widgets scrollbar despite naming the lib's own class).
     */
    public function testScrollbarWrapsAndUnwrapsTheCanonicalWidget(): void
    {
        $sb = \SugarCraft\Flexbox\Scrollbar::vertical();
        $this->assertInstanceOf(\SugarCraft\Widgets\Scrollbar\Scrollbar::class, $sb->widgetScrollbar());
        $this->assertSame($sb->widgetScrollbar(), $sb->widgetScrollbar());
    }

    // ---- Dimension delegators ---------------------------------------------

    public function testWithSizeReturnsNewInstance(): void
    {
        $vp = Viewport::withContent("hello\nworld", 20, 10);
        $vp2 = $vp->withSize(40, 20);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(40, $vp2->getWidth());
        $this->assertSame(20, $vp2->getHeight());
    }

    public function testSetWidthReturnsNewInstance(): void
    {
        $vp = Viewport::withContent("hello\nworld", 20, 10);
        $vp2 = $vp->setWidth(30);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(30, $vp2->getWidth());
    }

    public function testSetHeightReturnsNewInstance(): void
    {
        $vp = Viewport::withContent("hello\nworld", 20, 10);
        $vp2 = $vp->setHeight(15);

        $this->assertNotSame($vp, $vp2);
        $this->assertSame(15, $vp2->getHeight());
    }

    // ---- X offset delegators ----------------------------------------------

    public function testSetXOffsetReturnsNewInstance(): void
    {
        $wideContent = str_repeat("Very long line content here that exceeds width\n", 5);
        $vp = Viewport::withContent($wideContent, 20, 10);
        $vp2 = $vp->setXOffset(5);

        $this->assertNotSame($vp, $vp2);
    }

    public function testXOffsetDefaultsToZero(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertSame(0, $vp->xOffset());
    }
}
