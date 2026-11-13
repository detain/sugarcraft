<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Table\{Column, Row, RowData, StyledCell, Table};

/**
 * Style-tier precedence pins (audit M3 — the program's most-confirmed
 * doc-vs-code polarity defect).
 *
 * Documented order: base < column < row < cell < styleFunc, with the
 * selection reverse video composed OVER the winner. Before the fix,
 * selection was applied as a rowStyle replacement BEFORE the cell chain,
 * making it the lowest effective tier: a styled column, StyledCell, or
 * styleFunc result threw the highlight away, and the borderless gaps kept
 * plain '7' while cells carried the replacing style — a split highlight.
 */
final class TableSelectionPrecedenceTest extends TestCase
{
    private function plain(string $s): string
    {
        return \preg_replace('/\x1b\[[0-9;]*m/', '', $s) ?? $s;
    }

    public function testColumnStyledCellStillCarriesSelectionReverse(): void
    {
        $t = Table::fromColumns([
            Column::new('a', 'A', 10)->withStyle('31'),
        ])->withRows([Row::new(RowData::from(['a' => 'hit']))]);
        // Row 0 selected by default (selectable defaults true, cursor at 0).

        $view = $t->view();

        // Pre-fix, the row-level '7' replacement clobbered the column red
        // and the cell emitted only "\x1b[0;7m"; post-fix the cell carries
        // column red (ANSI16 31 canon) AND reverse video.
        $this->assertStringContainsString("\x1b[0;38;2;205;0;0;7m", $view);
        $this->assertStringContainsString('hit', $this->plain($view));
    }

    public function testStyledCellAndStyleFuncBothComposeUnderSelection(): void
    {
        $t = Table::fromColumns([Column::new('a', 'A', 10)])
            ->withRows([
                Row::new(RowData::from(['a' => StyledCell::new('cell', '32')])),
            ])
            ->withStyleFunc(fn(int $r, int $c, string $v): string => '35');

        $view = $t->view();

        // styleFunc wins the chain (ANSI16 35 magenta canon), selection
        // rides on top: one run carrying colour + reverse.
        $this->assertStringContainsString("\x1b[0;38;2;205;0;205;7m", $view);
    }

    public function testBorderlessHighlightIsContinuousAcrossGaps(): void
    {
        $t = Table::fromColumns([
            Column::new('a', 'A', 6),
            Column::new('b', 'B', 6),
        ])
            ->withRows([Row::new(RowData::from(['a' => 'one', 'b' => 'two']))])
            ->withBorderless(true);

        $view = $t->view();

        // One reverse run spans cell text AND the gap between cells; the
        // pre-fix split-highlight shape (a bare run break in the middle of
        // the row) cannot occur because every cell composes ';7'.
        $this->assertStringContainsString("\x1b[0;7m   one    two\x1b[0m", $view);
    }

    public function testUnselectedRowsCarryNoReverse(): void
    {
        $t = Table::fromColumns([Column::new('a', 'A', 10)])
            ->withRows([
                Row::new(RowData::from(['a' => 'first'])),
                Row::new(RowData::from(['a' => 'second'])),
            ]);

        $reverseLines = 0;
        foreach (\explode("\n", $t->view()) as $line) {
            if (\str_contains($line, ';7m')) {
                $reverseLines++;
            }
        }

        // Exactly one row band is reversed (the default cursor at index 0).
        $this->assertSame(1, $reverseLines);
    }

    public function testDefaultFirstRenderHighlightsFirstRow(): void
    {
        // M16 doc pin: selectable defaults to true and the cursor starts at
        // index 0 — the FIRST data row is highlighted on the first render;
        // withSelectable(false) removes the band entirely.
        $t = Table::fromColumns([Column::new('a', 'A', 10)])
            ->withRows([Row::new(RowData::from(['a' => 'x']))]);

        $this->assertStringContainsString(';7m', $t->view());
        $this->assertStringNotContainsString(
            ';7m',
            $t->withSelectable(false)->view(),
        );
    }

    /** ST-59 / OG-7: a bordered selected row is ONE reverse run across its in-row rules. */
    public function testSelectedBorderedRowBandIsContinuousAcrossSeparators(): void
    {
        $t = Table::fromColumns([Column::new('a', 'A', 4), Column::new('b', 'B', 4)])
            ->withRows([Row::new(RowData::from(['a' => 'x', 'b' => 'y'])), Row::new(RowData::from(['a' => 'p', 'b' => 'q']))]);
        $lines = \explode("\n", $t->view());
        $selected = $lines[3];
        $this->assertSame(1, \substr_count($selected, "\x1b[0;7m"), 'one open of the reverse band for the whole row');
        $this->assertStringNotContainsString("\x1b[0;7m", $lines[4], 'unselected rows carry no reverse');
    }
}
