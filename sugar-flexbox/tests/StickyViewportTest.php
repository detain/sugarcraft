<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Flexbox\Scrollbar;
use SugarCraft\Flexbox\Viewport;

final class StickyViewportTest extends TestCase
{

    // ---- withStickyHeader coercion ------------------------------------

    public function testWithStickyHeaderRejectsNegative(): void
    {
        // The name always promised this; FBX-30 delivers it. A negative
        // sticky count is an argument bug, not geometry — it REFUSES by
        // name and value instead of quietly clamping to 0. Zero still
        // means 'no sticky region' (testWithStickyHeaderZeroIsNoOp).
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Viewport::withStickyHeader() needs a row count >= 0; got -5');
        Viewport::fromContent("one\ntwo\nthree", 20, 4)->withStickyHeader(-5);
    }

    public function testWithStickyHeaderZeroIsNoOp(): void
    {
        $vp = Viewport::fromContent("line1\nline2\nline3", 20, 3)->withStickyHeader(0);
        $this->assertStringContainsString('line1', $vp->view());
    }

    // ---- withStickyFooter coercion --------------------------------------

    public function testWithStickyFooterRejectsNegative(): void
    {
        // FBX-30: same refusal law on the footer door.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Viewport::withStickyFooter() needs a row count >= 0; got -5');
        Viewport::fromContent("one\ntwo\nthree", 20, 4)->withStickyFooter(-5);
    }

    public function testWithStickyFooterZeroIsNoOp(): void
    {
        $vp = Viewport::fromContent("line1\nline2\nline3", 20, 3)->withStickyFooter(0);
        $this->assertStringContainsString('line3', $vp->view());
    }

    // ---- Sticky header rendering ---------------------------------------

    public function testStickyHeaderAlwaysShowsFirstNLines(): void
    {
        // Use content that exceeds viewport height so scrolling is possible.
        $content = "header1\nheader2\nscrollable1\nscrollable2\nscrollable3\nfooter1";
        // stickyHeader=2, stickyFooter=1, viewport height=6, content has 6 lines
        $vp = Viewport::fromContent($content, 20, 6)
            ->withStickyHeader(2)
            ->withStickyFooter(1)
            ->setYOffset(0);

        $view = $vp->view();
        $lines = explode("\n", $view);

        // First 2 lines are the sticky header, always visible.
        $this->assertSame('header1', trim($lines[0]));
        $this->assertSame('header2', trim($lines[1]));
    }

    public function testStickyHeaderVisibleRegardlessOfScrollOffset(): void
    {
        // Use 50 lines so there's plenty to scroll through.
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        // stickyHeader=2, stickyFooter=1, viewport height=6
        $vp = Viewport::fromContent($content, 20, 50)
            ->withStickyHeader(2)
            ->withStickyFooter(1);

        // Scroll well past the header content — header should still be at top.
        $scrolled = $vp->setYOffset(20);
        $view = $scrolled->view();
        $resultLines = explode("\n", $view);

        $this->assertSame('line0', trim($resultLines[0]));
        $this->assertSame('line1', trim($resultLines[1]));
    }

    // ---- Sticky footer rendering --------------------------------------

    public function testStickyFooterAlwaysShowsLastMLines(): void
    {
        $content = "header1\nmiddle1\nmiddle2\nmiddle3\nmiddle4\nfooter1";
        // stickyHeader=1, stickyFooter=1, viewport height=6, content has 6 lines
        $vp = Viewport::fromContent($content, 20, 6)
            ->withStickyHeader(1)
            ->withStickyFooter(1)
            ->setYOffset(0);

        $view = $vp->view();
        $lines = explode("\n", $view);
        $lastLine = trim($lines[count($lines) - 1]);

        $this->assertSame('footer1', $lastLine);
    }

    public function testStickyFooterVisibleRegardlessOfScrollOffset(): void
    {
        // 50 lines so there's plenty to scroll through.
        $lines = [];
        for ($i = 0; $i < 50; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        $vp = Viewport::fromContent($content, 20, 50)
            ->withStickyHeader(1)
            ->withStickyFooter(1);

        // Scroll way down — footer should still be at the bottom.
        $scrolled = $vp->setYOffset(40);
        $view = $scrolled->view();
        $resultLines = explode("\n", $view);
        $lastLine = trim($resultLines[count($resultLines) - 1]);

        $this->assertSame('line49', $lastLine);
    }

    // ---- Combined sticky header + footer ------------------------------

    public function testStickyHeaderAndFooterBothRendered(): void
    {
        $content = "H1\nH2\nscroll1\nscroll2\nscroll3\nF1";
        $vp = Viewport::fromContent($content, 20, 6)
            ->withStickyHeader(2)
            ->withStickyFooter(1)
            ->setYOffset(0);

        $view = $vp->view();
        $lines = explode("\n", $view);

        // Top: sticky header.
        $this->assertSame('H1', trim($lines[0]));
        $this->assertSame('H2', trim($lines[1]));
        // Bottom: sticky footer.
        $this->assertSame('F1', trim($lines[count($lines) - 1]));
    }

    public function testStickyHeaderAndFooterWithScrollOffset(): void
    {
        $content = "H1\nH2\nM1\nM2\nM3\nF1";
        $vp = Viewport::fromContent($content, 20, 6)
            ->withStickyHeader(2)
            ->withStickyFooter(1);

        $scrolled = $vp->setYOffset(1);
        $view = $scrolled->view();
        $lines = explode("\n", $view);

        // Header stays at top; footer stays at bottom.
        $this->assertSame('H1', trim($lines[0]));
        $this->assertSame('F1', trim($lines[count($lines) - 1]));
    }

    // ---- Fall-through when no sticky regions --------------------------

    public function testViewFallsThroughToInnerWhenNoStickyRegions(): void
    {
        $vp = Viewport::fromContent("line1\nline2\nline3", 20, 3)
            ->withStickyHeader(0)
            ->withStickyFooter(0);

        $this->assertStringContainsString('line1', $vp->view());
        $this->assertStringContainsString('line3', $vp->view());
    }

    // ---- Immutability -------------------------------------------------

    public function testStickyHeaderReturnsNewInstance(): void
    {
        $a = Viewport::fromContent("line1\nline2", 20, 3);
        $b = $a->withStickyHeader(1);
        $this->assertNotSame($a, $b);
    }

    public function testStickyFooterReturnsNewInstance(): void
    {
        $a = Viewport::fromContent("line1\nline2", 20, 3);
        $b = $a->withStickyFooter(1);
        $this->assertNotSame($a, $b);
    }

    // ---- Navigation preserves sticky state ----------------------------

    public function testLineUpPreservesStickyHeader(): void
    {
        $lines = [];
        for ($i = 0; $i < 20; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        $vp = Viewport::fromContent($content, 20, 10)
            ->withStickyHeader(2)
            ->withStickyFooter(1);

        $scrolled = $vp->setYOffset(5)->lineUp(1);
        $view = $scrolled->view();
        $resultLines = explode("\n", $view);

        $this->assertSame('line0', trim($resultLines[0]));
        $this->assertSame('line1', trim($resultLines[1]));
    }

    public function testLineDownPreservesStickyFooter(): void
    {
        $lines = [];
        for ($i = 0; $i < 20; $i++) {
            $lines[] = "line{$i}";
        }
        $content = implode("\n", $lines);

        $vp = Viewport::fromContent($content, 20, 10)
            ->withStickyHeader(1)
            ->withStickyFooter(1);

        $scrolled = $vp->setYOffset(0)->lineDown(5);
        $view = $scrolled->view();
        $resultLines = explode("\n", $view);

        $this->assertSame('line19', trim($resultLines[count($resultLines) - 1]));
    }

    // ---- Clamping when sticky regions exceed viewport height ----------

    public function testStickyHeaderClampedToViewportHeight(): void
    {
        // stickyHeader=5, stickyFooter=1, height=6 → header clamped to 5 (6-1=5)
        $vp = Viewport::fromContent("1\n2\n3\n4\n5\n6", 20, 6)
            ->withStickyHeader(5)
            ->withStickyFooter(1);

        $view = $vp->view();
        $lines = explode("\n", $view);
        $this->assertCount(6, $lines);
    }

    public function testStickyFooterClampedToViewportHeight(): void
    {
        // stickyHeader=1, stickyFooter=5, height=6 → footer clamped to 5 (6-1=5)
        $vp = Viewport::fromContent("1\n2\n3\n4\n5\n6", 20, 6)
            ->withStickyHeader(1)
            ->withStickyFooter(5);

        $view = $vp->view();
        $lines = explode("\n", $view);
        $this->assertCount(6, $lines);
    }

    /**
     * Sticky + scrollbar geometry: the content was padded
     * to the FULL width and paintScrollbar then appended its column — every
     * sticky row overflowed by one cell (21 wide at a request of 20), pushing
     * the terminal's last column into wrap. The scrollbar column must be
     * reserved BEFORE padding.
     */
    public function testStickyWithScrollbarNeverExceedsRequestedWidth(): void
    {
        $vp = Viewport::fromContent(\implode("\n", \range(1, 50)), 20, 6)
            ->withStickyHeader(1)
            ->withVerticalScrollbar(\SugarCraft\Flexbox\Scrollbar::vertical());

        $lines = \explode("\n", $vp->view());
        $this->assertCount(6, $lines);
        foreach ($lines as $line) {
            $this->assertSame(20, \SugarCraft\Core\Util\Width::string($line), 'sticky+scrollbar rows must be exactly the requested width');
        }
    }

    public function testShortDocumentNeverDuplicatesBetweenHeaderAndFooter(): void
    {
        // With sticky regions on a one-line document, the old footer clamp
        // ignored the document length and repeated the same line as header
        // AND footer — the screen reader read it twice.
        $v = Viewport::fromContent('only line', 20, 6)
            ->withStickyHeader(1)
            ->withStickyFooter(1);
        $view = $v->view();
        $lines = explode("\n", $view);
        $this->assertCount(6, $lines);
        $this->assertSame(1, substr_count($view, 'only line'));
    }

    public function testMiddleWindowStopsBeforeFooterRegionOnShortDocument(): void
    {
        // The middle slice was bounded only by the scrollable HEIGHT, never
        // by the rows that remain before the footer region starts: a 3-line
        // document in a 10-row frame with header 2 + footer 2 rendered the
        // last line as both the middle window's entry AND the footer's —
        // breaking the absolute claim in the feature list ("a row can never
        // appear in both regions"). The middle must stop at total - footer
        // and blank padding fills the rest.
        $v = Viewport::fromContent("l1\nl2\nl3", 20, 10)
            ->withStickyHeader(2)
            ->withStickyFooter(2);
        $view = $v->view();
        $lines = explode("\n", $view);
        $this->assertCount(10, $lines);
        $this->assertSame(1, substr_count($view, 'l1'));
        $this->assertSame(1, substr_count($view, 'l2'));
        $this->assertSame(1, substr_count($view, 'l3'));
        // l3 belongs to the FOOTER region (the bottom rows), not the middle.
        $this->assertStringContainsString('l3', $lines[9]);
        $this->assertSame('', trim($lines[8]));
    }

    public function testFooterOnlyStickyWindowNeverReachesIntoFooterRows(): void
    {
        // Same defect without any header: 4 lines in a 5-row frame with
        // footer 3 let the 2-row middle window slice rows 0..1 while the
        // footer re-rendered rows 1..3 — l2 appeared twice.
        $v = Viewport::fromContent("l1\nl2\nl3\nl4", 20, 5)
            ->withStickyFooter(3);
        $view = $v->view();
        $lines = explode("\n", $view);
        $this->assertCount(5, $lines);
        $this->assertSame(1, substr_count($view, 'l1'));
        $this->assertSame(1, substr_count($view, 'l2'));
        $this->assertSame(1, substr_count($view, 'l3'));
        $this->assertSame(1, substr_count($view, 'l4'));
    }

    public function testStickyTruncatesOverlongLinesToViewportWidth(): void
    {
        // Content wider than the viewport escaped the sticky frame (the
        // base view() clamps only when no scrollbar is shown and the
        // offset is zero); every painted row must be exactly `width` cells.
        $wide = str_repeat('0123456789', 10);
        $v = Viewport::fromContent($wide, 10, 3)->withStickyHeader(1);
        $lines = explode("\n", $v->view());
        foreach ($lines as $i => $line) {
            $this->assertSame(10, \SugarCraft\Core\Util\Width::string($line), "row $i");
        }
        $this->assertStringStartsWith('0123456789', $lines[0]);
    }

    public function testWideScrollbarRuneReservesItsCells(): void
    {
        // The scrollbar column was carved as exactly 1 cell while an
        // East-Asian thumb rune occupies 2, so every row ran 1 cell over
        // the viewport width.
        $content = implode("\n", array_map(
            static fn (int $n): string => 'line ' . $n,
            range(1, 20),
        ));
        $v = Viewport::fromContent($content, 10, 5)
            ->withStickyHeader(1)
            ->withScrollbar()
            ->withScrollbarRunes("\u{6771}", '│');
        $view = $v->view();
        $this->assertStringContainsString("\u{6771}", $view);
        foreach (explode("\n", $view) as $i => $line) {
            $this->assertSame(10, \SugarCraft\Core\Util\Width::string($line), "row $i");
        }
    }

    public function testWithSizeRejectsNegativeDimensions(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Viewport::fromContent('x', 20, 5)->withSize(40, -1);
    }

    // ---- withVerticalScrollbar accepts THIS lib's wrapper (shadowing trap) --

    public function testStickyPathPaintsTheSameScrollbarColumnAsThePlainPath(): void
    {
        // The old sticky substitution read only raw runes and ignored the
        // attached component, so one call produced two different scrollbars
        // (two in truth: the plain path painted the component's column,
        // the sticky path its own).
        $content = implode("\n", array_map(
            static fn (int $n): string => 'line ' . $n,
            range(1, 30),
        ));
        $plain = Viewport::fromContent($content, 20, 6)
            ->withVerticalScrollbar(Scrollbar::vertical());
        $sticky = $plain->withStickyHeader(1);

        $plainColumn = array_map(
            static fn (string $l): string => mb_substr($l, -1, null, 'UTF-8'),
            explode("\n", $plain->view()),
        );
        $stickyColumn = array_map(
            static fn (string $l): string => mb_substr($l, -1, null, 'UTF-8'),
            explode("\n", $sticky->view()),
        );

        $this->assertSame($plainColumn, $stickyColumn, 'one scrollbar, both paths');
        $this->assertContains('█', $stickyColumn, 'the component thumb rides the sticky frame');
        $this->assertContains('▲', $stickyColumn, 'the component arrows ride the sticky frame');
    }

    public function testStickyWideThumbReservesItsTrueCells(): void
    {
        // A CJK thumb through the component door: the sticky reservation
        // reads the carried runes, so the budget stays width-invariant.
        $content = implode("\n", array_map(
            static fn (int $n): string => 'line ' . $n,
            range(1, 30),
        ));
        $bar = Scrollbar::vertical()->withThumbChar("\u{6771}");
        $v = Viewport::fromContent($content, 20, 6)
            ->withVerticalScrollbar($bar)
            ->withStickyHeader(1);
        $view = $v->view();
        $this->assertStringContainsString("\u{6771}", $view);
        foreach (explode("\n", $view) as $i => $line) {
            $this->assertSame(20, \SugarCraft\Core\Util\Width::string($line), "wide-thumb sticky row $i");
        }
    }

    public function testTheViewportAcceptsItsOwnScrollbarWrapper(): void
    {
        // The documented companion is sugar-flexbox's own Scrollbar; the
        // parameter historically type-hinted the shadowing widgets import,
        // so the honest example threw a TypeError at the delegate boundary.
        $vp = Viewport::fromContent(\implode("\n", \range(1, 20)), 20, 6)
            ->withScrollbar()
            ->withVerticalScrollbar(Scrollbar::vertical());

        $again = $vp->withVerticalScrollbar(Scrollbar::horizontal());
        $this->assertNotSame($vp, $again, 'attaching a scrollbar stays immutable');

        $view = $again->view();
        $this->assertNotSame('', $view, 'a wrapped scrollbar must still render a frame');
        $this->assertSame(
            20,
            \SugarCraft\Core\Util\Width::string(\explode("\n", $view)[0]),
            'the scrollbar column stays inside the requested width',
        );
    }

    // ---- FBX-02: horizontal offset must slice EVERY sticky region -----

    /** Five 20-cell lines; digits ride both ends so every window offset
     *  paints bytes identifying WHICH line (and which cells) it shows. */
    private static function horizontalContent(): string
    {
        return implode("\n", array_map(
            static fn (int $i): string => sprintf('%d%s%d', $i, str_repeat('-', 17), $i),
            range(0, 4),
        ));
    }

    public function testStickyOffsetZeroPaintsLeadingWindows(): void
    {
        $vp = Viewport::fromContent(self::horizontalContent(), 10, 4)
            ->withStickyHeader(1)
            ->withStickyFooter(1);
        $this->assertSame(
            "0---------\n1---------\n2---------\n4---------",
            $vp->view(),
        );
        $this->assertTrue($vp->atLeftmost());
        $this->assertSame(0, $vp->xOffset());
    }

    public function testStickyMidOffsetDropsLeftCellsFromEveryRegion(): void
    {
        $vp = Viewport::fromContent(self::horizontalContent(), 10, 4)
            ->withStickyHeader(1)
            ->withStickyFooter(1)
            ->setXOffset(9);
        // Header, middle AND footer all shift by 9 cells — pre-fix the
        // sticky frame kept painting column zero at any offset.
        $this->assertSame(
            "---------0\n---------1\n---------2\n---------4",
            $vp->view(),
        );
        foreach (explode("\n", $vp->view()) as $i => $line) {
            $this->assertSame(10, \SugarCraft\Core\Util\Width::string($line), "row $i");
        }
    }

    public function testStickyRightmostOffsetPinsTailWindowsAndQueryState(): void
    {
        $vp = Viewport::fromContent(self::horizontalContent(), 10, 4)
            ->withStickyHeader(1)
            ->withStickyFooter(1)
            ->scrollRight(10);
        $this->assertSame(
            "---------0\n---------1\n---------2\n---------4",
            $vp->view(),
            'the tail windows are the rightmost pixels',
        );
        $this->assertSame(9, $vp->xOffset(), 'offset clamps to maxXOffset');
        $this->assertTrue($vp->atRightmost(), 'content wider than the viewport, scrolled fully right');
        $this->assertFalse($vp->atLeftmost());
        $this->assertSame(1.0, $vp->horizontalScrollPercent());
    }

    public function testStickyQueryStateAgreesWithPaintedPixelsMidScroll(): void
    {
        $mid = Viewport::fromContent(self::horizontalContent(), 10, 4)
            ->withStickyHeader(1)
            ->withStickyFooter(1)
            ->setXOffset(5);
        $this->assertFalse($mid->atLeftmost());
        $this->assertFalse($mid->atRightmost());
        $this->assertEqualsWithDelta(5 / 9, $mid->horizontalScrollPercent(), 0.0001);
        $edge = $mid->setXOffset(9);
        // The first painted row is header line 0 starting exactly at cell 9.
        $this->assertSame('---------0', explode("\n", $edge->view())[0]);
    }

    public function testStickyHeaderOnlyAndFooterOnlySliceToo(): void
    {
        $headerOnly = Viewport::fromContent(self::horizontalContent(), 10, 4)
            ->withStickyHeader(1)
            ->setXOffset(9);
        $this->assertSame(
            "---------0\n---------1\n---------2\n---------3",
            $headerOnly->view(),
        );

        $footerOnly = Viewport::fromContent(self::horizontalContent(), 10, 3)
            ->withStickyFooter(1)
            ->setXOffset(9);
        $this->assertSame(
            "---------0\n---------1\n---------4",
            $footerOnly->view(),
        );
    }

    public function testNonStickyPathKeepsDelegateHorizontalLaw(): void
    {
        // The plain path answers through the delegate, which drops the left
        // cells without a right clamp; the sticky fix must not regress it.
        $vp = Viewport::fromContent('0123456789abcdefghij', 10, 1)->setXOffset(4);
        $this->assertSame('456789abcdefghij', $vp->view());
    }
}
