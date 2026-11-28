<?php

declare(strict_types=1);

namespace SugarCraft\Forms\Tests\Viewport;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\KeyType;
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Forms\Viewport\Viewport;
use SugarCraft\Forms\Viewport\ViewportTickMsg;

/**
 * Pins the immutable `withContent()` / `scrollBy()` pair that P2-3 names
 * (`Viewport::new()->withContent($lines)->scrollBy($n)->view()`), beside the
 * pre-existing set* family, which ViewportTest keeps covering unchanged.
 */
final class ViewportImmutableApiTest extends TestCase
{
    /** @return list<string> */
    private function lines(int $n): array
    {
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = "line $i";
        }
        return $out;
    }

    public function testThePlanChainRendersTheScrolledWindow(): void
    {
        $view = Viewport::new(80, 3)->withContent($this->lines(10))->scrollBy(2)->view();

        $this->assertSame("line 3\nline 4\nline 5", $view);
    }

    public function testWithContentAcceptsAStringSplitOnNewlines(): void
    {
        $v = Viewport::new(80, 3)->withContent(implode("\n", $this->lines(5)));

        $this->assertSame(5, $v->totalLineCount());
        $this->assertSame("line 1\nline 2\nline 3", $v->view());
    }

    public function testWithContentListAndStringYieldTheSameLines(): void
    {
        $fromList   = Viewport::new(80, 3)->withContent(['a', "b\nc", 'd']);
        $fromString = Viewport::new(80, 3)->withContent("a\nb\nc\nd");

        $this->assertSame(['a', 'b', 'c', 'd'], $fromList->lines);
        $this->assertSame($fromString->lines, $fromList->lines);
    }

    public function testWithContentMatchesSetContentForTheSameText(): void
    {
        $text = implode("\n", $this->lines(7));

        $this->assertSame(
            Viewport::new(80, 3)->setContent($text)->lines,
            Viewport::new(80, 3)->withContent($text)->lines,
        );
    }

    public function testWithContentEmptyStringAndEmptyListYieldOneBlankLine(): void
    {
        $this->assertSame([''], Viewport::new(80, 3)->withContent('')->lines);
        $this->assertSame([''], Viewport::new(80, 3)->withContent([])->lines);
        $this->assertSame('', Viewport::new(80, 3)->withContent([])->view());
    }

    public function testWithContentClampsAnOffsetPastTheNewEnd(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(20))->scrollBy(15);
        $this->assertSame(15, $v->yOffset());

        $shorter = $v->withContent($this->lines(5));

        $this->assertSame(2, $shorter->yOffset());
        $this->assertTrue($shorter->atBottom());
        $this->assertSame("line 3\nline 4\nline 5", $shorter->view());
    }

    public function testWithContentKeepsAnOffsetThatStillFits(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(20))->scrollBy(4);

        $this->assertSame(4, $v->withContent($this->lines(30))->yOffset());
    }

    public function testScrollByNegativeMovesUp(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(10))->scrollBy(5)->scrollBy(-2);

        $this->assertSame(3, $v->yOffset());
        $this->assertSame("line 4\nline 5\nline 6", $v->view());
    }

    public function testScrollByZeroKeepsTheOffset(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(10))->scrollBy(4);

        $this->assertSame(4, $v->scrollBy(0)->yOffset());
    }

    public function testScrollByClampsAtTheBottom(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(10))->scrollBy(100);

        $this->assertSame(7, $v->yOffset());
        $this->assertTrue($v->atBottom());
        $this->assertSame("line 8\nline 9\nline 10", $v->view());
    }

    public function testScrollByClampsAtTheTop(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(10))->scrollBy(3)->scrollBy(-100);

        $this->assertSame(0, $v->yOffset());
        $this->assertTrue($v->atTop());
    }

    public function testScrollByStaysAtZeroWhenContentFits(): void
    {
        $v = Viewport::new(80, 5)->withContent($this->lines(3))->scrollBy(2);

        $this->assertSame(0, $v->yOffset());
        $this->assertSame("line 1\nline 2\nline 3", $v->view());
    }

    public function testScrollBySurvivesIntExtremes(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(10));

        $this->assertSame(7, $v->scrollBy(PHP_INT_MAX)->yOffset());
        $this->assertSame(0, $v->scrollBy(5)->scrollBy(PHP_INT_MIN)->yOffset());
    }

    public function testScrollByLeavesTheHorizontalOffsetAlone(): void
    {
        $v = Viewport::new(4, 2)
            ->withContent(['abcdefghij', 'klmnopqrst', 'uvwxyz', '0123'])
            ->setXOffset(3)
            ->scrollBy(1);

        $this->assertSame(3, $v->xOffset());
        $this->assertSame(1, $v->yOffset());
    }

    public function testWithContentDoesNotChangeTheOriginal(): void
    {
        $original = Viewport::new(80, 3)->withContent($this->lines(10))->scrollBy(4);
        $linesBefore = $original->lines;
        $viewBefore  = $original->view();

        $next = $original->withContent($this->lines(2));

        $this->assertNotSame($original, $next);
        $this->assertSame($linesBefore, $original->lines);
        $this->assertSame(4, $original->yOffset());
        $this->assertSame($viewBefore, $original->view());
    }

    public function testScrollByDoesNotChangeTheOriginal(): void
    {
        $original = Viewport::new(80, 3)->withContent($this->lines(10))->scrollBy(2);
        $viewBefore = $original->view();

        $down = $original->scrollBy(3);
        $none = $original->scrollBy(0);

        $this->assertNotSame($original, $down);
        $this->assertNotSame($original, $none);
        $this->assertSame(2, $original->yOffset());
        $this->assertSame(5, $down->yOffset());
        $this->assertSame($viewBefore, $original->view());
    }

    public function testTheSetFamilyStillBehavesAsBefore(): void
    {
        $v = Viewport::new(80, 3)->setContent(implode("\n", $this->lines(10)))->setYOffset(50);

        $this->assertSame(7, $v->yOffset());
        $this->assertSame(
            $v->view(),
            Viewport::new(80, 3)->withContent($this->lines(10))->scrollBy(50)->view(),
        );
    }

    public function testScrollByDuringASmoothScrollIsNotUndoneByLaterTicks(): void
    {
        $v = Viewport::new(80, 5)->withContent($this->lines(30))->withSmoothScroll(true);
        [$v, ] = $v->update(new KeyMsg(KeyType::Down));
        $this->assertSame(10, $v->scrollAnimFrame);

        // The pending one-line move from j is kept: 1 + 10, as if the
        // animation had finished before the step.
        $v = $v->scrollBy(10);
        $this->assertSame(11, $v->yOffset());
        $this->assertSame(0, $v->scrollAnimFrame);
        $this->assertSame(-1, $v->scrollTargetY);

        for ($i = 0; $i < 10; $i++) {
            [$v, $cmd] = $v->update(new ViewportTickMsg());
            $this->assertNull($cmd);
        }
        $this->assertSame(11, $v->yOffset());
    }

    public function testScrollByDuringASmoothScrollMatchesTheSettledResult(): void
    {
        $base = Viewport::new(80, 5)->withContent($this->lines(30))->withSmoothScroll(true);
        [$animating, ] = $base->update(new KeyMsg(KeyType::Down));
        [$animating, ] = $animating->update(new KeyMsg(KeyType::Down));

        $settled = $animating;
        while ($settled->scrollAnimFrame > 0) {
            [$settled, ] = $settled->update(new ViewportTickMsg());
        }

        $this->assertSame(2, $settled->yOffset());
        $this->assertSame($settled->scrollBy(10)->yOffset(), $animating->scrollBy(10)->yOffset());
        $this->assertSame($settled->scrollBy(-1)->yOffset(), $animating->scrollBy(-1)->yOffset());
        $this->assertSame(25, $animating->scrollBy(100)->yOffset());
    }

    public function testWithContentDuringASmoothScrollDoesNotTickPastTheNewEnd(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(30))->withSmoothScroll(true);
        [$v, ] = $v->update(new KeyMsg(KeyType::End));
        $this->assertSame(27, $v->scrollTargetY);
        $this->assertSame(10, $v->scrollAnimFrame);
        [$v, ] = $v->update(new ViewportTickMsg());
        [$v, ] = $v->update(new ViewportTickMsg());

        $v = $v->withContent($this->lines(5));
        $this->assertSame(2, $v->yOffset());
        $this->assertSame(0, $v->scrollAnimFrame);
        $this->assertSame(-1, $v->scrollTargetY);
        $this->assertSame(-1, $v->scrollTargetX);

        for ($i = 0; $i < 12; $i++) {
            [$v, $cmd] = $v->update(new ViewportTickMsg());
            $this->assertNull($cmd);
        }
        $this->assertSame(2, $v->yOffset());
        $this->assertSame("line 3\nline 4\nline 5", $v->view());
    }

    public function testWithContentDuringASmoothScrollKeepsTheCarriedMoveWhenItFits(): void
    {
        $v = Viewport::new(80, 3)->withContent($this->lines(30))->withSmoothScroll(true);
        [$v, ] = $v->update(new KeyMsg(KeyType::End));
        [$v, ] = $v->update(new ViewportTickMsg());

        // The new content is longer, so the pending target still fits and
        // the window lands on it rather than stopping mid-lerp.
        $v = $v->withContent($this->lines(40));
        $this->assertSame(27, $v->yOffset());
        $this->assertSame(0, $v->scrollAnimFrame);
    }
}
