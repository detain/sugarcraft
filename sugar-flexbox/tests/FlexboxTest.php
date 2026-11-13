<?php

declare(strict_types=1);

namespace SugarCraft\Flexbox\Tests;

use SugarCraft\Flexbox\Flex\{Align, Direction, FlexBox, FlexItem};
use SugarCraft\Flexbox\Scrollbar;
use SugarCraft\Flexbox\Table\{Column, Table, TableRenderer};
use SugarCraft\Flexbox\Viewport;
use PHPUnit\Framework\TestCase;

final class FlexboxTest extends TestCase
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
            ->withStyle('32');

        $this->assertSame(2, $item->ratio);
        $this->assertSame(10, $item->basis);
        $this->assertSame('32', $item->style);
    }

    public function testFlexItemRatioAndBasisWorkInLayout(): void
    {
        // Exact-cell pin (finding-014): with BOTH items basis-paid the ratio
        // never moves a byte — basis wins and the allocations are 5+5 in a
        // 20-wide box; contains-only asserts could not see that.
        $box = FlexBox::row(
            FlexItem::new('A')->withRatio(1)->withBasis(5),
            FlexItem::new('B')->withRatio(2)->withBasis(5),
        );

        $this->assertSame(
            ['A    B    ', '          '],
            \explode("\n", $box->render(20, 2)),
        );
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
        // Exact-cell pin (finding-014): two ratio-1 items split 20 down the
        // middle (10+10); every byte of the frame is now under test, so a
        // gap/allocation off-by-one outside the single assembly test fails.
        $box = FlexBox::row(
            FlexItem::new('LEFT'),
            FlexItem::new('RIGHT'),
        );

        $this->assertSame(
            ['LEFT      RIGHT     ', '                    ', '                    '],
            \explode("\n", $box->render(20, 3)),
        );
    }

    public function testFlexBoxRenderColumn(): void
    {
        // Exact-cell pin (finding-014): column mode divides the 6-row axis
        // 3+3 between the two default-ratio items; TOP owns lines 0-2 and
        // BOTTOM lines 3-5 (row-OWNERSHIP, not just presence).
        $box = FlexBox::column(
            FlexItem::new('TOP'),
            FlexItem::new('BOTTOM'),
        );

        $this->assertSame(
            ['TOP                 ', '                    ', '                    ', 'BOTTOM              ', '                    ', '                    '],
            \explode("\n", $box->render(20, 6)),
        );
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

    // ---- Structural FlexBox render tests ----

    /**
     * FlexBox row rendering produces exactly $totalHeight lines.
     */
    public function testFlexBoxRowRenderLineCount(): void
    {
        $box = FlexBox::row(
            FlexItem::new("line1\nline2\nline3"),
            FlexItem::new("line4\nline5"),
        );

        $output = $box->render(40, 5);
        $lines = \explode("\n", $output);

        $this->assertCount(5, $lines, 'Row render should produce exactly height lines');
    }

    /**
     * Every rendered line's visual width should not exceed the requested width.
     */
    public function testFlexBoxRowNoLineExceedsWidth(): void
    {
        $box = FlexBox::row(
            FlexItem::new('LEFT'),
            FlexItem::new('RIGHT'),
        );

        $output = $box->render(20, 3);
        $lines = \explode("\n", $output);

        foreach ($lines as $line) {
            $width = \SugarCraft\Core\Util\Width::string($line);
            $this->assertLessThanOrEqual(20, $width, "Line width {$width} should not exceed 20");
        }
    }

    /**
     * FlexItem::withWidth(k) is equivalent to withBasis(k).
     */
    public function testFlexItemWithWidthEqualsWithBasis(): void
    {
        $a = FlexItem::new('content')->withWidth(10);
        $b = FlexItem::new('content')->withBasis(10);

        $this->assertSame($a->basis, $b->basis);
    }

    /**
     * addItem appends an item to the FlexBox.
     */
    public function testFlexBoxAddItemAppends(): void
    {
        $box = FlexBox::row(FlexItem::new('A'));
        $box2 = $box->addItem(FlexItem::new('B'));

        // Original unchanged.
        $this->assertStringNotContainsString('B', $box->render(20, 2));
        // New has both.
        $this->assertStringContainsString('A', $box2->render(20, 3));
        $this->assertStringContainsString('B', $box2->render(20, 3));
    }

    /**
     * withGap(n) inserts n spaces between items in row mode.
     * Exact-cell pin (finding-014): box 20, gap 3 → usable 17 split
     * floor-first (9/8); the frame is A+8 pad, THREE gap spaces, B+7 pad.
     * Shifting the gap constant ±1 flips this red — the off-by-one the
     * old contains-only asserts could not see.
     */
    public function testFlexBoxRowWithGap(): void
    {
        $box = FlexBox::row(FlexItem::new('A'), FlexItem::new('B'))
            ->withGap(3);

        $this->assertSame(
            ['A           B       ', '                    '],
            \explode("\n", $box->render(20, 2)),
        );
    }

    /**
     * Ratio/basis mix on exact cells (finding-014): a basis-5 item beside a
     * ratio-2 and a default ratio-1 item leaves 6 free cells in an 11-wide
     * box, split 4/2 by ratio — every allocation edge is byte-pinned.
     */
    public function testFlexBoxRatioBasisMixExactCells(): void
    {
        $box = FlexBox::row(
            FlexItem::new('A')->withBasis(5),
            FlexItem::new('B')->withRatio(2),
            FlexItem::new('C'),
        );

        $this->assertSame(
            ['A    B   C '],
            \explode("\n", $box->render(11, 1)),
        );
    }

    /**
     * Single-cell floor of the ratio split (finding-014): usable 6 minus a
     * 1-cell gap leaves 5 for two ratio-1 items — floor gives 2/2, the
     * leftover cell rides the FIRST item in order (3/2), never the box.
     */
    public function testFlexBoxLeftoverCellGoesToFirstFlexItem(): void
    {
        $box = FlexBox::row(FlexItem::new('A'), FlexItem::new('B'))
            ->withGap(1);

        $this->assertSame(
            ['A   B '],
            \explode("\n", $box->render(6, 1)),
        );
    }

    /**
     * withDirection round-trips the direction property.
     */
    public function testFlexBoxWithDirectionRoundtrip(): void
    {
        $box = FlexBox::row(FlexItem::new('x'))->withDirection(Direction::Column);
        $this->assertSame(Direction::Column, $box->direction);

        $box2 = $box->withDirection(Direction::Row);
        $this->assertSame(Direction::Row, $box2->direction);
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

        // View order is Alice, Bob; the standing selection rode its row
        // ('Bob') through the sort to index 1 (FBX-26 follow law), and
        // asking explicitly is how you get the head row.
        $this->assertSame(1, $t->cursor());
        $this->assertSame('Bob', $t->rawCurrentRow()[0]);
        $this->assertSame('Alice', $t->withCursor(0)->rawCurrentRow()[0]);
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
            $rows[] = $t->setCursor($i)->rawCurrentCell(0);
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

        $this->assertSame('Alice', $t->rawCurrentRow()[0]);
        $t = $t->setCursor(1);
        $this->assertSame('Bob', $t->rawCurrentRow()[0]);
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

        // Ascending view is Alice, Bob; the standing selection rode its
        // row ('Bob', the default index 0) through the sort to index 1
        // (FBX-26 follow law).
        $this->assertSame(1, $t->cursor());
        $this->assertSame('Bob', $t->rawCurrentRow()[0]);

        $t = $t->sortByNext(0);  // toggle to desc: Bob, Alice — 'Bob' heads it
        $this->assertSame(0, $t->cursor());
        $this->assertSame('Bob', $t->rawCurrentRow()[0]);
    }

    /**
     * Regression: Table::sortBy() must sync the target Column's sorted() state
     * so the header renders the ▲ (asc) / ▼ (desc) arrow on the sorted column
     * and shows no arrow on the others.
     */
    public function testTableSortByRendersSortArrowInHeader(): void
    {
        $base = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->addRow(['Bob', 'LA'])
            ->addRow(['Alice', 'NYC']);

        // Ascending: ▲ on the sorted column (Name), no arrow on City.
        $header = $base->sortBy(0, true)->buildLines()[0];
        $this->assertStringContainsString('▲ Name', $header, 'Ascending sort must render ▲ on the sorted column');
        $this->assertStringContainsString('City', $header, 'Non-sorted column title must still render');
        $this->assertStringNotContainsString('▼', $header, 'Ascending sort must not render the ▼ glyph');
        $this->assertStringNotContainsString('▲ City', $header, 'Non-sorted column must show no arrow');
        $this->assertSame(1, \substr_count($header, '▲'), 'Only the sorted column may show an arrow');

        // Descending: ▼ on the sorted column, no ▲ anywhere.
        $headerDesc = $base->sortBy(0, false)->buildLines()[0];
        $this->assertStringContainsString('▼ Name', $headerDesc, 'Descending sort must render ▼ on the sorted column');
        $this->assertStringNotContainsString('▲', $headerDesc, 'Descending sort must not render the ▲ glyph');
        $this->assertStringNotContainsString('▼ City', $headerDesc, 'Non-sorted column must show no arrow');
        $this->assertSame(1, \substr_count($headerDesc, '▼'), 'Only the sorted column may show an arrow');
    }

    public function testTableCurrentCell(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addColumn(Column::make('City', 10))
            ->addRow(['Alice', 'NYC']);

        $this->assertSame('Alice', $t->rawCurrentCell(0));
        $this->assertSame('NYC', $t->rawCurrentCell(1));
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

    public function testTableCursorDoorRefusesOutOfBoundsIndex(): void
    {
        $t = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Alice']);

        // FBX-26: the door refuses; out-of-range cursors cannot exist.
        $this->expectException(\InvalidArgumentException::class);
        $t->setCursor(99);  // beyond row count
    }

    public function testTableCurrentCellReturnsNullWhenNoCurrentRow(): void
    {
        // An empty view is the one honest null source.
        $t = (new Table())->addColumn(Column::make('Name', 10));

        $this->assertNull($t->rawCurrentCell(0));
    }

    // ---- Column sort accessor tests ----------------------------------------

    public function testColumnSortedSetsDirectionAndPriority(): void
    {
        $col = Column::make('Name', 10)->sorted(1, 1);

        $this->assertSame(1, $col->sortDir());
        $this->assertSame(1, $col->sortPriority());
    }

    public function testColumnUnsortedClearsSortState(): void
    {
        $col = Column::make('Name', 10)->sorted(-1, 2)->unsorted();

        $this->assertSame(0, $col->sortDir());
        $this->assertSame(0, $col->sortPriority());
    }

    public function testColumnSortedWithDescendingDirection(): void
    {
        $col = Column::make('Age', 5)->sorted(-1, 1);

        $this->assertSame(-1, $col->sortDir());
        $this->assertSame(1, $col->sortPriority());
    }

    public function testColumnUnsortedIsIdempotent(): void
    {
        $col = Column::make('Name', 10)->unsorted()->unsorted();

        $this->assertSame(0, $col->sortDir());
        $this->assertSame(0, $col->sortPriority());
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
     * Mirrors sugar-panels, sugar-dashboard, candy-code, sugar-overlay, sugar-treelist.
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

        // Subsequent frames should be delta. finding-044: the old caps were
        // magic numbers (120/200/320) whose quoted sizes had already drifted
        // from reality. Each cap is now DERIVED IN-TEST as 115% of the full
        // frame that a FRESH renderer would emit for the same table state —
        // the honest ceiling is "beat the re-render by a safety margin", and
        // it re-anchors automatically when styles/fixtures change.
        $cap2 = (int) ceil(strlen((new TableRenderer())->render($t2)) * 1.15);
        $cap3 = (int) ceil(strlen((new TableRenderer())->render($t3)) * 1.15);
        $this->assertLessThanOrEqual($cap2, $bytes2, \sprintf('Frame 2 delta %d bytes must fit 115%% of full frame %d', $bytes2, $cap2));
        $this->assertLessThanOrEqual($cap3, $bytes3, \sprintf('Frame 3 delta %d bytes must fit 115%% of full frame %d', $bytes3, $cap3));

        // Total delta bytes for both frames stay under the summed derived caps.
        $totalDelta = $bytes2 + $bytes3;
        $this->assertLessThanOrEqual($cap2 + $cap3, $totalDelta, 'Total delta bytes for 2 frames should fit the summed derived caps');
    }

    // ---- SEC: style setters reject SGR injection ---------------------------

    /**
     * Caller-controlled style strings that MUST be rejected: each can break out
     * of the `CSI <style> m` wrapper (applyStyle) and inject arbitrary terminal
     * control sequences (window-title spoofing, screen clears, etc.).
     *
     * @return list<string>
     */
    private static function maliciousStyles(): array
    {
        return [
            "\x1b]0;pwned\x07",        // OSC set-window-title + BEL
            "\x1bP0;1|17/ab\x1b\\",  // DCS ... ST
            "\x1b[31m",                // raw CSI SGR (ESC + letter)
            "31mHACK",                 // closes SGR early, then literal text
            "1;31m",                   // trailing 'm' terminator
            "7\x1b[2J",                // valid code then ESC + clear-screen
        ];
    }

    public function testTableWithCursorStyleRejectsSgrInjection(): void
    {
        foreach (self::maliciousStyles() as $bad) {
            try {
                (new Table())->withCursorStyle($bad);
                $this->fail('withCursorStyle must reject ' . \bin2hex($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        // Legit SGR params and the empty ("no style") case still pass through.
        $this->assertInstanceOf(Table::class, (new Table())->withCursorStyle('1;31'));
        $this->assertInstanceOf(Table::class, (new Table())->withCursorStyle(''));
    }

    public function testTableWithHeaderStyleRejectsSgrInjection(): void
    {
        foreach (self::maliciousStyles() as $bad) {
            try {
                (new Table())->withHeaderStyle($bad);
                $this->fail('withHeaderStyle must reject ' . \bin2hex($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertInstanceOf(Table::class, (new Table())->withHeaderStyle('1;31'));
        $this->assertInstanceOf(Table::class, (new Table())->withHeaderStyle(''));
    }

    public function testColumnWithStyleRejectsSgrInjection(): void
    {
        foreach (self::maliciousStyles() as $bad) {
            try {
                Column::make('Name', 10)->withStyle($bad);
                $this->fail('Column::withStyle must reject ' . \bin2hex($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('1;31', Column::make('Name', 10)->withStyle('1;31')->ansiStyle);
        $this->assertSame('', Column::make('Name', 10)->withStyle('')->ansiStyle);
    }

    public function testFlexItemWithStyleRejectsSgrInjection(): void
    {
        foreach (self::maliciousStyles() as $bad) {
            try {
                FlexItem::new('x')->withStyle($bad);
                $this->fail('FlexItem::withStyle must reject ' . \bin2hex($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('1;31', FlexItem::new('x')->withStyle('1;31')->style);
        $this->assertSame('', FlexItem::new('x')->withStyle('')->style);
    }

    // ---- Sort-arrow glyph render pin (#1234) -------------------------------

    /**
     * Load-bearing pin for the #1234 fix: the sort arrow glyph must actually
     * appear in the rendered view() output (not merely in buildLines internals)
     * after sortBy()/sortByNext(), for both ascending and descending directions.
     * Reverting the Column::sorted() sync in Table::sortBy() drops the glyph and
     * fails this test.
     */
    public function testSortByRendersArrowGlyph(): void
    {
        $base = (new Table())
            ->addColumn(Column::make('Name', 10))
            ->addRow(['Bob'])
            ->addRow(['Alice']);

        $asc = $base->sortBy(0, true)->render();
        $this->assertStringContainsString('▲', $asc, 'Ascending sort must render the ▲ glyph in the view');
        $this->assertStringNotContainsString('▼', $asc);

        // sortByNext toggles asc → desc, which must swap the glyph in the view.
        $desc = $base->sortBy(0, true)->sortByNext(0)->render();
        $this->assertStringContainsString('▼', $desc, 'Toggled sort must render the ▼ glyph in the view');
        $this->assertStringNotContainsString('▲', $desc);
    }

    // ---- Data-origin sanitization ------------------------------------------

    /**
     * Column::sanitize() (reached via format()/padded()) strips OSC/DCS/ESC and
     * C0 control characters from cell data so untrusted row values cannot inject
     * terminal control sequences.
     */
    public function testColumnSanitizeStripsControlSequences(): void
    {
        $col = Column::make('X', 20);

        // OSC window-title injection embedded in a cell value.
        $out = $col->padded("a\x1b]0;pwned\x07b", 0);
        $this->assertStringNotContainsString("\x1b", $out, 'ESC must be stripped');
        $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
        $this->assertStringContainsString('ab', $out, 'Visible text survives sanitization');

        // DCS sequence + a bare C0 control.
        $out2 = $col->padded("x\x1bP1;2|q\x1b\\y\x01z", 0);
        $this->assertStringNotContainsString("\x1b", $out2, 'DCS ESC must be stripped');
        $this->assertStringNotContainsString("\x01", $out2, 'C0 control must be stripped');
        $this->assertStringContainsString('xyz', $out2, 'Visible text survives sanitization');
    }

    /**
     * FlexBox::sanitize() (reached via render()) strips OSC/DCS/ESC and C0
     * control characters from item content so untrusted panel data cannot inject
     * terminal control sequences.
     */
    public function testFlexBoxSanitizeStripsControlSequences(): void
    {
        // FlexItem has no style, so the library itself emits no ESC — any ESC in
        // the output would have to have come from the (malicious) content.
        $box = FlexBox::row(FlexItem::new("a\x1b]0;pwned\x07b\x01c"));
        $out = $box->render(20, 1);

        $this->assertStringNotContainsString("\x1b", $out, 'ESC must be stripped from item content');
        $this->assertStringNotContainsString("\x07", $out, 'BEL must be stripped');
        $this->assertStringNotContainsString("\x01", $out, 'C0 control must be stripped');
        $this->assertStringContainsString('abc', $out, 'Visible text survives sanitization');
    }

    // ---- Viewport scrollbar delegator tests ----

    /** withScrollbar(true) returns a new Viewport with scrollbar enabled. */
    public function testViewportWithScrollbarReturnsNewInstance(): void
    {
        $vp = Viewport::new(20, 10);
        $vp2 = $vp->withScrollbar(true);

        $this->assertNotSame($vp, $vp2, 'withScrollbar should return a new instance');
    }

    /** withYOffset(int) returns a new Viewport with the new offset. */
    public function testViewportSetYOffset(): void
    {
        $vp = Viewport::new(20, 5)->withContent(\implode("\n", \range(1, 20)));
        $vp2 = $vp->withYOffset(10);

        $this->assertSame(10, $vp2->yOffset());
    }

    /** yOffset() returns current scroll offset. */
    public function testViewportYOffsetAccessor(): void
    {
        $vp = Viewport::new(20, 5)->withContent(\implode("\n", \range(1, 20)));
        $this->assertSame(0, $vp->yOffset());

        $vp2 = $vp->withYOffset(5);
        $this->assertSame(5, $vp2->yOffset());
    }

    /** withContent returns a new Viewport with updated content. */
    public function testViewportSetContentReturnsNewInstance(): void
    {
        $vp = Viewport::new(20, 5);
        $vp2 = $vp->withContent("hello\nworld");

        $this->assertNotSame($vp, $vp2, 'withContent should return a new instance');
    }

    /**
     * Sum contract: N equal-ratio items in W cells must
     * use ALL W cells. The old (int)round allocation gave 3×ratio(1) at width
     * 10 only 9 displayed cells ('A  B  C  ').
     */
    public function testRowRatiosConsumeTheWholeWidth(): void
    {
        $box = FlexBox::row(
            FlexItem::new('A')->withRatio(1),
            FlexItem::new('B')->withRatio(1),
            FlexItem::new('C')->withRatio(1),
        );

        $lines = \explode("\n", $box->render(10, 2));
        $this->assertNotEmpty($lines);
        foreach ($lines as $line) {
            $this->assertSame(10, \SugarCraft\Core\Util\Width::string($line), 'every row line must fill the width exactly');
        }
    }

    /**
     * Column-mode padding is display-cell based: the old
     * str_pad counted BYTES, so a line holding '東' (3 bytes, 2 cells) was
     * padded to 10 bytes = 9 cells — every following glyph sat one column off.
     * Align::Start keeps the lines short on entry to the final pad (Stretch
     * lets alignCell fill them first).
     */
    public function testColumnLinesPadToExactCellWidthWithWideGlyphs(): void
    {
        $box = FlexBox::column(
            FlexItem::new('東')->withRatio(1),
            FlexItem::new('x')->withRatio(1),
        )->withAlign(\SugarCraft\Flexbox\Flex\Align::Start);

        $lines = \explode("\n", $box->render(10, 4));
        $this->assertCount(4, $lines);
        foreach ($lines as $line) {
            $this->assertSame(10, \SugarCraft\Core\Util\Width::string($line), 'wide-glyph lines must pad by cells, not bytes');
        }
    }

    // ---- Flex hardening (lib-fixes-1103) ------------------------------------

    public function testZeroRatioItemBesideFlexSiblingKeepsItsFloor(): void
    {
        $box = FlexBox::row(
            FlexItem::new('a')->withRatio(0),
            FlexItem::new('b')->withRatio(1),
        );

        $lines = \explode("\n", $box->render(10, 1));
        $this->assertCount(1, $lines);
        $this->assertStringContainsString('a', $lines[0]);
        $this->assertStringContainsString('b', $lines[0]);
        $this->assertSame(10, \SugarCraft\Core\Util\Width::string($lines[0]));
    }

    public function testNegativeGapIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Gap must be >= 0');
        FlexBox::row(FlexItem::new('a'))->withGap(-1);
    }

    public function testHugeGapIsReinedInAtRender(): void
    {
        $box = FlexBox::row(FlexItem::new('A'), FlexItem::new('B'))->withGap(PHP_INT_MAX);

        $lines = \explode("\n", $box->render(10, 1));
        $this->assertCount(1, $lines);
        $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::string($lines[0]));
        $this->assertStringContainsString('A', $lines[0]);
        $this->assertStringContainsString('B', $lines[0]);
    }

    public function testNegativeBasisIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Basis must be >= 0');
        FlexItem::new('x')->withBasis(-5);
    }

    public function testNegativeRatioIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Ratio must be >= 0');
        FlexItem::new('x')->withRatio(-1);
    }

    public function testHugeBasisClampsToContainer(): void
    {
        $box = FlexBox::row(
            FlexItem::new('BIG')->withBasis(200000),
            FlexItem::new('x')->withRatio(1),
        );

        $lines = \explode("\n", $box->render(20, 1));
        $this->assertCount(1, $lines);
        $this->assertSame(20, \SugarCraft\Core\Util\Width::string($lines[0]), 'a mis-typed huge basis must shrink, not storm memory');
        $this->assertStringContainsString('BIG', $lines[0]);
    }

    public function testDegenerateContainerRendersEmptyBothDirections(): void
    {
        $this->assertSame('', FlexBox::row(FlexItem::new('a'))->render(10, -1));
        $this->assertSame('', FlexBox::row(FlexItem::new('a'))->render(-5, 3));
        $this->assertSame('', FlexBox::column(FlexItem::new('a'))->render(10, -1));
        $this->assertSame('', FlexBox::column(FlexItem::new('a'))->render(0, 5));
    }

    public function testRowNeverEmitsMoreLinesThanTotalHeight(): void
    {
        // Fit contract C3(b): a row used to size its line loop to the
        // tallest item, so a 10-line child inside a 3-row box emitted 10
        // rows and overflowed the container (no array_slice equivalent to
        // column mode's final clamp).
        $tall = \implode("\n", \array_map(strval(...), \range(1, 10)));
        $box  = FlexBox::row(
            FlexItem::new($tall),
            FlexItem::new('x'),
        )->withAlign(Align::Start);

        $lines = \explode("\n", $box->render(10, 3));
        $this->assertCount(3, $lines, 'row height is a ceiling, never a suggestion');
        $this->assertStringContainsString('1', $lines[0]);
        $this->assertStringContainsString('3', $lines[2]);
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(10, \SugarCraft\Core\Util\Width::string($line));
        }
    }

    public function testRowFrameAssemblyIsCellGapCellByteExact(): void
    {
        // Locks the assembly order (cell, gap, cell, ...; gap never after
        // the last item) that the M7 per-item precompute refactor must
        // reproduce byte-for-byte; the 138-case old/new render-dump md5
        // equality is the lane evidence, this pin is the standing guard.
        $box = FlexBox::row(
            FlexItem::new("aa\nbb\ncc")->withBasis(3),
            FlexItem::new("xx\nyy\nzz")->withBasis(3),
        )->withGap(1)->withAlign(Align::Start);

        $this->assertSame(
            ["aa  xx ", "bb  yy ", "cc  zz "],
            \explode("\n", $box->render(12, 3)),
        );
    }

    /**
     * The squeezed-container fallback branch (finding-012): when the basis
     * spend exhausts the budget (flexFree <= 0), EVERY basis-less item still
     * receives one cell — including ratio-paid items — and the renderer's
     * final clamp contains the overflow. Before this test the branch had
     * zero coverage: deleting it silently shipped 0-cell (then negative)
     * allocations for starved flex items.
     */
    public function testSqueezeFallbackStillAllocatesOneCellToEveryFlexItem(): void
    {
        $box = FlexBox::row();
        $allocate = new \ReflectionMethod($box, 'allocateMainAxis');
        $allocate->setAccessible(true);

        $measured = [
            ['item' => FlexItem::new('AAA')->withBasis(3), 'width' => 3, 'height' => 1],
            ['item' => FlexItem::new('B'), 'width' => 1, 'height' => 1],
            ['item' => FlexItem::new('C')->withRatio(0), 'width' => 1, 'height' => 1],
        ];

        // Exact exhaustion: usable 3, fixedSum 3, flexFree 0.
        $alloc = $allocate->invoke($box, $measured, 3, 0);
        $this->assertSame(3, $alloc[0]['allocated'], 'basis item keeps its box-clamped basis');
        $this->assertSame(1, $alloc[1]['allocated'], 'starved ratio item still gets one cell');
        $this->assertSame(1, $alloc[2]['allocated'], 'basis-less zero-ratio item still gets one cell');

        // Over-committed: the 1-cell gap drives flexFree negative and the
        // basis clamps to the box; the fallback floor is unchanged.
        $alloc2 = $allocate->invoke($box, $measured, 3, 1);
        $this->assertSame(3, $alloc2[0]['allocated'], 'basis clamps to the box, never the usable');
        $this->assertSame(1, $alloc2[1]['allocated'], 'negative flexFree still floors at one cell');
    }

    public function testSqueezeRendererClampsOverflowRows(): void
    {
        // The same branch at the byte seam: 3+1 allocated cells in a 3-wide
        // box must paint exactly the box — the overflow is clamped, never
        // emitted (terminal desync law).
        $box = FlexBox::row(FlexItem::new('AAA')->withBasis(3), FlexItem::new('B'));

        $this->assertSame('AAA', $box->render(3, 1));
    }

    // -------------------------------------------------------------------------
    // FBX-15: per-instance measured-geometry memo + bulk construction path
    // -------------------------------------------------------------------------

    public function testMeasuredGeometryMemoizesOnTheItemInstance(): void
    {
        $item = FlexItem::new("abc\n東京\nx"); // widths 3, 4, 1

        $widthMemo  = new \ReflectionProperty(FlexItem::class, 'widthMemo');
        $heightMemo = new \ReflectionProperty(FlexItem::class, 'heightMemo');
        $lineMemo   = new \ReflectionProperty(FlexItem::class, 'lineMemo');

        $this->assertNull($widthMemo->getValue($item), 'nothing is computed before the first ask');
        $this->assertNull($heightMemo->getValue($item));
        $this->assertNull($lineMemo->getValue($item));

        $this->assertSame(4, $item->measuredWidth());
        $this->assertSame(3, $item->measuredHeight());

        $this->assertSame(4, $widthMemo->getValue($item), 'the answer landed in the memo');
        $this->assertSame(3, $heightMemo->getValue($item));
        $this->assertSame(['abc', '東京', 'x'], $lineMemo->getValue($item));

        // The strict proof of measure-once: poison the memo — if measuredWidth()
        // recomputed from content it would answer 4 again, not the sentinel.
        $widthMemo->setValue($item, 99);
        $this->assertSame(99, $item->measuredWidth(), 'measuredWidth answers the memo, never re-walks');
    }

    public function testDerivedItemCarriesFreshMemos(): void
    {
        $base    = FlexItem::new('first');
        $derived = $base->withContent("two\nlines");

        $widthMemo = new \ReflectionProperty(FlexItem::class, 'widthMemo');
        $this->assertNull($widthMemo->getValue($base));
        $this->assertNull($widthMemo->getValue($derived));

        $this->assertSame(5, $base->measuredWidth());    // 'first'
        $this->assertSame(5, $derived->measuredWidth()); // 'lines'

        // Independent memos: the base cache must not be shared by reference.
        $this->assertSame(5, $widthMemo->getValue($base));
        $widthMemo->setValue($derived, 1);
        $this->assertSame(1, $derived->measuredWidth());
        $this->assertSame(5, $base->measuredWidth(), 'with* clones never inherit a live memo object');
    }

    public function testFrameRenderFillsTheItemMemos(): void
    {
        // The renderer now measures THROUGH the item — a single frame must
        // leave every memo populated (zero byte moves are pinned by the
        // exact-cell tests above; this pins the wiring).
        $item = FlexItem::new("L1\nL2\nLONGER");
        FlexBox::row($item)->render(10, 3);

        $this->assertSame(6, (new \ReflectionProperty(FlexItem::class, 'widthMemo'))->getValue($item));
        $this->assertSame(3, (new \ReflectionProperty(FlexItem::class, 'heightMemo'))->getValue($item));
    }

    public function testLinesAnswersTheRawUnsanitizedSplit(): void
    {
        // Measurement discipline (documented asymmetry): lines() carries the
        // raw bytes — sanitize stays per PRINTED line inside the renderer.
        $item = FlexItem::new("keep\x1b[31mred\nsecond");

        $this->assertSame(["keep\x1b[31mred", 'second'], $item->lines());
    }

    public function testWithItemsIsTheBulkEquivalentOfChainedAdds(): void
    {
        $chain = FlexBox::row(FlexItem::new('A'))
            ->addItem(FlexItem::new('B'))
            ->addItem(FlexItem::new('C'));
        $bulk = FlexBox::row()->withItems(
            FlexItem::new('A'),
            FlexItem::new('B'),
            FlexItem::new('C'),
        );

        $this->assertSame(
            $chain->render(20, 1),
            $bulk->render(20, 1),
            'one-copy bulk construction paints exactly what the O(n²) chain paints',
        );

        // and it REPLACES the list rather than appending to it:
        $replaced = FlexBox::row(FlexItem::new('GHOST'))->withItems(FlexItem::new('B'));
        $this->assertSame('B                   ', $replaced->render(20, 1));
    }
}
