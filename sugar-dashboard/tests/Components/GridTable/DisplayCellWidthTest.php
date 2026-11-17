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
        // The label is four cells, so it fits the cells left after the two-cell
        // indicator: it is padded to that width, not cut, and the indicator
        // still sits flush against the right wall.
        self::assertStringContainsString('┃名前' . str_repeat(' ', 9) . '▲┃', $rendered);
    }

    public function testUnsortedHeaderPadsACjkLabelByDisplayWidth(): void
    {
        // The label 名前 is four cells but six bytes. str_pad pads by bytes, so on
        // the old path the header came out narrower than its column (the frame
        // line and the header line differ); Width::padRight pads by display cells
        // to the column's fourteen.
        $name = Column::new('name', '名前');
        $grid = GridTable::new([$name], [new Row(['name' => '日本語テキスト'])])
            ->setSize(30, 10);

        $rendered = $grid->render();
        $header = explode("\n", $rendered)[1];

        $this->assertFrameIsRectangular($rendered);
        self::assertSame(16, Width::string($header));
        self::assertStringContainsString('┃名前' . str_repeat(' ', 10) . '┃', $header);
    }

    public function testDecomposedAccentMeasuresOneCell(): void
    {
        // "e" followed by U+0301 COMBINING ACUTE ACCENT is one glyph in one cell.
        // The code-point rule counted two code points, one column cell too many.
        self::assertSame(['k' => 1], self::columnWidthsOf(["e\u{0301}"]));
    }

    public function testPrecomposedAccentPadsByDisplayWidth(): void
    {
        // U+00E9 is one cell but two bytes. str_pad pads by bytes, so a cell of
        // it in a four-cell column got two spaces and stopped a cell short.
        $columns = [Column::new('name', 'Name')];
        $rendered = GridTable::new($columns, [new Row(['name' => "\u{00e9}"])])
            ->setSize(30, 10)
            ->render();

        self::assertStringContainsString("┃\u{00e9}   ┃", $rendered);
    }

    public function testSortedHeaderKeepsTheSgrOfALabelThatFits(): void
    {
        // Width 12 leaves 10 cells beside the two-cell indicator; the styled
        // "Name" fits in them, so its SGR must survive the sorted-header cut.
        $name = Column::new('name', "\e[1mName\e[0m", sortable: true);
        $grid = GridTable::new([$name], [new Row(['name' => 'abcdefghijkl'])])
            ->sort($name)
            ->setSize(30, 10);

        $rendered = $grid->render();

        $this->assertFrameIsRectangular($rendered);
        self::assertStringContainsString("┃\e[1mName\e[0m" . str_repeat(' ', 7) . '▲┃', $rendered);
    }

    public function testSortedHeaderDropsSgrOnlyWhenTheLabelIsClipped(): void
    {
        // The column is the label's 25 cells wide, so the indicator leaves 23 for
        // the label; the styled label is cut there and its SGR goes with the cut.
        $name = Column::new('name', "\e[1mNameIsLongerThanTheColumn\e[0m", sortable: true);
        $grid = GridTable::new([$name], [new Row(['name' => 'abcdefghijkl'])])
            ->sort($name)
            ->setSize(30, 10);

        $rendered = $grid->render();
        $header = explode("\n", $rendered)[1];

        $this->assertFrameIsRectangular($rendered);
        self::assertStringNotContainsString("\e[", $header);
        self::assertStringContainsString('NameIsLongerThanTheColu ▲┃', $header);
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

    public function testTruncateNeverSplitsAGraphemeCluster(): void
    {
        // A ZWJ family is one two-cell glyph. Cut to three cells it keeps the whole
        // cluster and drops "xyz". Master's mb_substr kept two code points and left
        // a dangling ZWJ before the ellipsis.
        $family = "\u{1F468}\u{200D}\u{1F469}\u{200D}\u{1F467}";
        self::assertSame($family . '…', Calc::truncate($family . 'xyz', 3));

        // A flag is a pair of regional indicators, one two-cell glyph. Cut to two
        // cells it cannot fit, so only the ellipsis remains; master kept the lone
        // first indicator.
        self::assertSame('…', Calc::truncate("\u{1F1EF}\u{1F1F5}abc", 2));

        // Combining marks belong to the base letter's cluster and stay with it.
        self::assertSame("a\u{0301}\u{0301}…", Calc::truncate("a\u{0301}\u{0301}bc", 2));
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

    public function testComboBoxOptionLabelClipsInDisplayCells(): void
    {
        // Option labels go through the same clip as the placeholder. Inside a
        // twelve-cell allocation the "▶ " prefix leaves ten cells: the label is
        // cut to eight cells (日本語テ) plus the ellipsis. The code-point rule
        // saw seven characters, fit them, and overran the box.
        $combo = $this->plainCombo([['label' => '日本語テキスト']], 12);

        $lines = explode("\n", $combo->render());

        self::assertSame('▶ 日本語テ…', $lines[1]);
        self::assertSame(11, Width::string($lines[1]));
    }

    public function testComboBoxOptionLabelChargesATabFourCells(): void
    {
        // Budget four cells: "a" is one, the tab would be four more, so the
        // label is cut before the tab. The code-point rule kept "a<TAB>b" and
        // overran the allocation.
        $combo = $this->plainCombo([['label' => "a\tbcdefg"]], 6);

        $lines = explode("\n", $combo->render());

        self::assertSame('▶ a…', $lines[1]);
        self::assertStringNotContainsString("\t", $lines[1]);
    }

    public function testComboBoxOptionLabelDropsSgrOnlyWhenClipped(): void
    {
        // A clipped label loses its SGR (the old mb_substr cut could leave the
        // escape open). A label that fits keeps its SGR, returned untouched.
        $clipped = $this->plainCombo([['label' => "\x1b[31mred alert\x1b[0m"]], 8);
        $clippedLine = explode("\n", $clipped->render())[1];

        self::assertSame('▶ red a…', $clippedLine);
        self::assertStringNotContainsString("\x1b", $clippedLine);

        $fits = $this->plainCombo([['label' => "\x1b[31mred\x1b[0m"]], 20);
        $fitsLine = explode("\n", $fits->render())[1];

        self::assertSame("▶ \x1b[31mred\x1b[0m", $fitsLine);
    }

    public function testFrozenHalfOfARowPadsCjkCellsByDisplayWidth(): void
    {
        // At width 30 each column is 15 cells (literal, not read back from Calc,
        // so the expectation holds on master too). The frozen cell 日本 is four
        // cells and six bytes: str_pad pads by bytes, so on master it would reach
        // 13 cells, two short of the 15-cell column. The frozen half must reach
        // 15 cells, then the extra right wall that renderFrozenRow writes before the
        // divider. That wall makes the frozen data row 34 cells wide, one more than
        // the 33-cell frame, and the pin below records it as a defect. The row is
        // split at the freeze divider ║ so only the frozen half is measured.
        $columns = [Column::new('name', 'Name'), Column::new('city', 'City')];
        $rows = [
            new Row(['name' => '日本語', 'city' => '東京都']),
            new Row(['name' => '日本', 'city' => '東京']),
        ];

        $rendered = GridTable::new($columns, $rows)->freezeColumns(1)->setSize(30, 10)->render();
        $frozenHalf = explode('║', explode("\n", $rendered)[4])[0];

        // Pinned as it is, not as correct. The frozen data row is 34 cells wide
        // because renderFrozenRow writes an extra right wall before the divider,
        // one more than the 33-cell frame. Tracked as a separate work package:
        // freeze-divider extra wall in renderFrozenRow. The literals below stay as they are.
        self::assertSame('┃日本' . str_repeat(' ', 11) . '┃', $frozenHalf);
        self::assertSame(17, Width::string($frozenHalf));
    }

    public function testScrollingHalfOfAFrozenRowPadsCjkCellsByDisplayWidth(): void
    {
        // The scrolling half is the cells after the freeze divider ║. 東京 is four
        // cells and six bytes, the same byte/cell gap as the frozen half, and the
        // column is the literal 15 cells (see the frozen-half test).
        $columns = [Column::new('name', 'Name'), Column::new('city', 'City')];
        $rows = [
            new Row(['name' => '日本語', 'city' => '東京都']),
            new Row(['name' => '日本', 'city' => '東京']),
        ];

        $rendered = GridTable::new($columns, $rows)->freezeColumns(1)->setSize(30, 10)->render();
        $scrollHalf = explode('║', explode("\n", $rendered)[4])[1];

        // Pinned as it is, not as correct: this half is part of the same 34-cell frozen
        // row. renderFrozenRow writes an extra right wall before the divider, one more
        // than the 33-cell frame (see the frozen-half test). Tracked as freeze-divider
        // extra wall in renderFrozenRow. The literals stay.
        self::assertSame('東京' . str_repeat(' ', 11) . '┃', $scrollHalf);
        self::assertSame(16, Width::string($scrollHalf));
    }

    public function testNoticeRowPadsByDisplayWidth(): void
    {
        // The notice text is a fixed ASCII message, so the rendered notice
        // cannot show a byte/cell gap. The helper is pinned with a multibyte
        // message to catch a return to str_pad. Its widths are six then three.
        $grid = GridTable::new([Column::new('name', 'Name'), Column::new('city', 'City')]);
        $notice = new \ReflectionMethod(GridTable::class, 'renderNoticeRow');

        $line = (string) $notice->invoke($grid, '日本', [6, 3]);

        self::assertSame(12, Width::string($line));
        self::assertStringContainsString(Width::padRight('日本', 6), $line);

        // Only filterable columns match, so the filter needs one to empty the set.
        // The minimum width keeps the whole notice visible rather than ellipsized.
        $filtered = GridTable::new(
            [Column::new('name', 'Name', minWidth: 24, filterable: true)],
            [new Row(['name' => 'alpha'])],
        )->filter('nomatch')->setSize(30, 10);
        self::assertStringContainsString('no rows match filter', $filtered->render());
    }

    public function testPlainAsciiTruncationMatchesTheCodePointRule(): void
    {
        // Oracle: the rule before the display-cell change. It agrees with the
        // cell rule only where one code point is one cell, so the inputs are
        // printable ASCII with no tab and no ESC (those are pinned below).
        $codePointRule = static function (string $s, int $max): string {
            if ($max <= 0) {
                return '';
            }
            if (mb_strlen($s) <= $max) {
                return $s;
            }

            return mb_substr($s, 0, $max - 1) . '…';
        };

        foreach (['', 'a', 'hello', 'abcdefgh', 'Hello, world!'] as $s) {
            for ($max = 0; $max <= 12; $max++) {
                self::assertSame(
                    $codePointRule($s, $max),
                    Calc::truncate($s, $max),
                    "'{$s}' clipped to {$max}",
                );
            }
        }
    }

    public function testATabIsChargedTheTabWidthNotOneCell(): void
    {
        // Width charges a tab TAB_WIDTH cells (Style expands tabs to the same
        // count). The code-point rule charged one, so a cell that used to fit
        // can now clip. Pinned so a change to the tab charge is visible here.
        self::assertSame(6, Width::string("a\tb"));
        self::assertSame(['k' => 6], self::columnWidthsOf(["a\tb"]));
        self::assertSame('a…', Calc::truncate("a\tb", 3));
    }

    public function testSgrBytesAreNotChargedToCells(): void
    {
        // The code-point rule counted the ESC and the parameter bytes of an SGR
        // sequence, so a three-cell styled word measured twelve. Cells ignore
        // the sequence, and only a clipped cell loses its styling.
        $styled = "\x1b[31mred\x1b[0m";

        self::assertSame(3, Width::string($styled));
        self::assertSame(['k' => 3], self::columnWidthsOf([$styled]));
        self::assertSame($styled, Calc::truncate($styled, 3));
        self::assertSame('r…', Calc::truncate($styled, 2));
    }

    /**
     * A ComboBox with the default selected-row color removed, so the option
     * line carries only the clipped label and its own SGR.
     *
     * @param list<array{label: string}> $options
     */
    private function plainCombo(array $options, int $width): ComboBox
    {
        return ComboBox::new('Search', $options)->withSelectedColor(null)->setSize($width, 6);
    }

    /**
     * @param list<string> $values one column, keyed 'k'
     * @return array<string, int>
     */
    private function columnWidthsOf(array $values): array
    {
        $rows = array_map(static fn(string $v): Row => new Row(['k' => $v]), $values);
        $widths = Calc::calculateColumnWidths($rows, [Column::new('k', 'K')], 200);

        return ['k' => $widths[0]];
    }
}
