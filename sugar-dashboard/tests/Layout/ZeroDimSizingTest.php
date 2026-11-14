<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Measurable;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Layout\Frame;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;
use SugarCraft\Dashboard\Layout\Grid\StackedGrid;
use SugarCraft\Dashboard\Layout\Pad;
use SugarCraft\Dashboard\Layout\Panel;
use SugarCraft\Dashboard\Layout\Stack;
use SugarCraft\Dashboard\Layout\VStack;
use SugarCraft\Dashboard\Layout\Window;
use SugarCraft\Dashboard\Layout\ZStack;

/**
 * The zero-dims cure (finding sugar-dashboard-032): containers used to
 * push raw zeros down setSize for every channel they did not budget, and
 * framed children read that 0 as "unallocated" - abandoning their chrome
 * and rendering bare content inside the parent's layout math. The rule now:
 * an unbudgeted channel takes the child's MEASURED natural extent (Measurable
 * chrome-inclusive, SizedItem honest innerSize), never a lying zero.
 */
final class ZeroDimSizingTest extends TestCase
{
    private function strItem(string $text): Item
    {
        return new class($text) implements Item {
            public function __construct(private readonly string $text) {}
            public function render(): string
            {
                return $this->text;
            }
        };
    }

    // ─── Measurable contract ─────────────────────────────────────

    public function testFrameNaturalExtentIsChromeInclusive(): void
    {
        $frame = Frame::new($this->strItem('hi'));
        $this->assertInstanceOf(Measurable::class, $frame);

        // content 'hi' = 2 cells; + padding(1,1,1,1) + the 2 border columns.
        $this->assertSame([6, 5], $frame->naturalExtent(0));

        // A real width channel wins; the height stays natural.
        $this->assertSame([60, 5], $frame->naturalExtent(60));
    }

    public function testWindowNaturalExtentCountsTitleRowAndChrome(): void
    {
        $window = Window::new($this->strItem('one line'), 'T');
        $this->assertInstanceOf(Measurable::class, $window);

        [$w, $h] = $window->naturalExtent(0);
        $this->assertSame(12, $w, '8 content + 2 pad + 2 border');
        $this->assertSame(6, $h, '1 content + 1 title + 2 border + 2 pad');
        $this->assertSame([60, 6], $window->naturalExtent(60));
    }

    public function testPadNaturalExtentCountsTheRing(): void
    {
        $pad = Pad::new($this->strItem('abcd'))->withPadding(2);
        $this->assertInstanceOf(Measurable::class, $pad);
        $this->assertSame([8, 5], $pad->naturalExtent(0));
    }

    // ─── Containers no longer send lying zeros ───────────────────

    public function testStackKeepsFramedChildChromeWhenUnallocated(): void
    {
        $stack = Stack::new(Frame::new($this->strItem('body')));
        $this->assertStringContainsString('╭', $stack->render());
    }

    public function testStackKeepsFramedChildChromeInsideWidthAllocation(): void
    {
        $stack = Stack::new(Frame::new($this->strItem('body')))->setSize(40, 12);
        $rendered = $stack->render();

        $this->assertStringContainsString('╭', $rendered);
        foreach (explode("\n", $rendered) as $line) {
            $this->assertLessThanOrEqual(40, Width::string($line));
        }
    }

    public function testVStackKeepsFramedChildChrome(): void
    {
        $vstack = (new VStack())->withItems([Frame::new($this->strItem('body'))]);
        $this->assertStringContainsString('╭', $vstack->render());
    }

    public function testZStackKeepsFramedChildChrome(): void
    {
        $zstack = (new ZStack())->withItems([Frame::new($this->strItem('body'))]);
        $this->assertStringContainsString('╭', $zstack->render());
    }

    public function testPanelBodyKeepsFramedChildChrome(): void
    {
        $panel = Panel::new(Frame::new($this->strItem('body')))->setSize(40, 15);
        $this->assertStringContainsString('╭', $panel->render());
    }

    // ─── Pad clamp: a ring too big for the box yields, never blanks ──

    public function testPadWithRingLargerThanAllocationKeepsContentAlive(): void
    {
        $pad = Pad::new($this->strItem('abcd'))->withPadding(2)->setSize(4, 4);
        $lines = explode("\n", $pad->render());

        // The ring survives in some shape: exactly the 4 allocated rows, not
        // the old single raw line that threw the whole pad away.
        $this->assertCount(4, $lines, 'degenerate pad keeps its allocation rows: ' . json_encode($lines));
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(4, Width::string($line), 'line escapes the 4-cell allocation: ' . $line);
        }
        $this->assertStringContainsString('a', implode("\n", $lines), 'the degenerate pad must not blank the content');
    }

    public function testPadRoomyAllocationStillRendersThroughTheStylePath(): void
    {
        $pad = Pad::new($this->strItem('abcd'))->withPadding(2)->setSize(10, 10);
        $rendered = $pad->render();

        $this->assertStringContainsString('abcd', $rendered);
        foreach (explode("\n", $rendered) as $line) {
            $this->assertLessThanOrEqual(10, Width::string($line));
        }
    }

    // ─── Untouched contracts (the cure must not over-correct) ─────

    public function testDirectlyUnallocatedFramesStillRenderBareContent(): void
    {
        // The raw-passthrough leg stays: no allocation means no chrome -
        // the CONTAINER is what now supplies a truthful size (finding 032
        // ruled the zero, not the fallback).
        $this->assertSame('body', Frame::new($this->strItem('body'))->render());
        $this->assertSame('body', Frame::new($this->strItem('body'))->setSize(0, 0)->render());
    }

    public function testSizedItemWithoutNaturalAnswerStillGetsTheOldZeros(): void
    {
        $raw = new class implements Sizer {
            public function setSize(int $width, int $height): Sizer
            {
                return $this;
            }
            public function render(): string
            {
                return 'plain';
            }
        };

        // No Measurable, no innerSize signal: unchanged pass-through bytes.
        $this->assertStringContainsString('plain', Stack::new($raw)->render());
    }

    public function testStackedGridExpandWithoutLeftoverHeightKeepsFramedChildChrome(): void
    {
        // Height 2 fully eaten by the fixed neighbour: the expanding framed
        // child's share is 0. The guard leaves it at its natural extent for
        // measurement; D-37 then caps the exit at the allocation, so the
        // frame's chrome needs actual room - no rows strand past it.
        $grid = (new StackedGrid())
            ->withItem($this->strItem("a\nb"))
            ->withItem(Frame::new($this->strItem('exp')), new ItemOptions(column: 0, expandVertical: true))
            ->setSize(80, 2);

        $this->assertCount(2, explode("\n", $grid->render()));

        $roomy = $grid->setSize(80, 8);
        $this->assertStringContainsString('╭', $roomy->render());
        $this->assertCount(8, explode("\n", $roomy->render()));
    }
}
