<?php

declare(strict_types=1);

namespace SugarCraft\Widgets\Tests\Table;

use PHPUnit\Framework\TestCase;
use SugarCraft\Widgets\Table\Table;
use SugarCraft\Core\Util\Width;

/**
 * Regression tests for column-padding behavior.
 *
 * Mirrors upstream charmbracelet/bubbles #472 — a regression where
 * varying-width cells stopped being padded to column width, producing
 * misaligned rows. Our renderer pads every cell to the auto-computed
 * (or explicitly-pinned) column width and joins with a single-space
 * gutter, so this test class anchors that behavior so it can't quietly
 * regress.
 */
final class TablePaddingRegressionTest extends TestCase
{
    public function testEveryRowPadsToTheWidestCellPerColumn(): void
    {
        $t = Table::new(
            ['Name', 'Status'],
            [
                ['Alice',     'OK'],
                ['Bob',       'PENDING'],
                ['Christine', 'FAIL'],
            ],
        );

        $lines = explode("\n", $t->view());
        $this->assertCount(4, $lines);
        // Compare *cell width* (ANSI-stripped, codepoint-aware) so the
        // default header SGR underline doesn't leak into the assertion.
        $widths = array_map(static fn(string $line): int => Width::string($line), $lines);
        $this->assertSame(
            [$widths[0]],
            array_unique($widths),
            'every rendered row should have identical visible width',
        );
    }

    public function testMixedAsciiAndMultibyteCellsAlignByCellWidth(): void
    {
        // 'café' is 5 bytes / 4 cells; 'naïve' is 6 bytes / 5 cells.
        // Width::string() must be cell-width aware; renderRow must use it.
        $t = Table::new(
            ['Word'],
            [
                ['café'],
                ['naïve'],
                ['plain'],
            ],
        );
        $lines = explode("\n", $t->view());
        $cellWidths = array_map(static fn(string $line): int => Width::string($line), $lines);
        $this->assertSame(
            [$cellWidths[0]],
            array_unique($cellWidths),
            'every rendered row should have identical *cell* width even with multibyte content',
        );
    }

    public function testExplicitTableWidthOverridesAutoSize(): void
    {
        $t = Table::new(
            ['Name'],
            [
                ['this-is-a-very-long-name'],
                ['ok'],
            ],
        )->setSize(8, 0);

        $lines = explode("\n", $t->view());
        // Every line capped at 8 cells (cell-width, ANSI-stripped).
        foreach ($lines as $line) {
            $this->assertLessThanOrEqual(8, Width::string($line), "line `{$line}` should fit in 8 cells");
        }
    }

    public function testEmptyCellPadsToColumnWidth(): void
    {
        $t = Table::new(
            ['A', 'B'],
            [
                ['hello', 'world'],
                ['',      'x'],         // empty A
                ['y',     ''],          // empty B
            ],
        );
        $lines = explode("\n", $t->view());
        $widths = array_map(static fn(string $line): int => Width::string($line), $lines);
        $this->assertSame(
            [$widths[0]],
            array_unique($widths),
            'rows containing empty cells should still pad to full column width',
        );
    }

    public function testGutterIsSingleSpace(): void
    {
        $t = Table::new(
            ['A', 'B', 'C'],
            [
                ['1', '2', '3'],
            ],
        );
        $lines = explode("\n", $t->view());
        // 3 columns of width 1 + 2 gutters of width 1 = 5 cells
        $this->assertSame(5, Width::string($lines[1]));
    }
}
