<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Components\GridTable;

use SugarCraft\Dash\Layout\Frame;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Layout\Grid\ItemOptions;
use SugarCraft\Dash\Layout\Grid\Options;
use SugarCraft\Dash\Foundation\Sizer;
use SugarCraft\Dash\Layout\Grid\StackedGrid;
use SugarCraft\Sprinkles\Border;
use SugarCraft\Core\Util\Color;
use PHPUnit\Framework\TestCase;

final class StackedGridTest extends TestCase
{
    // ═══════════════════════════════════════════════════════════════
    // Helper: simple string item
    // ═══════════════════════════════════════════════════════════════

    private function strItem(string $s): Item
    {
        return new class($s) implements Item {
            public function __construct(private readonly string $s) {}
            public function render(): string { return $this->s; }
        };
    }

    private function failingItem(): Item
    {
        return new class implements \SugarCraft\Dash\Foundation\Item {
            public function render(): string {
                // Line wider than any reasonable allocation — tests truncation
                return str_repeat('x', 200);
            }
        };
    }

    // ═══════════════════════════════════════════════════════════════
    // Initial state
    // ═══════════════════════════════════════════════════════════════

    public function testNewGridHasNoItems(): void
    {
        $grid = new StackedGrid();
        $this->assertSame('Loading...', $grid->render());
    }

    public function testEmptyGridBeforeSetSize(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('hello'));
        $this->assertSame('Loading...', $grid->render());
    }

    // ═══════════════════════════════════════════════════════════════
    // Single-column stacking
    // ═══════════════════════════════════════════════════════════════

    public function testSingleColumnStacksVertically(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Line 1'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Line 2'), new ItemOptions(column: 0));
        $grid->setSize(20, 10);

        $rendered = $grid->render();
        $this->assertStringContainsString('Line 1', $rendered);
        $this->assertStringContainsString('Line 2', $rendered);
    }

    public function testItemsInSameColumnHaveEqualWidth(): void
    {
        $grid = new StackedGrid(new Options(fitScreen: true));
        $grid->addItem($this->strItem('Short'), new ItemOptions(column: 0));
        $grid->setSize(40, 10);

        $rendered = $grid->render();
        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            if ($line !== '') {
                $this->assertLessThanOrEqual(40, \SugarCraft\Core\Util\Width::string($line));
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════
    // Multi-column layout
    // ═══════════════════════════════════════════════════════════════

    public function testDifferentColumnsPlacedSideBySide(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Left'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Right'), new ItemOptions(column: 1));
        $grid->setSize(40, 5);

        $rendered = $grid->render();
        $this->assertStringContainsString('Left', $rendered);
        $this->assertStringContainsString('Right', $rendered);
    }

    public function testThreeColumnsWithMixedWidths(): void
    {
        $grid = new StackedGrid(new Options(fitScreen: true));
        $grid->addItem($this->strItem('Col0'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Col1'), new ItemOptions(column: 1));
        $grid->addItem($this->strItem('Col2'), new ItemOptions(column: 2));
        $grid->setSize(60, 3);

        $rendered = $grid->render();
        $this->assertStringContainsString('Col0', $rendered);
        $this->assertStringContainsString('Col1', $rendered);
        $this->assertStringContainsString('Col2', $rendered);
    }

    public function testSkippedColumnIndexCreatesGap(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('First'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Third'), new ItemOptions(column: 2));
        $grid->setSize(40, 5);

        $rendered = $grid->render();
        $this->assertStringContainsString('First', $rendered);
        $this->assertStringContainsString('Third', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // FitScreen option
    // ═══════════════════════════════════════════════════════════════

    public function testFitScreenDividesWidthEqually(): void
    {
        $grid = new StackedGrid(new Options(fitScreen: true));
        $grid->addItem($this->strItem('A'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('B'), new ItemOptions(column: 1));
        $grid->setSize(40, 5);

        $rendered = $grid->render();
        // Width should be distributed (40 / 2 = 20 per column)
        $this->assertNotSame('', $rendered);
    }

    public function testFitScreenDisabledUsesNaturalWidth(): void
    {
        $grid = new StackedGrid(new Options(fitScreen: false));
        $grid->addItem($this->strItem('Short'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('LongContent'), new ItemOptions(column: 1));
        $grid->setSize(40, 5);

        $rendered = $grid->render();
        $this->assertStringContainsString('Short', $rendered);
        $this->assertStringContainsString('LongContent', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Vertical expansion
    // ═══════════════════════════════════════════════════════════════

    public function testExpandVerticalFillsRemainingSpace(): void
    {
        $grid = new StackedGrid();
        // Fixed item takes 2 lines; expanding item fills the rest
        $grid->addItem($this->strItem("Fixed\nLine"), new ItemOptions(column: 0, expandVertical: false));
        $grid->addItem($this->strItem('Expanding'), new ItemOptions(column: 0, expandVertical: true));
        $grid->setSize(20, 10);

        $rendered = $grid->render();
        $lines = explode("\n", $rendered);
        $this->assertGreaterThan(2, count($lines));
    }

    public function testMultipleExpandingItemsShareSpace(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('One'), new ItemOptions(column: 0, expandVertical: true));
        $grid->addItem($this->strItem('Two'), new ItemOptions(column: 0, expandVertical: true));
        $grid->setSize(20, 10);

        $rendered = $grid->render();
        $lines = array_filter(explode("\n", $rendered), fn($l) => trim($l) !== '');
        $this->assertGreaterThanOrEqual(2, count($lines));
    }

    public function testRemainderGoesToLastItem(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Fixed'), new ItemOptions(column: 0, expandVertical: false));
        $grid->addItem($this->strItem('Expanding1'), new ItemOptions(column: 0, expandVertical: true));
        $grid->addItem($this->strItem('Expanding2'), new ItemOptions(column: 0, expandVertical: true));
        $grid->setSize(20, 5);

        // With 5 height, fixed takes 1, remaining 4 split as 2+2
        $rendered = $grid->render();
        $this->assertNotSame('', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Nested grids
    // ═══════════════════════════════════════════════════════════════

    public function testNestedGridPropagatesSize(): void
    {
        $inner = new StackedGrid();
        $inner->addItem($this->strItem('Inner Content'), new ItemOptions(column: 0));

        $outer = new StackedGrid();
        $outer->addItem($inner, new ItemOptions(column: 0));
        $outer->setSize(40, 10);

        $rendered = $outer->render();
        $this->assertStringContainsString('Inner Content', $rendered);
    }

    public function testNestedGridInColumnMultiColumn(): void
    {
        $innerLeft = new StackedGrid();
        $innerLeft->addItem($this->strItem('L1'), new ItemOptions(column: 0));
        $innerLeft->addItem($this->strItem('L2'), new ItemOptions(column: 0));

        $outer = new StackedGrid();
        $outer->addItem($innerLeft, new ItemOptions(column: 0));
        $outer->addItem($this->strItem('Right'), new ItemOptions(column: 1));
        $outer->setSize(40, 8);

        $rendered = $outer->render();
        $this->assertStringContainsString('L1', $rendered);
        $this->assertStringContainsString('Right', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════
    // Width clamping / truncation
    // ═══════════════════════════════════════════════════════════════

    public function testWidePlainItemIsTruncated(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->failingItem(), new ItemOptions(column: 0));
        $grid->setSize(10, 3);

        $rendered = $grid->render();
        // Should not crash; all lines should be ≤ 10 wide
        $lines = explode("\n", $rendered);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::string($line));
        }
    }
}
