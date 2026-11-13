<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, RenderCanvas, Row, RowData, Table, WrapMode};

/**
 * Audit ST-17 (finding 17 / QA-04 + TUI-03): wrapped paths must never emit
 * a line over the column budget and must never drop an over-wide cluster in
 * silence. The QA probe was a multiline Character-wrap CJK run in a 1-cell
 * column — the bordered fill branch painted a 10-cell line on a 9-cell frame
 * (glyph lost, frame desynced) while the borderless path handled it. These
 * pins hold the fix in both directions: uniform line widths, a visible '…'
 * where content was clipped, and bordered/borderless parity.
 */
final class TableMultilineCjkTest extends TestCase
{
    private static function stripAnsi(string $s): string
    {
        return (string) \preg_replace('/\x1b\[[0-9;]*m/', '', $s);
    }

    /** @return list<int> display widths of every rendered line */
    private static function lineWidths(string $view): array
    {
        return \array_map('mb_strwidth', \explode("\n", \rtrim($view, "\n")));
    }

    private static function cjkTable(int $cellWidth, bool $borderless = false): Table
    {
        $col = Column::new('c', 'C', $cellWidth)->withWrapMode(WrapMode::Character);

        return Table::fromColumns([$col, Column::new('id', 'ID', 2)])
            ->withRows([Row::new(RowData::from(['c' => '漢字', 'id' => '1']))])
            ->withMultilineMode(true)
            ->withBorderless($borderless);
    }

    public function testOverWideClustersNeverEscapeTheBorderedFrame(): void
    {
        $view = self::stripAnsi(self::cjkTable(1)->View());
        $widths = self::lineWidths($view);

        $this->assertGreaterThan(1, \count($widths));
        foreach ($widths as $i => $w) {
            $this->assertSame($widths[0], $w, "line $i desynced from the frame");
        }
        $this->assertStringContainsString('…', $view, 'clipped clusters must announce');
        // Frame: 1 + 2 cells + between border + 2 edges.
        $this->assertSame(6, $widths[0]);
    }

    public function testBorderlessPathAgreesWithBorderedWidths(): void
    {
        $bordered = self::lineWidths(self::stripAnsi(self::cjkTable(1)->View()));
        $borderless = self::lineWidths(self::stripAnsi(self::cjkTable(1, true)->View()));

        // Same cells, same wrapping — the box adds two edge lines and two
        // border characters per line.
        $inner = \array_slice($bordered, 1, -1);
        $this->assertCount(\count($borderless), $inner);
        foreach ($inner as $i => $w) {
            $this->assertSame($w - 2, $borderless[$i], "parity broke at line $i");
        }
    }

    public function testCharacterWrapKeepsEveryLineWithinColumnBudget(): void
    {
        $col = Column::new('cjk', 'NL', 4)->withWrapMode(WrapMode::Character);
        $table = Table::fromColumns([$col, Column::new('id', 'ID', 3)])
            ->withRows([Row::new(RowData::from(['cjk' => '日本語です', 'id' => '1']))])
            ->withMultilineMode(true);

        $view = self::stripAnsi($table->View());
        $widths = self::lineWidths($view);
        foreach ($widths as $i => $w) {
            $this->assertSame($widths[0], $w, "line $i desynced");
        }
        // 5 clusters of width 2 across a 4-cell column: 日本 | 語で | す.
        $this->assertStringContainsString('日本', $view);
        $this->assertStringContainsString('語で', $view);
        $this->assertStringContainsString('す', $view);
    }

    public function testUnbreakableWordIsSplitNotErased(): void
    {
        $col = Column::new('w', 'W', 5)->withWrapMode(WrapMode::WordWrap);
        $table = Table::fromColumns([$col])
            ->withRows([Row::new(RowData::from(['w' => 'extraordinarilylong']))])
            ->withMultilineMode(true);

        $view = self::stripAnsi($table->View());
        $widths = self::lineWidths($view);
        foreach ($widths as $i => $w) {
            $this->assertSame($widths[0], $w, "line $i desynced");
        }
        // Width::wrap shatters an over-long word across lines: content must
        // survive whole across the wrapped lines, and no line may over-wide
        // the frame.
        $this->assertStringContainsString('extra', $view);
        $this->assertStringContainsString('ordin', $view);
        $this->assertStringContainsString('arily', $view);
        $this->assertStringContainsString('long', $view);
    }

    /**
     * The renderCell clamp (never over-wide, never silent) is directly
     * reachable when a splitter yields clusters wider than the column and
     * no other arm fits them: feed a WordWrap line through a 1-cell column.
     */
    public function testLinesNeverEscapeTheColumnBudgetEvenFromWordWrap(): void
    {
        $col = Column::new('w', 'W', 1)->withWrapMode(WrapMode::WordWrap);
        $table = Table::fromColumns([$col, Column::new('id', 'ID', 2)])
            ->withRows([Row::new(RowData::from(['w' => '日本語', 'id' => 'x']))])
            ->withMultilineMode(true);

        $view = self::stripAnsi($table->View());
        $widths = self::lineWidths($view);
        foreach ($widths as $i => $w) {
            $this->assertSame($widths[0], $w, "line $i desynced from the frame");
        }
        $this->assertStringContainsString('…', $view);
    }

    public function testCjkHeaderInNarrowBorderedCellAnnouncesInsteadOfDesync(): void
    {
        // The header text rides fillCellContent directly (no renderCell wrap
        // pass), so the fill arm's announce-and-stop law needs its own pin.
        $table = Table::fromColumns([Column::new('c', '日本', 1), Column::new('id', 'ID', 2)])
            ->withRows([Row::new(RowData::from(['c' => 'x', 'id' => '1']))]);

        $view = self::stripAnsi($table->View());
        $widths = self::lineWidths($view);
        foreach ($widths as $i => $w) {
            $this->assertSame($widths[0], $w, "line $i desynced from the frame");
        }
        $this->assertStringContainsString('…', $view);
    }

    // ---- ST-58: width-2 cluster at the canvas right edge -------------------

    public function testWideClusterStraddlingTheBufferEdgeDowngradesToEllipsis(): void
    {
        // Defensive shape (only a too-narrow misconfiguration can produce
        // it, which is why the framed/borderless public paths pin as
        // uniform-width instead): a 3-cell canvas whose declared cell space
        // reaches past the buffer used to store a width-2 cell at the final
        // slot with its continuation silently skipped, overrunning the
        // visual line by a column. ST-58 budgets the fit against the buffer
        // too, so the edge announces '…' inside the frame.
        $canvas = new RenderCanvas(3, 1);
        $table = Table::fromColumns([Column::new('c', 'C', 4)]);
        $fill = new \ReflectionMethod(Table::class, 'fillCellContent');
        $fill->invoke($table, $canvas, 0, 0, 'ab漢', 6, null);

        $this->assertSame('…', $canvas->at(2, 0)?->rune);
        $this->assertSame(1, $canvas->at(2, 0)?->width());
        $view = self::stripAnsi($canvas->toBuffer()->toAnsi());
        foreach (self::lineWidths($view) as $i => $w) {
            $this->assertSame(3, $w, "line $i overran the 3-cell buffer");
        }
    }

    public function testWideClusterWithItsContinuationInsideTheBufferStillPlaces(): void
    {
        // The other arm of the same budget: when the second slot exists,
        // the wide cluster places unchanged (no downgrade, no regression).
        $canvas = new RenderCanvas(4, 1);
        $table = Table::fromColumns([Column::new('c', 'C', 4)]);
        $fill = new \ReflectionMethod(Table::class, 'fillCellContent');
        $fill->invoke($table, $canvas, 0, 0, 'ab漢', 4, null);

        $this->assertSame('漢', $canvas->at(2, 0)?->rune);
        $this->assertSame(2, $canvas->at(2, 0)?->width());
        $this->assertSame(0, $canvas->at(3, 0)?->width());
    }
}
