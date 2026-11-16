<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\GridTable\Calc;
use SugarCraft\Dashboard\Components\GridTable\Column;
use SugarCraft\Dashboard\Components\GridTable\GridTable;
use SugarCraft\Dashboard\Components\GridTable\Row;
use SugarCraft\Dashboard\Components\Select\ComboBox;

/**
 * Column widths, truncation and padding are measured in terminal display
 * cells (sugar-core Width), not code points: a CJK ideograph and an emoji each
 * occupy two cells, so a code-point measure left those columns short and
 * misaligned every frame line.
 */
final class DisplayCellWidthTest extends TestCase
{
    private function assertFrameIsRectangular(string $rendered): void
    {
        $lines = explode("\n", $rendered);
        $frameWidth = Width::string($lines[0]);

        foreach ($lines as $i => $line) {
            self::assertSame(
                $frameWidth,
                Width::string($line),
                "line {$i} is not the frame width ({$frameWidth} cells): {$line}",
            );
        }
    }

    public function testCjkAndEmojiRowsKeepTheFrameRectangular(): void
    {
        $grid = GridTable::new(
            [Column::new('name', 'Name'), Column::new('city', 'City')],
            [new Row(['name' => '日本語テキスト', 'city' => '🙂x'])],
        )->setSize(30, 10);

        $rendered = $grid->render();

        $this->assertFrameIsRectangular($rendered);
        self::assertSame(33, Width::string(explode("\n", $rendered)[0]));
        self::assertStringContainsString('┃日本語テキスト      ┃', $rendered);
    }

    public function testSortIndicatorKeepsTheCellWidthOfCjkHeaders(): void
    {
        $name = Column::new('name', '名前', sortable: true);
        $grid = GridTable::new([$name], [new Row(['name' => '日本語テキスト'])])
            ->sort($name)
            ->setSize(30, 10);

        $rendered = $grid->render();

        $this->assertFrameIsRectangular($rendered);
        // The label is cut to the cells left after the two-cell indicator, so
        // the indicator still sits flush against the right wall.
        self::assertStringContainsString('┃名前' . str_repeat(' ', 9) . '▲┃', $rendered);
    }

    public function testTruncateCountsDisplayCellsNotCodePoints(): void
    {
        // Five cells: two CJK ideographs (four cells) plus the one-cell ellipsis.
        self::assertSame('日本…', Calc::truncate('日本語テキスト', 5));
        self::assertSame(5, Width::string(Calc::truncate('日本語テキスト', 5)));

        // Three cells: one emoji (two cells) plus the ellipsis.
        self::assertSame('🙂…', Calc::truncate('🙂🙂🙂', 3));

        // A string whose cells already fit comes back untouched.
        self::assertSame('日本', Calc::truncate('日本', 4));
    }

    public function testColumnWidthIsTheWidestCellOfCjkContent(): void
    {
        // '日本語' is three code points but six display cells, so the first
        // column needs six cells; the budget of seven leaves one for the second.
        $rows = [new Row(['a' => '日本語', 'b' => 'x'])];
        $columns = [Column::new('a', 'A'), Column::new('b', 'B')];

        self::assertSame([6, 1], Calc::calculateColumnWidths($rows, $columns, 7));
    }

    public function testComboBoxPlaceholderClipsInDisplayCells(): void
    {
        // Inside a twelve-cell allocation the three-cell search prefix leaves
        // nine cells: the placeholder is cut to eight cells plus the ellipsis.
        $combo = ComboBox::new('日本語テキスト')->setSize(12, 1);

        $rendered = $combo->render();

        self::assertStringContainsString('日本語テ…', $rendered);
        self::assertSame(12, Width::string($rendered));
    }
}
