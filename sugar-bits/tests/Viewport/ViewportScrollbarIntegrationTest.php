<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Viewport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Bits\Scrollbar\Scrollbar;
use SugarCraft\Bits\Scrollbar\ScrollbarState;
use SugarCraft\Bits\Viewport\Viewport;

final class ViewportScrollbarIntegrationTest extends TestCase
{
    private function content(int $n): string
    {
        $lines = [];
        for ($i = 1; $i <= $n; $i++) {
            $lines[] = "line $i";
        }
        return implode("\n", $lines);
    }

    // ── withVerticalScrollbar() — structural ──────────────────────────────────

    public function testWithVerticalScrollbarReturnsNewInstance(): void
    {
        $vp = Viewport::new(80, 5)->setContent($this->content(10));
        $sb = Scrollbar::vertical();
        $vp2 = $vp->withVerticalScrollbar($sb);
        $this->assertNotSame($vp, $vp2);
        $this->assertNull($vp->verticalScrollbar);
        $this->assertNotNull($vp2->verticalScrollbar);
    }

    public function testWithVerticalScrollbarPreservesOtherState(): void
    {
        $vp = Viewport::new(80, 5)
            ->setContent($this->content(10))
            ->lineDown(3);
        $sb = Scrollbar::vertical();
        $vp2 = $vp->withVerticalScrollbar($sb);
        $this->assertSame($vp->width, $vp2->width);
        $this->assertSame($vp->height, $vp2->height);
        $this->assertSame($vp->yOffset, $vp2->yOffset);
        $this->assertSame($vp->showScrollbar, $vp2->showScrollbar);
    }

    // ── Integration: Scrollbar component used when injected ───────────────────

    public function testViewRendersScrollbarColumnWhenInjected(): void
    {
        // total=10, viewport=3, position=0
        // Without arrows: availableSpace=3, thumbHeight=max(1,round(3/10*3))=max(1,0)=1
        // maxThumbStart=3-1=2, thumbStart=round(0/9*2)=0
        $vp = Viewport::new(10, 3)
            ->setContent($this->content(10))
            ->withScrollbar(true)
            ->withVerticalScrollbar(Scrollbar::vertical()->withArrows(false));

        $output = $vp->view();

        // Each line should be padded to width-1=9, then scrollbar char appended
        $lines = explode("\n", $output);
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            // Line + scrollbar column = 10 chars
            $this->assertSame(10, mb_strlen($line, 'UTF-8'));
        }
    }

    public function testViewUsesScrollbarStateFromViewport(): void
    {
        // Build a scrollbar with known chars so we can trace the thumb position.
        // total=100, viewport=20, position=80 (max) → thumb at bottom
        $vp = Viewport::new(10, 20)
            ->setContent($this->content(100))
            ->gotoBottom() // yOffset = 80 (maxOffset)
            ->withScrollbar(true)
            ->withVerticalScrollbar(
                Scrollbar::vertical()
                    ->withArrows(false)
                    ->withTrackChar('░')
                    ->withThumbChar('█'),
            );

        $output = $vp->view();
        $lines = explode("\n", $output);

        // Last line should end with the thumb char (at bottom of scroll range)
        $lastLine = end($lines);
        $this->assertSame('█', mb_substr($lastLine, -1, 1, 'UTF-8'));
    }

    public function testViewUsesScrollbarStatePositionMidpoint(): void
    {
        // total=100, viewport=20, position=40 (middle of 0-80 range)
        // availableSpace=20, thumbHeight=max(1,round(20/100*20))=max(1,4)=4
        // maxThumbStart=20-4=16, thumbStart=round(40/80*16)=round(8)=8
        // Thumb occupies positions 8-11 in the 20-row output
        $vp = Viewport::new(10, 20)
            ->setContent($this->content(100))
            ->setYOffset(40)
            ->withScrollbar(true)
            ->withVerticalScrollbar(
                Scrollbar::vertical()
                    ->withArrows(false)
                    ->withTrackChar('░')
                    ->withThumbChar('█'),
            );

        $output = $vp->view();
        $lines = explode("\n", $output);
        $this->assertCount(20, $lines);

        // Lines 8-11 should have thumb char
        for ($i = 0; $i < 20; $i++) {
            $char = mb_substr($lines[$i], -1, 1, 'UTF-8');
            if ($i >= 8 && $i < 12) {
                $this->assertSame('█', $char, "Line $i should be thumb char");
            } else {
                $this->assertSame('░', $char, "Line $i should be track char");
            }
        }
    }

    // ── Fallback: inline scrollbar still used when no Scrollbar injected ─────

    public function testViewFallsBackToInlineScrollbarWhenNoComponentInjected(): void
    {
        $vp = Viewport::new(10, 3)
            ->setContent($this->content(10))
            ->withScrollbar(true);

        $output = $vp->view();
        $lines = explode("\n", $output);

        // Should still render (inline scrollbar path, no Scrollbar component)
        $this->assertCount(3, $lines);
        foreach ($lines as $line) {
            $this->assertSame(10, mb_strlen($line, 'UTF-8'));
        }
    }

    // ── Custom Scrollbar chars ─────────────────────────────────────────────────

    public function testCustomScrollbarCharsAreUsed(): void
    {
        $vp = Viewport::new(10, 3)
            ->setContent($this->content(10))
            ->withScrollbar(true)
            ->withVerticalScrollbar(
                Scrollbar::vertical()
                    ->withArrows(false)
                    ->withTrackChar('.')
                    ->withThumbChar('#'),
            );

        $output = $vp->view();
        $this->assertStringContainsString('.', $output);
        $this->assertStringContainsString('#', $output);
        $this->assertStringNotContainsString('░', $output);
        $this->assertStringNotContainsString('█', $output);
    }

    // ── Edge cases ────────────────────────────────────────────────────────────

    public function testContentFitsViewportNoScrollbarNeeded(): void
    {
        // total=3, viewport=5 → content fits, all track chars
        $vp = Viewport::new(10, 5)
            ->setContent($this->content(3))
            ->withScrollbar(true)
            ->withVerticalScrollbar(
                Scrollbar::vertical()
                    ->withArrows(false)
                    ->withTrackChar('░')
                    ->withThumbChar('█'),
            );

        $output = $vp->view();
        $this->assertStringNotContainsString('█', $output);
    }

    public function testZeroHeightViewportReturnsEmpty(): void
    {
        $vp = Viewport::new(10, 0)
            ->setContent($this->content(10))
            ->withScrollbar(true)
            ->withVerticalScrollbar(Scrollbar::vertical()->withArrows(false));

        $this->assertSame('', $vp->view());
    }

    public function testScrollbarColumnFollowsViewportScrollPosition(): void
    {
        // Start at top (position 0), then scroll to middle
        $vp = Viewport::new(10, 5)
            ->setContent($this->content(50))
            ->withScrollbar(true)
            ->withVerticalScrollbar(
                Scrollbar::vertical()
                    ->withArrows(false)
                    ->withTrackChar('░')
                    ->withThumbChar('█'),
            );

        // At yOffset=0: thumb should be at top
        $top = $vp->view();
        $topLines = explode("\n", $top);

        // Scroll to middle
        $vp2 = $vp->setYOffset(22); // ~middle of maxOffset=45
        $mid = $vp2->view();
        $midLines = explode("\n", $mid);

        // Thumb position should differ between top and mid
        $topThumbRow = null;
        $midThumbRow = null;
        foreach ($topLines as $i => $line) {
            if (mb_substr($line, -1, 1, 'UTF-8') === '█') {
                $topThumbRow = $i;
                break;
            }
        }
        foreach ($midLines as $i => $line) {
            if (mb_substr($line, -1, 1, 'UTF-8') === '█') {
                $midThumbRow = $i;
                break;
            }
        }
        $this->assertNotNull($topThumbRow);
        $this->assertNotNull($midThumbRow);
        $this->assertNotSame($topThumbRow, $midThumbRow);
    }

    // ── Horizontal scrollbar not yet implemented ──────────────────────────────

    public function testHorizontalScrollbarNotAffectedByVerticalScrollbarInjection(): void
    {
        // The vertical Scrollbar component only affects the vertical scrollbar.
        // Horizontal scrolling is independent.
        $vp = Viewport::new(5, 3) // narrow width
            ->setContent("this is a very long line that exceeds the viewport width")
            ->withScrollbar(true)
            ->withVerticalScrollbar(
                Scrollbar::vertical()->withArrows(false),
            );

        // xOffset=0, should show beginning of line
        $output = $vp->view();
        $this->assertStringStartsWith('this i', $output);
    }
}
