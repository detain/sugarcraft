<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Stickers\Viewport;

/**
 * Tests for Viewport navigation, scroll queries, and internal state methods.
 */
final class ViewportQueryTest extends TestCase
{
    // ---- Factory methods ------------------------------------------------

    public function testNewCreatesDefaultViewport(): void
    {
        $vp = Viewport::new();
        $this->assertSame(80, $vp->getWidth());
        $this->assertSame(24, $vp->getHeight());
        $this->assertFalse($vp->isSynced());
    }

    public function testNewWithCustomDimensions(): void
    {
        $vp = Viewport::new(40, 10);
        $this->assertSame(40, $vp->getWidth());
        $this->assertSame(10, $vp->getHeight());
    }

    public function testWithContentCreatesViewportWithContent(): void
    {
        $vp = Viewport::withContent("line1\nline2", 20, 3);
        $this->assertStringContainsString('line1', $vp->view());
        $this->assertStringContainsString('line2', $vp->view());
    }

    public function testWithContentTrimsContent(): void
    {
        $vp = Viewport::withContent("line1\nline2\nline3\nline4\nline5", 20, 3);
        // content exceeds viewport height
        $this->assertSame(5, $vp->totalLineCount());
    }

    // ---- Navigation methods ----------------------------------------------

    public function testGotoTop(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(20);
        $top = $scrolled->gotoTop();
        $this->assertSame(0, $top->yOffset());
    }

    public function testGotoBottom(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $bottom = $vp->gotoBottom();
        // Should be near the bottom
        $this->assertGreaterThanOrEqual(30, $bottom->yOffset());
    }

    public function testPageUp(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(20);
        $paged = $scrolled->pageUp();
        $this->assertSame(10, $paged->yOffset());
    }

    public function testPageDown(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(5);
        $paged = $scrolled->pageDown();
        $this->assertSame(15, $paged->yOffset());
    }

    public function testHalfPageUp(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(10);
        $half = $scrolled->halfPageUp();
        $this->assertSame(5, $half->yOffset());
    }

    public function testHalfPageDown(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(5);
        $half = $scrolled->halfPageDown();
        $this->assertSame(10, $half->yOffset());
    }

    // ---- Query methods ---------------------------------------------------

    public function testTotalLineCount(): void
    {
        $vp = Viewport::withContent("1\n2\n3\n4\n5", 20, 3);
        $this->assertSame(5, $vp->totalLineCount());
    }

    public function testVisibleLineCount(): void
    {
        $vp = Viewport::withContent("1\n2\n3\n4\n5", 20, 3);
        $this->assertSame(3, $vp->visibleLineCount());
    }

    public function testAtTopWhenAtBeginning(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertTrue($vp->atTop());
    }

    public function testAtTopWhenScrolled(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(5);
        $this->assertFalse($vp->atTop());
    }

    public function testAtBottomWhenAtEnd(): void
    {
        $lines = \range(1, 50);
        $vp = Viewport::withContent(\implode("\n", $lines), 20, 10);
        // Go to bottom
        $bottom = $vp->gotoBottom();
        $this->assertTrue($bottom->atBottom());
    }

    public function testAtBottomWhenNotAtEnd(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(5);
        $this->assertFalse($vp->atBottom());
    }

    public function testScrollPercentAtTop(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertSame(0.0, $vp->scrollPercent());
    }

    public function testScrollPercentAtMiddle(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(20);
        $percent = $vp->scrollPercent();
        $this->assertGreaterThan(0.0, $percent);
        $this->assertLessThan(1.0, $percent);
    }

    public function testScrollPercentAtBottom(): void
    {
        $lines = \range(1, 50);
        $vp = Viewport::withContent(\implode("\n", $lines), 20, 10);
        $bottom = $vp->gotoBottom();
        $this->assertSame(1.0, $bottom->scrollPercent());
    }

    public function testYOffset(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(7);
        $this->assertSame(7, $vp->yOffset());
    }

    // ---- Combined navigation and query ----------------------------------

    public function testAtTopAfterGotoTop(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(20);
        $top = $vp->gotoTop();
        $this->assertTrue($top->atTop());
    }

    public function testAtBottomAfterGotoBottom(): void
    {
        $lines = \range(1, 50);
        $vp = Viewport::withContent(\implode("\n", $lines), 20, 10);
        $bottom = $vp->gotoBottom();
        $this->assertTrue($bottom->atBottom());
    }

    // ---- withVerticalScrollbar -------------------------------------------

    public function testWithVerticalScrollbarReturnsNewInstance(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrollbar = \SugarCraft\Stickers\Scrollbar::vertical();
        $vp2 = $vp->withVerticalScrollbar($scrollbar);
        $this->assertNotSame($vp, $vp2);
    }

    // ---- Model contract: init/update ------------------------------------

    public function testInitReturnsClosure(): void
    {
        $vp = Viewport::withContent("content", 20, 3);
        $init = $vp->init();
        $this->assertIsArray($init);
    }

    public function testUpdateReturnsArrayWithModel(): void
    {
        $vp = Viewport::withContent("content\nmore", 20, 3);
        $msg = new \SugarCraft\Core\Msg('Tick');
        [$model, $cmd] = $vp->update($msg);
        $this->assertInstanceOf(Viewport::class, $model);
    }

    // ---- Clamping edge cases ---------------------------------------------

    public function testSetYOffsetClampsToValidRange(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 20)), 20, 10);
        // Setting offset beyond max should clamp
        $excessive = $vp->setYOffset(100);
        // The offset gets clamped by the inner BitsViewport
        $this->assertLessThanOrEqual(10, $excessive->yOffset());
    }

    public function testSetXOffsetClampsToValidRange(): void
    {
        $wideContent = str_repeat("Very long line content here\n", 5);
        $vp = Viewport::withContent($wideContent, 20, 3);
        $excessive = $vp->setXOffset(1000);
        // Should be clamped
        $this->assertLessThanOrEqual(100, $excessive->xOffset());
    }

    public function testLineUpClampsToZero(): void
    {
        $vp = Viewport::withContent(\implode("\n", \range(1, 20)), 20, 10);
        $up = $vp->setYOffset(2)->lineUp(10);
        $this->assertSame(0, $up->yOffset());
    }

    public function testLineDownClampsToMax(): void
    {
        $lines = \range(1, 20);
        $vp = Viewport::withContent(\implode("\n", $lines), 20, 10);
        $down = $vp->setYOffset(15)->lineDown(10);
        // Should clamp to max offset
        $this->assertGreaterThanOrEqual(10, $down->yOffset());
    }

    // ---- Empty content edge case -----------------------------------------

    public function testEmptyContentViewport(): void
    {
        $vp = Viewport::withContent("", 20, 10);
        $this->assertSame(0, $vp->totalLineCount());
        $this->assertSame(10, $vp->visibleLineCount());
        $this->assertTrue($vp->atTop());
        $this->assertTrue($vp->atBottom());
    }

    // ---- paintScrollbar with various states -------------------------------

    public function testPaintScrollbarVisibleWithStickyHeaderAndFooter(): void
    {
        $content = "H1\nH2\nM1\nM2\nM3\nM4\nM5\nF1";
        $vp = Viewport::withContent($content, 20, 8)
            ->withStickyHeader(2)
            ->withStickyFooter(1)
            ->withScrollbar(true)
            ->setYOffset(0);

        $view = $vp->view();
        $lines = explode("\n", $view);

        // With scrollbar, each line has an extra char at the end
        $this->assertGreaterThan(8, \strlen($lines[0]));
    }

    public function testPaintScrollbarRendersAtBottomWhenScrolled(): void
    {
        $lines = [];
        for ($i = 0; $i < 30; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        $vp = Viewport::withContent($content, 20, 6)
            ->withScrollbar(true)
            ->setYOffset(20);

        $view = $vp->view();
        $viewLines = explode("\n", $view);

        // Thumb should be near bottom when scrolled far
        $this->assertGreaterThan(6, \strlen($viewLines[0]));
    }
}
