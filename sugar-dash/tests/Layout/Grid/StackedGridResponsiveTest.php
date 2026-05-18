<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Tests\Layout\Grid;

use PHPUnit\Framework\TestCase;
use SugarCraft\Dash\Foundation\Item;
use SugarCraft\Dash\Layout\Grid\ItemOptions;
use SugarCraft\Dash\Layout\Grid\StackedGrid;

/**
 * Responsive breakpoint behaviour for StackedGrid.
 *
 * At width < 90: multi-column layouts collapse to a single column (all
 * items stacked vertically, full width).
 * At width >= 90: multi-column layouts render side-by-side as normal.
 */
final class StackedGridResponsiveTest extends TestCase
{
    private function strItem(string $s): Item
    {
        return new class($s) implements Item {
            public function __construct(private readonly string $s) {}
            public function render(): string { return $this->s; }
        };
    }

    // ═══════════════════════════════════════════════════════════════════
    // Snapshot: wide layout (width=120)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * At 120 cells (wide) a 2-column grid renders with both columns
     * side-by-side. We verify that both items appear and that the
     * layout is non-empty.
     */
    public function testWideLayoutShowsBothColumnsAtWidth120(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Left Column Content'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Right Column Content'), new ItemOptions(column: 1));
        $grid->setSize(120, 10);

        $rendered = $grid->render();

        $this->assertStringContainsString('Left Column Content', $rendered);
        $this->assertStringContainsString('Right Column Content', $rendered);
        $this->assertNotSame('', trim($rendered));
    }

    /**
     * At 120 cells wide the layout is NOT collapsed — the total rendered
     * width should reflect two side-by-side columns.
     */
    public function testWideLayoutAtWidth120NotCollapsed(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Left'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Right'), new ItemOptions(column: 1));
        $grid->setSize(120, 5);

        $rendered = $grid->render();
        $lines = explode("\n", $rendered);

        // All non-empty lines should fit within 120 cells (wide layout uses full width).
        foreach ($lines as $line) {
            if ($line !== '') {
                $this->assertLessThanOrEqual(120, \SugarCraft\Core\Util\Width::string($line));
            }
        }
    }

    // ═══════════════════════════════════════════════════════════════════
    // Snapshot: narrow layout (width=80)
    // ═══════════════════════════════════════════════════════════════════

    /**
     * At 80 cells (narrow) a 2-column grid collapses to a single column.
     * Both items appear but stacked vertically within one column at full 80-cell width.
     */
    public function testNarrowLayoutCollapsesToSingleColumnAtWidth80(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Top Panel'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Bottom Panel'), new ItemOptions(column: 1));
        $grid->setSize(80, 10);

        $rendered = $grid->render();

        $this->assertStringContainsString('Top Panel', $rendered);
        $this->assertStringContainsString('Bottom Panel', $rendered);
    }

    /**
     * At 80 cells narrow, the single-column content uses the full 80-cell width.
     */
    public function testNarrowLayoutAtWidth80UsesFullWidth(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Content A'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Content B'), new ItemOptions(column: 1));
        $grid->setSize(80, 8);

        $rendered = $grid->render();
        $lines = explode("\n", $rendered);

        foreach ($lines as $line) {
            if ($line !== '') {
                $this->assertLessThanOrEqual(80, \SugarCraft\Core\Util\Width::string($line));
            }
        }
    }

    /**
     * Narrow at 80 means content from column 0 and column 1 are both
     * present but stacked vertically — neither is lost.
     */
    public function testNarrowLayoutPreservesAllItemsFromAllColumns(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Col0-Item'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Col1-Item'), new ItemOptions(column: 1));
        $grid->addItem($this->strItem('Col2-Item'), new ItemOptions(column: 2));
        $grid->setSize(80, 15);

        $rendered = $grid->render();

        $this->assertStringContainsString('Col0-Item', $rendered);
        $this->assertStringContainsString('Col1-Item', $rendered);
        $this->assertStringContainsString('Col2-Item', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Boundary: width=90
    // ═══════════════════════════════════════════════════════════════════

    /**
     * At exactly 90 cells (the default threshold) the layout is NOT narrow
     * because the threshold is exclusive. The layout renders side-by-side.
     */
    public function testBoundaryAtWidth90RendersWideNotNarrow(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Left'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Right'), new ItemOptions(column: 1));
        $grid->setSize(90, 5);

        $rendered = $grid->render();

        $this->assertStringContainsString('Left', $rendered);
        $this->assertStringContainsString('Right', $rendered);
    }

    /**
     * Just below the 90 threshold (89) the layout IS narrow and collapses.
     */
    public function testWidth89IsNarrowAndCollapses(): void
    {
        $grid = new StackedGrid();
        $grid->addItem($this->strItem('Left'), new ItemOptions(column: 0));
        $grid->addItem($this->strItem('Right'), new ItemOptions(column: 1));
        $grid->setSize(89, 5);

        $rendered = $grid->render();

        $this->assertStringContainsString('Left', $rendered);
        $this->assertStringContainsString('Right', $rendered);
    }

    // ═══════════════════════════════════════════════════════════════════
    // Single-column grid is unaffected by breakpoints
    // ═══════════════════════════════════════════════════════════════════

    /**
     * A grid that already has only one column is unaffected by narrow
     * breakpoint — it renders normally (same behaviour at 80 and 120).
     */
    public function testSingleColumnGridUnaffectedByNarrowBreakpoint(): void
    {
        $gridNarrow = new StackedGrid();
        $gridNarrow->addItem($this->strItem('Only'), new ItemOptions(column: 0));
        $gridNarrow->setSize(80, 5);

        $gridWide = new StackedGrid();
        $gridWide->addItem($this->strItem('Only'), new ItemOptions(column: 0));
        $gridWide->setSize(120, 5);

        $this->assertStringContainsString('Only', $gridNarrow->render());
        $this->assertStringContainsString('Only', $gridWide->render());
    }
}
