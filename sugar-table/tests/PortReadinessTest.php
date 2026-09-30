<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Table\{Column, ColumnWidth, Row, RowData, Table};

/**
 * Port-readiness pins for the audit fix wave (findings #1-#10): the exact
 * behaviours candy-query's ResultTable will rely on when it unifies into this
 * lib. One focused test per finding, each naming the finding it guards.
 */
final class PortReadinessTest extends TestCase
{
    /** @param list<string> $lines */
    private static function stripAnsi(array $lines): array
    {
        return \array_map(static fn (string $l): string => \preg_replace('/\e\[[0-9;]*m/', '', $l), $lines);
    }

    /** True when any SGR run in the line carries reverse (param token 7). */
    private static function hasReverse(string $line): bool
    {
        if (\preg_match_all('/\e\[([0-9;]*)m/', $line, $runs) === false) {
            return false;
        }
        foreach ($runs[1] as $params) {
            if (\in_array('7', \explode(';', $params), true)) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function viewLines(string $view): array
    {
        return self::stripAnsi(\explode("\n", $view));
    }

    private static function renderBuffer(Table $t): \SugarCraft\Buffer\Buffer
    {
        $m = new \ReflectionMethod($t, 'renderToBuffer');
        $m->setAccessible(true);
        /** @var \SugarCraft\Buffer\Buffer */
        return $m->invoke($t);
    }

    /** A plain 2-column fixture: ID (4) + Name (10), align-left. */
    private static function plainTable(int $rows): Table
    {
        $data = [];
        for ($i = 1; $i <= $rows; $i++) {
            $data[] = Row::new(RowData::from(['id' => (string) $i, 'name' => "n{$i}"]));
        }

        return Table::fromColumns([
            Column::new('id', 'ID', 4)->withAlignLeft(),
            Column::new('name', 'Name', 10)->withAlignLeft()->withFilterable(),
        ])->withRows($data);
    }

    // ---- finding #1 (HIGH): append after render must invalidate the caches --

    public function testAddRowAfterRenderBecomesVisible(): void
    {
        $t = self::plainTable(2);
        $before = $t->View();
        $this->assertStringNotContainsString('n3', $before);

        $grown = $t->addRow(Row::new(RowData::from(['id' => '3', 'name' => 'n3'])));
        $after = $grown->View();

        $this->assertSame(3, $grown->TotalRows());
        $this->assertStringContainsString('n3', $after);
    }

    public function testAddRowsAfterRenderRegrowsContentWidth(): void
    {
        $t = Table::fromColumns([
            Column::new('c', 'Content', 0)->withColumnWidth(ColumnWidth::Content)->withAlignLeft(),
        ])->withRows([Row::new(RowData::from(['c' => 'hi']))]);

        $short = self::viewLines($t->View());
        $wide = $t->addRows([Row::new(RowData::from(['c' => 'a much longer value']))])->View();

        $longLine = self::viewLines($wide)[0];
        $this->assertGreaterThan(
            Width::of($short[0]),
            Width::of($longLine),
            'stale widthSolveCache would pin the table to the pre-append content width'
        );
        $this->assertStringContainsString('much longer', $wide);
    }

    // ---- finding #2 (MAJOR): styled multiline must never embed raw SGR ------

    public function testStyledMultilineViewCarriesOnlyWellFormedSgr(): void
    {
        $t = Table::fromColumns([
            Column::new('d', 'Desc', 6)->withAlignLeft()->withStyle('1;31')
                ->withWrapMode(\SugarCraft\Table\WrapMode::WordWrap),
        ])->withRows([
            Row::new(RowData::from(['d' => 'Reddish wrapping value'])),
        ])
            ->withMultilineMode(true)
            ->withSelectable(false);

        $view = $t->View();

        // Every ESC byte must be the head of a complete SGR sequence: the
        // count of ESCs equals the count of SGR openers, so no ESC was ever
        // laid into the grid as literal cell content (audit #2: the old code
        // embedded raw SGR in the string, then painted those ESCs as cells).
        $escCount = \substr_count($view, "\x1b");
        $sgrCount = \preg_match_all('/\e\[[0-9;]*m/', $view);
        $this->assertSame($sgrCount, $escCount, 'orphan ESC byte: raw SGR leaked into cell content');
        $this->assertGreaterThan(0, $sgrCount, 'styled table must still emit SGR');
        // ...and no letter is lost across the wrap: the surviving letters,
        // row-joined, are exactly header + value (words may split at the
        // 6-cell budget, characters may not vanish).
        $letters = \preg_replace('/[^A-Za-z]/', '', \preg_replace('/\e\[[0-9;]*m/', '', $view));
        $this->assertSame('DescReddishwrappingvalue', $letters);
    }

    // ---- finding #3 (MAJOR): frozen columns must be a contiguous prefix -----

    public function testSparseFrozenColsThrowsNamingTheRule(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/contiguous prefix/i');
        self::plainTable(2)->withFrozenCols([2]);
    }

    public function testFrozenColsNormalizesUnsortedPrefixAndRenders(): void
    {
        $t = self::plainTable(2)->withFrozenCols([1, 0]);
        $view = $t->View();

        $this->assertStringContainsString('ID', $view);
        $this->assertStringContainsString('Name', $view);
    }

    // ---- finding #4 (MAJOR): scrollX must not leave blank gutters -----------

    public function testScrolledTableRendersOneUniformWidthWithNoGutters(): void
    {
        $t = Table::fromColumns([
            Column::new('a', 'L1', 6)->withAlignLeft(),
            Column::new('b', 'L2', 6)->withAlignLeft(),
            Column::new('c', 'L3', 6)->withAlignLeft(),
            Column::new('d', 'L4', 6)->withAlignLeft(),
        ])
            ->withRows([Row::new(RowData::from(['a' => 'aa', 'b' => 'bb', 'c' => 'cc', 'd' => 'dd']))])
            ->withScrollX(2);

        $lines = \array_values(\array_filter(self::viewLines($t->View()), static fn (string $l): bool => $l !== ''));

        $width = Width::of($lines[0]);
        foreach ($lines as $i => $line) {
            $this->assertSame($width, Width::of($line), "line {$i} desynced from the frame width");
            $this->assertStringNotContainsString('│ ', $line, 'blank gutter after the left border');
        }
        $joined = \implode('', $lines);
        $this->assertStringContainsString('L3', $joined);
        $this->assertStringContainsString('L4', $joined);
        $this->assertStringNotContainsString('L1', $joined);
    }

    // ---- finding #5 (MAJOR): page-global cursor, highlight, CurrentRow ------

    public function testCursorOnSecondPageHighlightsTheVisibleRow(): void
    {
        $t = self::plainTable(4)->withPageSize(2)->withSelectable()->withSelectedIndex(3);

        $this->assertSame(1, $t->CurrentPage());
        $this->assertSame('n4', $t->CurrentRow()?->data->get('name'));

        foreach (\explode("\n", $t->View()) as $line) {
            if (\str_contains($line, 'n4')) {
                $this->assertTrue(self::hasReverse($line), 'selected row must render reverse');
            } elseif (\trim($line) !== '') {
                $this->assertFalse(self::hasReverse($line), 'only the selected row may reverse');
            }
        }
    }

    public function testSelectNextAndPreviousAutoPageAcrossBoundaries(): void
    {
        $t = self::plainTable(4)->withPageSize(2)->withSelectable()->withSelectedIndex(1);
        $this->assertSame(0, $t->CurrentPage());

        $down = $t->SelectNext();
        $this->assertSame(2, $down->SelectedIndex());
        $this->assertSame(1, $down->CurrentPage(), 'forward step must page onto the row it selects');

        $up = $down->SelectPrevious();
        $this->assertSame(1, $up->SelectedIndex());
        $this->assertSame(0, $up->CurrentPage(), 'backward step must page back onto the row it selects');
    }

    // ---- finding #6: maxWidth is a hard ceiling with marked clipping --------

    public function testMaxWidthCapsContentColumnAndMarksTheClip(): void
    {
        $col = Column::new('c', 'Content', 0)->withColumnWidth(ColumnWidth::Content)->withMaxWidth(5)->withAlignLeft();
        $t = Table::fromColumns([$col])->withRows([
            Row::new(RowData::from(['c' => 'a very long value here'])),
        ]);

        $solve = new \ReflectionMethod($t, 'computeColumnWidths');
        $solve->setAccessible(true);
        $total = new \ReflectionMethod($t, 'computeTotalWidth');
        $total->setAccessible(true);
        $this->assertSame([5], $solve->invoke($t, $total->invoke($t)));

        $this->assertStringContainsString('a ve…', $t->View());
    }

    // ---- finding #7: single-line rows clip with an ellipsis ------------------

    public function testSingleLineRowClipIsMarkedNotSilent(): void
    {
        $t = Table::fromColumns([
            Column::new('v', 'V', 5)->withAlignLeft(),
        ])->withRows([Row::new(RowData::from(['v' => 'averylongvalue']))]);

        $this->assertStringContainsString('aver…', \implode('', self::viewLines($t->View())));
    }

    // ---- finding #8: expanded row is one full-width detail line --------------

    public function testExpandedRowSpansWidthAtOneRowHeight(): void
    {
        $t = Table::fromColumns([
            Column::new('a', 'A', 4)->withAlignLeft(),
            Column::new('b', 'B', 6)->withAlignLeft(),
        ])->withRows([Row::new(RowData::from(['a' => 'a1', 'b' => 'expand me wider than six']))]);

        $plain = $t->View();
        $expanded = $t->toggleExpanded(0)->View();

        $plainLines = self::viewLines($plain);
        $expandedLines = self::viewLines($expanded);
        $this->assertCount(\count($plainLines), $expandedLines, 'expansion must occupy exactly one row slot');

        $detailLine = null;
        foreach ($expandedLines as $line) {
            if (\str_contains($line, 'expand')) {
                $detailLine = $line;
            }
        }
        $this->assertNotNull($detailLine, 'expanded row must render its joined detail line');
        // Joined run spans the whole visible width (4+1+6=11), clipped with '…'.
        $this->assertStringContainsString('a1  expand…', $detailLine);
        $this->assertStringNotContainsString('wider', $detailLine);
    }

    // ---- finding #9: page indices clamp into range ---------------------------

    public function testNegativePageClampsToFirstPage(): void
    {
        $t = self::plainTable(5)->withPageSize(2)->withPage(-1);

        $this->assertSame(0, $t->CurrentPage());
        $this->assertStringContainsString('n1', $t->View());
    }

    public function testBeyondRangePageClampsToLastPage(): void
    {
        $t = self::plainTable(5)->withPageSize(2)->withPage(999);

        $this->assertSame(2, $t->CurrentPage());
        $this->assertStringContainsString('n5', $t->View());
    }

    public function testFilterShrinkKeepsViewportInsideResult(): void
    {
        $t = self::plainTable(6)->withPageSize(2)->SelectPage(2);
        $shrunk = $t->Filter('name', 'n1'); // keeps only 'n1'

        $this->assertSame(0, $shrunk->CurrentPage());
        $this->assertStringContainsString('n1', $shrunk->View());
    }

    // ---- finding #10: candy-core Width is the only measurement oracle --------

    public function testTableCarriesNoLocalWidthFork(): void
    {
        foreach (['graphemeWidth', 'firstCodepoint', 'isZeroWidth', 'isWide'] as $forkMethod) {
            $this->assertFalse(
                (new \ReflectionClass(Table::class))->hasMethod($forkMethod),
                "deleted EAW fork method '{$forkMethod}' reappeared"
            );
        }
    }

    public function testZeroWidthClusterRidesItsCellInsteadOfInflating(): void
    {
        // "e" + U+0301 combining acute: the mark must land in the base
        // character's cell (width 1), never as its own forced 1-cell column.
        $t = Table::fromColumns([
            Column::new('v', 'V', 4)->withAlignLeft(),
        ])->withRows([Row::new(RowData::from(['v' => "e\u{0301}x"]))])->withBorderless();

        $buffer = self::renderBuffer($t);
        // Borderless + header: row 0 header, row 1 separator, row 2 data.
        $firstCell = $buffer->cellAt(0, 2);
        $this->assertSame(1, $firstCell->width);
        $this->assertStringContainsString("\u{0301}", $firstCell->rune, 'combining mark must stay attached to its base cell');
        $this->assertSame('x', $buffer->cellAt(1, 2)->rune);
    }
}
