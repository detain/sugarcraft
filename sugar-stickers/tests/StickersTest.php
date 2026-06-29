<?php

declare(strict_types=1);

namespace SugarCraft\Stickers\Tests;

use SugarCraft\Stickers\Flex\{Align, Direction, FlexBox, FlexItem, Justify};
use SugarCraft\Stickers\Table\{Column, Table, TableRenderer};
use PHPUnit\Framework\TestCase;

final class StickersTest extends TestCase
{
    // ---- FlexBox tests ----

    public function testFlexItemDefaults(): void
    {
        $item = FlexItem::new('hello');
        $this->assertSame('hello', $item->content);
        $this->assertSame(1, $item->ratio);
        $this->assertSame(0, $item->basis);
    }

    public function testFlexItemWithMethods(): void
    {
        $item = FlexItem::new('x')
            ->withRatio(2)
            ->withBasis(10)
            ->withGrow(3)
            ->withShrink(0)
            ->withStyle('32');

        $this->assertSame(2, $item->ratio);
        $this->assertSame(10, $item->basis);
        $this->assertSame(3, $item->grow);
        $this->assertSame(0, $item->shrink);
        $this->assertSame('32', $item->style);
    }

    public function testFlexBoxRow(): void
    {
        $box = FlexBox::row(
            FlexItem::new('A'),
            FlexItem::new('B'),
        );

        $this->assertSame(Direction::Row, $box->direction);
        $this->assertSame(0, $box->gap);
        $box = $box->withGap(1);
        $this->assertSame(1, $box->gap);
    }

    public function testFlexBoxColumn(): void
    {
        $box = FlexBox::column(
            FlexItem::new('A'),
            FlexItem::new('B'),
        );

        $this->assertSame(Direction::Column, $box->direction);
    }

    public function testFlexBoxRenderRow(): void
    {
        $box = FlexBox::row(
            FlexItem::new('LEFT'),
            FlexItem::new('RIGHT'),
        );

        $result = $box->render(20, 3);
        $this->assertIsString($result);
        $this->assertStringContainsString('LEFT', $result);
        $this->assertStringContainsString('RIGHT', $result);
    }

    public function testFlexBoxRenderColumn(): void
    {
        $box = FlexBox::column(
            FlexItem::new('TOP'),
            FlexItem::new('BOTTOM'),
        );

        $result = $box->render(20, 6);
        $this->assertIsString($result);
        $this->assertStringContainsString('TOP', $result);
        $this->assertStringContainsString('BOTTOM', $result);
    }

    public function testFlexBoxWithJustify(): void
    {
        $box = FlexBox::row(FlexItem::new('x'))
            ->withJustify(Justify::Center);
        $this->assertSame(Justify::Center, $box->justify);
    }

    public function testFlexBoxWithAlign(): void
    {
        $box = FlexBox::row(FlexItem::new('x'))
            ->withAlign(Align::Center);
        $this->assertSame(Align::Center, $box->align);
    }

    public function testFlexBoxEmpty(): void
    {
        $box = FlexBox::row();
        $this->assertSame('', $box->render(80, 24));
    }

    public function testFlexItemImmutability(): void
    {
        $a = FlexItem::new('old');
        $b = $a->withContent('new');
        $this->assertSame('old', $a->content);
        $this->assertSame('new', $b->content);
    }

    // ---- Table tests ----

    public function testTableAddColumn(): void
    {
        $t = new Table();
        $t = $t->addColumn(Column::make('Name', 20));

        $this->assertSame(1, $t->colCount());
    }

    public function testTableAddRow(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('Age', 5))
            ->addRow(['Alice', '30'])
            ->addRow(['Bob', '25']);

        $this->assertSame(2, $t->rowCount());
    }

    public function testTableSortBy(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('Age', 5))
            ->addRow(['Bob', '25'])
            ->addRow(['Alice', '30'])
            ->sortBy(0, true);

        $this->assertSame('Alice', $t->currentRow()[0]);
    }

    public function testTableSortByNumeric(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Age', 5))
            ->addRow(['25'])
            ->addRow(['30'])
            ->addRow(['15'])
            ->sortBy(0, true);

        $rows = [];
        for ($i = 0; $i < $t->rowCount(); $i++) {
            $rows[] = $t->setCursor($i)->currentCell(0);
        }

        $this->assertSame('15', $rows[0]);
        $this->assertSame('25', $rows[1]);
        $this->assertSame('30', $rows[2]);
    }

    public function testTableFilter(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->addRow(['Carol'])
            ->filter('a');

        // Alice + Carol both contain 'a' (case-insensitive)
        $this->assertSame(2, $t->rowCount());
    }

    public function testTableClearFilter(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->filter('a')
            ->clearFilter();

        $this->assertSame(2, $t->rowCount());
    }

    public function testTableCursorNavigation(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->addRow(['Carol']);

        $this->assertSame('Alice', $t->currentRow()[0]);
        $t = $t->setCursor(1);
        $this->assertSame('Bob', $t->currentRow()[0]);
    }

    public function testTableRender(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob']);

        $result = $t->render();
        $this->assertIsString($result);
        $this->assertStringContainsString('Name', $result);
        $this->assertStringContainsString('Alice', $result);
        $this->assertStringContainsString('Bob', $result);
    }

    public function testTableColumnAlignRight(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Age', 5)->withAlign('right'))
            ->addRow(['30']);

        $result = $t->render();
        $this->assertIsString($result);
        $this->assertStringContainsString('  30', $result);
    }

    public function testTableSortToggle(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Bob'])
            ->addRow(['Alice'])
            ->sortBy(0, true);  // asc

        $this->assertSame('Alice', $t->currentRow()[0]);

        $t = $t->sortByNext(0);  // toggle to desc
        $this->assertSame('Bob', $t->currentRow()[0]);
    }

    public function testTableCurrentCell(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->addRow(['Alice', 'NYC']);

        $this->assertSame('Alice', $t->currentCell(0));
        $this->assertSame('NYC', $t->currentCell(1));
    }

    public function testTableWithCursorStyle(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->setCursor(0)
            ->withCursorStyle('7');  // reverse video

        $result = $t->render();
        $this->assertIsString($result);
        // ANSI escape sequence should be present when cursor style is applied
        $this->assertStringContainsString("\x1b[7m", $result);
        $this->assertStringContainsString('Alice', $result);
    }

    public function testTableWithHeaderStyle(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->withHeaderStyle('1');  // bold

        $result = $t->render();
        $this->assertIsString($result);
        // ANSI escape sequence should be present for bold header
        $this->assertStringContainsString("\x1b[1m", $result);
        $this->assertStringContainsString('Name', $result);
    }

    public function testTableWithSeparator(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5))
            ->addRow(['x', 'y'])
            ->withSeparator(' || ');

        $result = $t->render();
        $this->assertIsString($result);
        // Custom separator should appear in output between columns
        $this->assertStringContainsString(' || ', $result);
    }

    public function testTableCurrentRowReturnsNullWhenOutOfBounds(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->setCursor(99);  // beyond row count

        $this->assertNull($t->currentRow());
    }

    public function testTableCurrentCellReturnsNullWhenNoCurrentRow(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->setCursor(99);  // beyond row count

        $this->assertNull($t->currentCell(0));
    }

    public function testTableRenderEmptyWithoutColumns(): void
    {
        $t = new Table();
        $this->assertSame('', $t->render());
    }

    public function testTableImmutability(): void
    {
        $t1 = (new Table())
            ->addColumn(Column::make('A', 5))
            ->addColumn(Column::make('B', 5))
            ->addRow(['x', 'y']);
        $t2 = $t1->withSeparator(' | ');

        // Original should be unchanged - uses default separator
        $this->assertStringContainsString(' │ ', $t1->render());
        // New instance should use custom separator
        $this->assertStringContainsString(' | ', $t2->render());
    }

    // ---- Diff-emission byte benchmark ------------------------------------

    /**
     * Benchmark: diff-based TableRenderer::render() emits fewer bytes than full re-render
     * for small changes between consecutive frames.
     *
     * Mirrors sugar-boxer, sugar-dash, sugar-crush, sugar-veil, candy-lister.
     *
     * TableRenderer tracks diff state across frames. First render emits full output.
     * Subsequent renders with stable dimensions emit delta-encoded ops.
     * Table diff works only when dimensions (width×height) stay constant.
     * Adding/removing rows changes height and triggers full re-emit.
     * We use style changes (cursorStyle, headerStyle) which are dimension-stable.
     *
     * Frame 1: full output (baseline)
     * Frame 2: delta output (≤30 bytes for a style change)
     * Frame 3: delta output (≤30 bytes for another style change)
     * Total delta: ≤60 bytes for 2 delta frames (30×2)
     */
    public function testDiffEmissionByteBenchmark(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice'])
            ->addRow(['Bob'])
            ->addRow(['Carol']);

        $renderer = new TableRenderer();

        // Frame 1: full render via TableRenderer
        $out1 = $renderer->render($t);
        $bytes1 = \strlen($out1);

        // Frame 2: add cursor style (dimension-stable change)
        $t2 = $t->setCursor(1)->withCursorStyle('7');  // reverse video on row 1
        $out2 = $renderer->render($t2);
        $bytes2 = \strlen($out2);

        // Frame 3: change header style (dimension-stable change)
        $t3 = $t2->withHeaderStyle('1');  // bold header
        $out3 = $renderer->render($t3);
        $bytes3 = \strlen($out3);

        // First frame is full output (baseline)
        $this->assertGreaterThan(50, $bytes1, 'Frame 1 should be full output');

        // Subsequent frames should be delta (≤30 bytes per frame for small changes)
        $this->assertLessThanOrEqual(30, $bytes2, 'Frame 2 delta should be ≤30 bytes');
        $this->assertLessThanOrEqual(30, $bytes3, 'Frame 3 delta should be ≤30 bytes');

        // Total delta bytes for 2 frames should be ≤60 (30×2)
        $totalDelta = $bytes2 + $bytes3;
        $this->assertLessThanOrEqual(60, $totalDelta, 'Total delta bytes for 2 frames should be ≤60');
    }
}
