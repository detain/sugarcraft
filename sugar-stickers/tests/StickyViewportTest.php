<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Stickers\Viewport;

final class StickyViewportTest extends TestCase
{
    // ---- withStickyHeader coercion ------------------------------------

    public function testWithStickyHeaderRejectsNegative(): void
    {
        $vp = Viewport::withContent("one\ntwo\nthree", 20, 4)->withStickyHeader(-5);
        // Negative is clamped to 0; falls through to normal rendering.
        $view = $vp->view();
        $this->assertStringContainsString('one', $view);
        $this->assertStringContainsString('two', $view);
        $this->assertStringContainsString('three', $view);
    }

    public function testWithStickyHeaderZeroIsNoOp(): void
    {
        $vp = Viewport::withContent("line1\nline2\nline3", 20, 3)->withStickyHeader(0);
        $this->assertStringContainsString('line1', $vp->view());
    }

    // ---- withStickyFooter coercion --------------------------------------

    public function testWithStickyFooterRejectsNegative(): void
    {
        $vp = Viewport::withContent("one\ntwo\nthree", 20, 4)->withStickyFooter(-5);
        // Negative is clamped to 0; falls through to normal rendering.
        $view = $vp->view();
        $this->assertStringContainsString('one', $view);
        $this->assertStringContainsString('three', $view);
    }

    public function testWithStickyFooterZeroIsNoOp(): void
    {
        $vp = Viewport::withContent("line1\nline2\nline3", 20, 3)->withStickyFooter(0);
        $this->assertStringContainsString('line3', $vp->view());
    }

    // ---- Sticky header rendering ---------------------------------------

    public function testStickyHeaderAlwaysShowsFirstNLines(): void
    {
        // Use content that exceeds viewport height so scrolling is possible.
        $content = "header1\nheader2\nscrollable1\nscrollable2\nscrollable3\nfooter1";
        // stickyHeader=2, stickyFooter=1, viewport height=6, content has 6 lines
        $vp = Viewport::withContent($content, 20, 6)
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
        $vp = Viewport::withContent($content, 20, 50)
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
        $vp = Viewport::withContent($content, 20, 6)
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

        $vp = Viewport::withContent($content, 20, 50)
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
        $vp = Viewport::withContent($content, 20, 6)
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
        $vp = Viewport::withContent($content, 20, 6)
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
        $vp = Viewport::withContent("line1\nline2\nline3", 20, 3)
            ->withStickyHeader(0)
            ->withStickyFooter(0);

        $this->assertStringContainsString('line1', $vp->view());
        $this->assertStringContainsString('line3', $vp->view());
    }

    // ---- Immutability -------------------------------------------------

    public function testStickyHeaderReturnsNewInstance(): void
    {
        $a = Viewport::withContent("line1\nline2", 20, 3);
        $b = $a->withStickyHeader(1);
        $this->assertNotSame($a, $b);
    }

    public function testStickyFooterReturnsNewInstance(): void
    {
        $a = Viewport::withContent("line1\nline2", 20, 3);
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

        $vp = Viewport::withContent($content, 20, 10)
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

        $vp = Viewport::withContent($content, 20, 10)
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
        $vp = Viewport::withContent("1\n2\n3\n4\n5\n6", 20, 6)
            ->withStickyHeader(5)
            ->withStickyFooter(1);

        $view = $vp->view();
        $lines = explode("\n", $view);
        $this->assertCount(6, $lines);
    }

    public function testStickyFooterClampedToViewportHeight(): void
    {
        // stickyHeader=1, stickyFooter=5, height=6 → footer clamped to 5 (6-1=5)
        $vp = Viewport::withContent("1\n2\n3\n4\n5\n6", 20, 6)
            ->withStickyHeader(1)
            ->withStickyFooter(5);

        $view = $vp->view();
        $lines = explode("\n", $view);
        $this->assertCount(6, $lines);
    }
}
