<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Layout\LayoutDirection;
use SugarCraft\Dashboard\Components\Card\Text;
use SugarCraft\Dashboard\Layout\Frame;
use SugarCraft\Dashboard\Layout\StackLayout;

/**
 * D-157 (finding 219): StackLayout had no direct suite; the two legs this
 * row fixed are the ones pinned here — the natural path's spacing must
 * agree with the vertical path's, and withSpacing clamps like its siblings.
 */
final class StackLayoutTest extends TestCase
{
    public function testNaturalSpacingMatchesVerticalSpacing(): void
    {
        $layout = new StackLayout([new Text("alpha"), new Text("beta")], 2, LayoutDirection::Vertical);

        $natural = explode("\n", $layout->render());
        $vertical = explode("\n", $layout->setSize(12, 6)->render());

        self::assertCount(4, $natural);          // 2 content rows + 2 blank rows
        self::assertSame('', trim($natural[1]));
        self::assertSame('', trim($natural[2]));
        self::assertStringContainsString('beta', $natural[3]);
        self::assertSame(trim($vertical[0]), $natural[0]);
        // Both paths must agree on where the second item lands.
        self::assertStringContainsString('beta', $vertical[3]);
    }

    public function testZeroSpacingKeepsItemsAdjacent(): void
    {
        $rendered = explode("\n", (new StackLayout([new Text("a"), new Text("b")], 0, LayoutDirection::Vertical))->render());

        self::assertCount(2, $rendered);
        self::assertSame('a', $rendered[0]);
        self::assertSame('b', $rendered[1]);
    }

    public function testNegativeSpacingIsClampedLikeTheSiblings(): void
    {
        $clamped = (new StackLayout([new Text("a"), new Text("b")], 0, LayoutDirection::Vertical))->withSpacing(-3);

        $zero = new StackLayout([new Text("a"), new Text("b")], 0, LayoutDirection::Vertical);

        self::assertSame($zero->render(), $clamped->render());
    }

    // ─── D-25 · finding sugar-dashboard-019: horizontal must SPLIT ────

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

    public function testHorizontalSplitsTheAllocationInsteadOfRepeatingIt(): void
    {
        $rendered = explode("\n", (new StackLayout(
            [$this->strItem('a'), $this->strItem('b'), $this->strItem('c')],
            0,
            LayoutDirection::Horizontal
        ))->setSize(60, 0)->render());

        // Old behavior: each child padded to the FULL 60 - the row came
        // back 180 wide, n times the allocation. Split mode: 3x20 == 60.
        self::assertNotEmpty($rendered);
        foreach ($rendered as $line) {
            self::assertSame(60, Width::string($line), 'horizontal row escapes the allocation: ' . json_encode($line));
        }
        self::assertStringStartsWith('a', $rendered[0]);
        self::assertStringContainsString('b', $rendered[0]);
        self::assertStringContainsString('c', $rendered[0]);
    }

    public function testHorizontalClipsAnOversizedChildToItsShare(): void
    {
        $wide = str_repeat('x', 30);
        $rendered = (new StackLayout(
            [$this->strItem($wide), $this->strItem('b'), $this->strItem('c')],
            0,
            LayoutDirection::Horizontal
        ))->setSize(60, 0)->render();

        foreach (explode("\n", $rendered) as $line) {
            self::assertSame(60, Width::string($line));
        }
        // 30 x's do not survive into a 20-cell share; they stop at the
        // boundary instead of pushing the neighbours out.
        self::assertStringNotContainsString(str_repeat('x', 21), $rendered);
    }

    public function testHorizontalSlackGoesToTheEarliestChildren(): void
    {
        $rendered = (new StackLayout(
            [$this->strItem('a'), $this->strItem('b'), $this->strItem('c')],
            0,
            LayoutDirection::Horizontal
        ))->setSize(10, 0)->render();

        foreach (explode("\n", $rendered) as $line) {
            self::assertSame(10, Width::string($line), 'budget 10 must land exactly, slack first');
        }
    }

    public function testHorizontalSpacingIsTakenOutOfTheBudget(): void
    {
        $rendered = (new StackLayout(
            [$this->strItem('a'), $this->strItem('b')],
            3,
            LayoutDirection::Horizontal
        ))->setSize(17, 0)->render();

        foreach (explode("\n", $rendered) as $line) {
            self::assertSame(17, Width::string($line), 'gap cells are budget cells, never extra: ' . json_encode($line));
        }
        // a at share 0, gap of 3 lands after it: 'a' then blanks until 'b'.
        self::assertStringStartsWith('a', explode("\n", $rendered)[0]);
    }

    public function testHorizontalHeightChannelDoesNotLieWithZero(): void
    {
        // D-32 family: an unallocated height channel used to send a raw 0
        // and framed children answered by dropping their chrome.
        $layout = (new StackLayout(
            [Frame::new($this->strItem('body'))],
            0,
            LayoutDirection::Horizontal
        ))->setSize(40, 0);

        $rendered = $layout->render();
        self::assertStringContainsString('╭', $rendered);
        foreach (explode("\n", $rendered) as $line) {
            self::assertLessThanOrEqual(40, Width::string($line));
        }
    }
}
