<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Timer\TickMsg;
use SugarCraft\Flexbox\Viewport;

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
        $this->assertFalse($vp->hasLinkedViewport());
    }

    public function testNewWithCustomDimensions(): void
    {
        $vp = Viewport::new(40, 10);
        $this->assertSame(40, $vp->getWidth());
        $this->assertSame(10, $vp->getHeight());
    }

    public function testWithContentCreatesViewportWithContent(): void
    {
        $vp = Viewport::fromContent("line1\nline2", 20, 3);
        $this->assertStringContainsString('line1', $vp->view());
        $this->assertStringContainsString('line2', $vp->view());
    }

    public function testWithContentTrimsContent(): void
    {
        $vp = Viewport::fromContent("line1\nline2\nline3\nline4\nline5", 20, 3);
        // content exceeds viewport height
        $this->assertSame(5, $vp->totalLineCount());
    }

    // ---- Navigation methods ----------------------------------------------

    public function testGotoTop(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(20);
        $top = $scrolled->gotoTop();
        $this->assertSame(0, $top->yOffset());
    }

    public function testGotoBottom(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $bottom = $vp->gotoBottom();
        // Should be near the bottom
        $this->assertGreaterThanOrEqual(30, $bottom->yOffset());
    }

    public function testPageUp(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(20);
        $paged = $scrolled->pageUp();
        $this->assertSame(10, $paged->yOffset());
    }

    public function testPageDown(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(5);
        $paged = $scrolled->pageDown();
        $this->assertSame(15, $paged->yOffset());
    }

    public function testHalfPageUp(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(10);
        $half = $scrolled->halfPageUp();
        $this->assertSame(5, $half->yOffset());
    }

    public function testHalfPageDown(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $scrolled = $vp->setYOffset(5);
        $half = $scrolled->halfPageDown();
        $this->assertSame(10, $half->yOffset());
    }

    // ---- Query methods ---------------------------------------------------

    public function testTotalLineCount(): void
    {
        $vp = Viewport::fromContent("1\n2\n3\n4\n5", 20, 3);
        $this->assertSame(5, $vp->totalLineCount());
    }

    public function testVisibleLineCount(): void
    {
        $vp = Viewport::fromContent("1\n2\n3\n4\n5", 20, 3);
        $this->assertSame(3, $vp->visibleLineCount());
    }

    public function testAtTopWhenAtBeginning(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertTrue($vp->atTop());
    }

    public function testAtTopWhenScrolled(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(5);
        $this->assertFalse($vp->atTop());
    }

    public function testAtBottomWhenAtEnd(): void
    {
        $lines = \range(1, 50);
        $vp = Viewport::fromContent(\implode("\n", $lines), 20, 10);
        // Go to bottom
        $bottom = $vp->gotoBottom();
        $this->assertTrue($bottom->atBottom());
    }

    public function testAtBottomWhenNotAtEnd(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(5);
        $this->assertFalse($vp->atBottom());
    }

    public function testScrollPercentAtTop(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        $this->assertSame(0.0, $vp->scrollPercent());
    }

    public function testScrollPercentAtMiddle(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(20);
        $percent = $vp->scrollPercent();
        $this->assertGreaterThan(0.0, $percent);
        $this->assertLessThan(1.0, $percent);
    }

    public function testScrollPercentAtBottom(): void
    {
        $lines = \range(1, 50);
        $vp = Viewport::fromContent(\implode("\n", $lines), 20, 10);
        $bottom = $vp->gotoBottom();
        $this->assertSame(1.0, $bottom->scrollPercent());
    }

    public function testYOffset(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(7);
        $this->assertSame(7, $vp->yOffset());
    }

    // ---- Combined navigation and query ----------------------------------

    public function testAtTopAfterGotoTop(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10)->setYOffset(20);
        $top = $vp->gotoTop();
        $this->assertTrue($top->atTop());
    }

    public function testAtBottomAfterGotoBottom(): void
    {
        $lines = \range(1, 50);
        $vp = Viewport::fromContent(\implode("\n", $lines), 20, 10);
        $bottom = $vp->gotoBottom();
        $this->assertTrue($bottom->atBottom());
    }

    // ---- withVerticalScrollbar -------------------------------------------

    public function testWithVerticalScrollbarReturnsNewInstance(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 10);
        // The parameter is THIS lib's Scrollbar (historically: the shadowed
        // widgets import made the signature silently accept only the foreign
        // type). Unwraps to the widgets scrollbar the inner viewport stores.
        $scrollbar = \SugarCraft\Flexbox\Scrollbar::vertical();
        $vp2 = $vp->withVerticalScrollbar($scrollbar);
        $this->assertNotSame($vp, $vp2);
        // The scrollbar actually lands: content + one gutter column, height
        // rows exactly (M8: rows must never exceed the requested width).
        $rows = \explode("\n", $vp2->view());
        $this->assertCount(10, $rows);
        foreach ($rows as $row) {
            $this->assertLessThanOrEqual(20, \SugarCraft\Core\Util\Width::string($row));
        }
    }

    /**
     * Type polarity: the shadowed widgets import made the
     * signature accept the FOREIGN scrollbar and reject the lib's own. The
     * trap is now inverted — passing the widgets type directly is a TypeError;
     * wrap it in SugarCraft\Flexbox\Scrollbar instead.
     */
    public function testWithVerticalScrollbarRefusesTheForeignScrollbarType(): void
    {
        $vp = Viewport::fromContent("a\nb\nc", 20, 3);
        $this->expectException(\TypeError::class);
        $vp->withVerticalScrollbar(\SugarCraft\Widgets\Scrollbar\Scrollbar::vertical());
    }

    // ---- Model contract: init/update ------------------------------------

    public function testInitDelegatesToInner(): void
    {
        $vp = Viewport::fromContent("content", 20, 3);
        // init() returns whatever the inner BitsViewport returns
        $init = $vp->init();
        // May be null or a Closure depending on BitsViewport state
        $this->assertTrue($init === null || \is_callable($init));
    }

    public function testUpdateReturnsArrayWithModel(): void
    {
        $vp = Viewport::fromContent("content\nmore", 20, 3);
        $msg = new TickMsg(0);
        [$model, $cmd] = $vp->update($msg);
        $this->assertInstanceOf(Viewport::class, $model);
    }

    // ---- Clamping edge cases ---------------------------------------------

    public function testSetYOffsetClampsToValidRange(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 20)), 20, 10);
        // Setting offset beyond max should clamp
        $excessive = $vp->setYOffset(100);
        // The offset gets clamped by the inner BitsViewport
        $this->assertLessThanOrEqual(10, $excessive->yOffset());
    }

    public function testSetXOffsetClampsToValidRange(): void
    {
        $wideContent = str_repeat("Very long line content here\n", 5);
        $vp = Viewport::fromContent($wideContent, 20, 3);
        $excessive = $vp->setXOffset(1000);
        // Should be clamped
        $this->assertLessThanOrEqual(100, $excessive->xOffset());
    }

    public function testLineUpClampsToZero(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 20)), 20, 10);
        $up = $vp->setYOffset(2)->lineUp(10);
        $this->assertSame(0, $up->yOffset());
    }

    public function testLineDownClampsToMax(): void
    {
        $lines = \range(1, 20);
        $vp = Viewport::fromContent(\implode("\n", $lines), 20, 10);
        $down = $vp->setYOffset(15)->lineDown(10);
        // Should clamp to max offset
        $this->assertGreaterThanOrEqual(10, $down->yOffset());
    }

    // ---- Empty content edge case -----------------------------------------

    public function testEmptyContentViewport(): void
    {
        $vp = Viewport::fromContent("", 20, 10);
        // Empty string split by \n gives [""], count = 1
        $this->assertSame(1, $vp->totalLineCount());
        // visibleLineCount = min(total, height) = min(1, 10) = 1
        $this->assertSame(1, $vp->visibleLineCount());
        $this->assertTrue($vp->atTop());
        $this->assertTrue($vp->atBottom());
    }

    // ---- paintScrollbar with various states -------------------------------

    public function testPaintScrollbarWithStickyFrameIsBoxyWithABarColumn(): void
    {
        $content = "H1\nH2\nM1\nM2\nM3\nM4\nM5\nF1";
        $vp = Viewport::fromContent($content, 20, 8)
            ->withStickyHeader(2)
            ->withStickyFooter(1)
            ->showScrollbar(true)
            ->setYOffset(0);

        $lines = explode("\n", $vp->view());

        // The old proxy was strlen > 8 — true for ANY nonempty row. The
        // real law: every row is exactly the box width (19 body cells +
        // one bar cell), the bar column only ever holds configured runes,
        // and the thumb appears on the row the offset maps to.
        $this->assertCount(8, $lines, 'sticky header 2 + middle 5 + footer 1');
        foreach ($lines as $i => $line) {
            $this->assertSame(20, \SugarCraft\Core\Util\Width::string($line), "sticky row $i");
        }
        // Proportional law: this document fits entirely (maxOffset 0), so
        // the thumb spans the WHOLE column and no track cell appears —
        // travel is pinned by testThumbRowIsMonotonicInYOffset below.
        $bar = array_map(
            static fn (string $l): string => mb_substr($l, -1, 1, 'UTF-8'),
            $lines,
        );
        $this->assertSame(array_fill(0, 8, '█'), $bar);
    }

    public function testThumbRowIsMonotonicInYOffset(): void
    {
        $lines = [];
        for ($i = 0; $i < 30; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        $starts = [];
        foreach ([0, 4, 8, 12, 16, 20, 24] as $y) {
            $vp = Viewport::fromContent($content, 20, 6)
                ->showScrollbar(true)
                ->setYOffset($y);
            $rows = explode("\n", $vp->view());
            foreach ($rows as $i => $line) {
                $this->assertSame(20, \SugarCraft\Core\Util\Width::string($line), "row $i at y=$y");
            }
            $thumb = array_keys(array_map(
                static fn (string $l): string => mb_substr($l, -1, 1, 'UTF-8'),
                $rows,
            ), '█', true);
            $this->assertNotEmpty($thumb, "a thumb cell must exist at y=$y");
            $starts[] = $thumb[0];
        }

        // Monotonic: deeper offsets never drag the thumb upward, and the
        // exact rows are deterministic (thumb height 1 of 6 travel 5 over
        // maxOffset 24: round(5 * y / 24)).
        $this->assertSame([0, 1, 2, 3, 3, 4, 5], $starts);
    }
}
