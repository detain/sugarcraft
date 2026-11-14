<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Layout;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dashboard\Foundation\Item;
use SugarCraft\Dashboard\Foundation\Sizer;
use SugarCraft\Dashboard\Layout\Frame;
use SugarCraft\Dashboard\Layout\Grid\ItemOptions;
use SugarCraft\Dashboard\Layout\Grid\StackedGrid;
use SugarCraft\Dashboard\Layout\Layout;
use SugarCraft\Dashboard\Layout\LayoutItem;
use SugarCraft\Dashboard\Layout\Panel;
use SugarCraft\Dashboard\Layout\Screen;
use SugarCraft\Dashboard\Layout\Sidebar;
use SugarCraft\Dashboard\Layout\Split;
use SugarCraft\Dashboard\Layout\Stack;
use SugarCraft\Dashboard\Layout\StackLayout;
use SugarCraft\Dashboard\Layout\VStack;
use SugarCraft\Dashboard\Layout\Window;
use SugarCraft\Dashboard\Layout\ZStack;

/**
 * Finding sugar-dashboard-030: a render exit that never sliced to its
 * allocation made a tall child push the frame past its rows - the terminal
 * answered by scrolling instead of clipping. Every layout render exit now
 * passes through Layout::clipToHeight; one pin per class: tall content at a
 * small height prints EXACTLY the allocated rows.
 */
final class ClipToHeightTest extends TestCase
{
    private const HEIGHT = 4;

    private function tall(int $rows = 10): Sizer
    {
        $text = implode("\n", array_map(
            static fn (int $i): string => 'row-' . $i,
            range(1, $rows)
        ));
        return new class($text) implements Sizer {
            public function __construct(private readonly string $text) {}
            public function setSize(int $width, int $height): Sizer
            {
                return $this;
            }
            public function render(): string
            {
                return $this->text;
            }
        };
    }

    // ─── Helper contract ─────────────────────────────────────────

    public function testHelperIsPassThroughForZeroHeightEmptyAndShortBlocks(): void
    {
        $this->assertSame('', Layout::clipToHeight('', 5));
        $this->assertSame("a\nb\nc", Layout::clipToHeight("a\nb\nc", 0));
        $this->assertSame("a\nb\nc", Layout::clipToHeight("a\nb\nc", 3));
        $this->assertSame("a\nb", Layout::clipToHeight("a\nb\nc", 2));
    }

    // ─── One pin per render exit ─────────────────────────────────

    public function testStackPrintsExactlyItsRows(): void
    {
        $rendered = Stack::new($this->tall())->setSize(20, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testVStackPrintsExactlyItsRows(): void
    {
        $rendered = (new VStack())->withItems([$this->tall()])->setSize(20, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testZStackPrintsExactlyItsRows(): void
    {
        $rendered = (new ZStack())->withItems([$this->tall()])->setSize(20, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testStackLayoutVerticalPrintsExactlyItsRows(): void
    {
        $rendered = StackLayout::vertical($this->tall())->setSize(20, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testStackLayoutHorizontalPrintsExactlyItsRows(): void
    {
        $rendered = StackLayout::horizontal($this->tall(), $this->tall(2))->setSize(30, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testLayoutVerticalPrintsExactlyItsRows(): void
    {
        $rendered = Layout::vertical([new LayoutItem($this->tall())])->setSize(20, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testLayoutHorizontalPrintsExactlyItsRows(): void
    {
        $rendered = Layout::horizontal([new LayoutItem($this->tall())])->setSize(30, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testSidebarPrintsExactlyItsRows(): void
    {
        $items = array_map(static fn (int $i): array => ['label' => 'row-' . $i], range(1, 12));
        $rendered = Sidebar::new($items)->setSize(20, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testSplitHorizontalPrintsExactlyItsRows(): void
    {
        $rendered = Split::horizontal([$this->tall(), $this->tall()])->setSize(40, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testSplitVerticalPrintsExactlyItsRows(): void
    {
        $rendered = Split::vertical([$this->tall(), $this->tall()])->setSize(30, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testScreenPrintsExactlyItsRowsWithoutScrolling(): void
    {
        $rendered = Screen::new($this->tall())->setSize(20, self::HEIGHT)->render();
        // The cursor preamble rides the FIRST line (no leading newline), so
        // the row count is the content's own: the terminal never scrolls.
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testPanelPrintsExactlyItsRows(): void
    {
        $rendered = Panel::new($this->tall())->setSize(30, self::HEIGHT)->render();
        $this->assertCount(self::HEIGHT, explode("\n", $rendered));
    }

    public function testFramePrintsExactlyItsRows(): void
    {
        $rendered = Frame::new($this->tall())->setSize(20, 6)->render();
        $this->assertCount(6, explode("\n", $rendered));
    }

    public function testWindowPrintsExactlyItsRows(): void
    {
        $rendered = Window::new($this->tall())->setSize(20, 8)->render();
        $this->assertCount(8, explode("\n", $rendered));
    }

    public function testStackedGridPrintsExactlyItsRows(): void
    {
        $grid = (new StackedGrid())
            ->withItem($this->tall(), new ItemOptions(column: 0))
            ->setSize(80, self::HEIGHT);
        $this->assertCount(self::HEIGHT, explode("\n", $grid->render()));
    }
}
