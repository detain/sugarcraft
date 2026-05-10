<?php

declare(strict_types=1);

namespace SugarCraft\Bits\Tests\Viewport;

use SugarCraft\Bits\Viewport\Viewport;
use SugarCraft\Bits\Viewport\ViewportTickMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\MouseAction;
use SugarCraft\Core\MouseButton;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\MouseWheelMsg;
use PHPUnit\Framework\TestCase;

final class ViewportTest extends TestCase
{
    private function content(int $n): string
    {
        $lines = [];
        for ($i = 1; $i <= $n; $i++) {
            $lines[] = "line $i";
        }
        return implode("\n", $lines);
    }

    public function testInitialEmpty(): void
    {
        $v = Viewport::new(80, 5);
        $this->assertSame('', $v->view());
        $this->assertTrue($v->atTop());
        $this->assertTrue($v->atBottom());
        $this->assertSame(1.0, $v->scrollPercent());
    }

    public function testShowsFirstWindow(): void
    {
        $v = Viewport::new(80, 3)->setContent($this->content(10));
        $this->assertSame("line 1\nline 2\nline 3", $v->view());
        $this->assertTrue($v->atTop());
        $this->assertFalse($v->atBottom());
    }

    public function testLineDownScrolls(): void
    {
        $v = Viewport::new(80, 3)->setContent($this->content(10));
        $v = $v->lineDown(2);
        $this->assertSame("line 3\nline 4\nline 5", $v->view());
    }

    public function testGotoBottom(): void
    {
        $v = Viewport::new(80, 3)->setContent($this->content(10))->gotoBottom();
        $this->assertTrue($v->atBottom());
        $this->assertSame("line 8\nline 9\nline 10", $v->view());
        $this->assertSame(1.0, $v->scrollPercent());
    }

    public function testGotoTop(): void
    {
        $v = Viewport::new(80, 3)->setContent($this->content(10))->gotoBottom();
        $v = $v->gotoTop();
        $this->assertTrue($v->atTop());
    }

    public function testHalfPageDown(): void
    {
        $v = Viewport::new(80, 4)->setContent($this->content(20));
        $v = $v->halfPageDown();
        $this->assertSame(2, $v->yOffset);
    }

    public function testPageDown(): void
    {
        $v = Viewport::new(80, 4)->setContent($this->content(20));
        $v = $v->pageDown();
        $this->assertSame(4, $v->yOffset);
    }

    public function testCannotScrollPastEnd(): void
    {
        $v = Viewport::new(80, 3)->setContent($this->content(5));
        $v = $v->lineDown(100);
        $this->assertTrue($v->atBottom());
        $this->assertSame(2, $v->yOffset);
    }

    public function testCannotScrollAboveTop(): void
    {
        $v = Viewport::new(80, 3)->setContent($this->content(10));
        $v = $v->lineUp(5);
        $this->assertTrue($v->atTop());
    }

    public function testKeyboardNav(): void
    {
        $v = Viewport::new(80, 3)->setContent($this->content(10));
        [$v, ] = $v->update(new KeyMsg(KeyType::Down));
        $this->assertSame(1, $v->yOffset);
        [$v, ] = $v->update(new KeyMsg(KeyType::Char, 'j'));
        $this->assertSame(2, $v->yOffset);
        [$v, ] = $v->update(new KeyMsg(KeyType::Up));
        $this->assertSame(1, $v->yOffset);
        [$v, ] = $v->update(new KeyMsg(KeyType::Char, 'G'));
        $this->assertTrue($v->atBottom());
    }

    public function testCtrlDHalfPage(): void
    {
        $v = Viewport::new(80, 4)->setContent($this->content(20));
        [$v, ] = $v->update(new KeyMsg(KeyType::Char, 'd', ctrl: true));
        $this->assertSame(2, $v->yOffset);
    }

    public function testScrollPercentMidway(): void
    {
        $v = Viewport::new(80, 5)->setContent($this->content(15));
        $v = $v->lineDown(5);  // max offset = 10, so 0.5
        $this->assertEqualsWithDelta(0.5, $v->scrollPercent(), 1e-6);
    }

    public function testTotalAndVisibleLineCount(): void
    {
        $v = Viewport::new(80, 4)->setContent($this->content(10));
        $this->assertSame(10, $v->totalLineCount());
        $this->assertSame(4,  $v->visibleLineCount());
        $v = $v->gotoBottom();
        $this->assertSame(4,  $v->visibleLineCount());
    }

    public function testWithSizeReclamps(): void
    {
        $v = Viewport::new(80, 5)->setContent($this->content(20))->gotoBottom();
        $this->assertSame(15, $v->yOffset);
        $v = $v->withSize(80, 10);
        $this->assertSame(10, $v->yOffset); // new max offset
    }

    private function wide(int $cols, int $rows): string
    {
        $lines = [];
        for ($r = 1; $r <= $rows; $r++) {
            $lines[] = str_repeat(chr(ord('a') + (($r - 1) % 26)), $cols);
        }
        return implode("\n", $lines);
    }

    public function testInitialXOffsetZero(): void
    {
        $v = Viewport::new(10, 3)->setContent($this->wide(40, 3));
        $this->assertSame(0, $v->xOffset);
        $this->assertTrue($v->atLeftmost());
        $this->assertFalse($v->atRightmost());
    }

    public function testScrollRightAdvancesByStep(): void
    {
        $v = Viewport::new(10, 3)->setContent($this->wide(40, 3))->withHorizontalStep(5);
        $v = $v->scrollRight();
        $this->assertSame(5, $v->xOffset);
        $v = $v->scrollRight();
        $this->assertSame(10, $v->xOffset);
    }

    public function testScrollLeftCannotGoBelowZero(): void
    {
        $v = Viewport::new(10, 3)->setContent($this->wide(40, 3));
        $v = $v->scrollLeft();
        $this->assertSame(0, $v->xOffset);
    }

    public function testScrollRightClampsAtMax(): void
    {
        $v = Viewport::new(10, 3)->setContent($this->wide(15, 3))->withHorizontalStep(20);
        $v = $v->scrollRight();
        $this->assertSame(5, $v->xOffset); // widest=15, width=10 -> max 5
        $this->assertTrue($v->atRightmost());
    }

    public function testSetXOffsetClamps(): void
    {
        $v = Viewport::new(10, 3)->setContent($this->wide(15, 3));
        $v = $v->setXOffset(99);
        $this->assertSame(5, $v->xOffset);
        $v = $v->setXOffset(-3);
        $this->assertSame(0, $v->xOffset);
    }

    public function testHorizontalScrollPercent(): void
    {
        $v = Viewport::new(10, 3)->setContent($this->wide(20, 3))->setXOffset(5);
        // max=10 → halfway
        $this->assertEqualsWithDelta(0.5, $v->horizontalScrollPercent(), 1e-6);
    }

    public function testHorizontalArrowKey(): void
    {
        $v = Viewport::new(10, 3)->setContent($this->wide(40, 3))->withHorizontalStep(4);
        [$v, ] = $v->update(new KeyMsg(KeyType::Right));
        $this->assertSame(4, $v->xOffset);
        [$v, ] = $v->update(new KeyMsg(KeyType::Left));
        $this->assertSame(0, $v->xOffset);
    }

    public function testHorizontalVimKeys(): void
    {
        $v = Viewport::new(10, 3)->setContent($this->wide(40, 3))->withHorizontalStep(2);
        [$v, ] = $v->update(new KeyMsg(KeyType::Char, 'l'));
        $this->assertSame(2, $v->xOffset);
        [$v, ] = $v->update(new KeyMsg(KeyType::Char, 'h'));
        $this->assertSame(0, $v->xOffset);
    }

    public function testViewDropsLeftCellsOnXOffset(): void
    {
        // viewport must be narrower than the line for setXOffset to stick.
        $v = Viewport::new(5, 1)->setContent('abcdefghij')->setXOffset(3);
        $this->assertSame('defghij', $v->view());
    }

    public function testSetWidthAndSetHeightAreIndependent(): void
    {
        $v = Viewport::new(20, 5);
        $v2 = $v->setWidth(40);
        $this->assertSame(40, $v2->getWidth());
        $this->assertSame(5, $v2->getHeight());

        $v3 = $v2->setHeight(10);
        $this->assertSame(40, $v3->getWidth());
        $this->assertSame(10, $v3->getHeight());
    }

    public function testSetWidthRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Viewport::new(20, 5)->setWidth(-1);
    }

    public function testSetHeightRejectsNegative(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Viewport::new(20, 5)->setHeight(-1);
    }

    // ---- smooth scroll ------------------------------------------------

    public function testWithSmoothScrollReturnsNewInstance(): void
    {
        $v = Viewport::new(80, 5);
        $v2 = $v->withSmoothScroll(true);
        $this->assertNotSame($v, $v2);
        $this->assertFalse($v->smoothScroll);
        $this->assertTrue($v2->smoothScroll);
    }

    public function testWithSmoothScrollDefaultTrue(): void
    {
        $v = Viewport::new(80, 5)->withSmoothScroll();
        $this->assertTrue($v->smoothScroll);
    }

    public function testSmoothScrollDisabledStaysAtOriginalPosition(): void
    {
        // Without smooth scroll, lineDown should jump immediately.
        $v = Viewport::new(80, 3)
            ->setContent($this->content(10))
            ->lineDown(2);
        $this->assertSame(2, $v->yOffset);
    }

    public function testSmoothScrollEnabledStaysAtOriginalPositionOnFirstUpdate(): void
    {
        // With smooth scroll, the first update doesn't jump - it starts animating.
        $v = Viewport::new(80, 3)
            ->setContent($this->content(10))
            ->withSmoothScroll(true);

        [$v, ] = $v->update(new KeyMsg(KeyType::Down));

        // Position stays at 0 while animation begins.
        $this->assertSame(0, $v->yOffset);
        $this->assertSame(1, $v->scrollTargetY);
        $this->assertSame(10, $v->scrollAnimFrame);
    }

    public function testSmoothScrollAdvancesOnTickMsg(): void
    {
        $v = Viewport::new(80, 3)
            ->setContent($this->content(10))
            ->withSmoothScroll(true);

        // Trigger smooth scroll animation.
        [$v, ] = $v->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $v->yOffset);

        // Advance animation by sending tick messages.
        for ($i = 0; $i < 9; $i++) {
            [$v, ] = $v->update(new ViewportTickMsg());
        }

        // After 9 ticks, still animating but close to target.
        $this->assertGreaterThanOrEqual(0, $v->yOffset);
        $this->assertLessThanOrEqual(1, $v->yOffset);

        // Final tick completes animation.
        [$v, ] = $v->update(new ViewportTickMsg());
        $this->assertSame(1, $v->yOffset);
        $this->assertSame(-1, $v->scrollTargetY);
        $this->assertSame(0, $v->scrollAnimFrame);
    }

    public function testSmoothScrollGotoBottomCompletesAnimation(): void
    {
        $v = Viewport::new(80, 3)
            ->setContent($this->content(10))
            ->withSmoothScroll(true);

        [$v, ] = $v->update(new KeyMsg(KeyType::Char, 'G'));
        $this->assertSame(0, $v->yOffset); // Animation starting from top.
        $this->assertSame(7, $v->scrollTargetY); // Target is bottom.

        // Complete animation.
        for ($i = 0; $i < 10; $i++) {
            [$v, ] = $v->update(new ViewportTickMsg());
        }
        $this->assertSame(7, $v->yOffset);
        $this->assertTrue($v->atBottom());
    }

    public function testSmoothScrollReturnsCmdToContinueAnimation(): void
    {
        $v = Viewport::new(80, 3)
            ->setContent($this->content(10))
            ->withSmoothScroll(true);

        [$v, $cmd] = $v->update(new KeyMsg(KeyType::Down));

        // Should return a command to continue animation.
        $this->assertNotNull($cmd);
        $this->assertInstanceOf(\Closure::class, $cmd);
    }

    public function testMouseWheelBypassesSmoothScroll(): void
    {
        $v = Viewport::new(80, 3)
            ->setContent($this->content(10))
            ->withSmoothScroll(true);

        [$v, ] = $v->update(new MouseWheelMsg(0, 0, MouseButton::WheelDown, MouseAction::Press));

        // Mouse wheel should jump immediately without animation.
        $this->assertSame(3, $v->yOffset);
        $this->assertSame(-1, $v->scrollTargetY);
        $this->assertSame(0, $v->scrollAnimFrame);
    }

    public function testSmoothScrollHorizontalScroll(): void
    {
        $v = Viewport::new(10, 3)
            ->setContent($this->wide(40, 3))
            ->withSmoothScroll(true);

        [$v, ] = $v->update(new KeyMsg(KeyType::Right));
        $this->assertSame(0, $v->xOffset); // Animation starting.
        $this->assertSame(6, $v->scrollTargetX); // Target is scrollRight step.

        // Complete animation.
        for ($i = 0; $i < 10; $i++) {
            [$v, ] = $v->update(new ViewportTickMsg());
        }
        $this->assertSame(6, $v->xOffset);
    }

    public function testSmoothScrollMultipleNavigationsWithinAnimation(): void
    {
        $v = Viewport::new(80, 3)
            ->setContent($this->content(10))
            ->withSmoothScroll(true);

        // First scroll down.
        [$v, ] = $v->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $v->yOffset);
        $this->assertSame(1, $v->scrollTargetY);

        // Another scroll down before first completes.
        [$v, ] = $v->update(new KeyMsg(KeyType::Down));
        $this->assertSame(0, $v->yOffset);
        $this->assertSame(2, $v->scrollTargetY); // Target updated.

        // Complete animation.
        for ($i = 0; $i < 10; $i++) {
            [$v, ] = $v->update(new ViewportTickMsg());
        }
        $this->assertSame(2, $v->yOffset);
    }

    public function testSmoothScrollDisabledByDefault(): void
    {
        $v = Viewport::new(80, 3)->setContent($this->content(10));
        $this->assertFalse($v->smoothScroll);
    }

    public function testSmoothScrollToggle(): void
    {
        $v = Viewport::new(80, 3)
            ->setContent($this->content(10))
            ->withSmoothScroll(true);

        $v2 = $v->withSmoothScroll(false);
        $this->assertFalse($v2->smoothScroll);

        // Original unchanged.
        $this->assertTrue($v->smoothScroll);
    }
}
