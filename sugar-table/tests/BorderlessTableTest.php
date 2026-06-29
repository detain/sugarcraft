<?php

declare(strict_types=1);

namespace SugarCraft\Table\Tests;

use SugarCraft\Table\{Column, ColumnWidth, Row, RowData, Table};
use SugarCraft\Core\Util\{Ansi, Width};
use PHPUnit\Framework\TestCase;

/**
 * Borderless + width-exact + ANSI reverse-video selection: the configuration a
 * table needs to compose cleanly inside another bordered shell (e.g. a
 * sugar-boxer content box) without a double border.
 */
final class BorderlessTableTest extends TestCase
{
    /** @param array<int,array<string,string>> $data */
    private function table(array $columns, array $data): Table
    {
        $rows = array_map(static fn (array $r): Row => Row::new(RowData::from($r)), $data);

        return Table::fromColumns($columns)->withRows($rows);
    }

    private static function lines(string $out): array
    {
        return explode("\n", $out);
    }

    /** True if a line carries the SGR reverse attribute (`7`), however the run is encoded. */
    private static function hasReverse(string $line): bool
    {
        return preg_match('/\e\[(?:[0-9;]*;)?7(?:;[0-9;]*)?m/', $line) === 1;
    }

    private function albumColumns(): array
    {
        return [
            Column::new('album', 'Album', 0)->withColumnWidth(ColumnWidth::Flex)->withAlignLeft(),
            Column::new('artist', 'Artist', 12)->withAlignLeft(),
            Column::new('year', 'Year', 6),
        ];
    }

    private function albumRows(): array
    {
        return [
            ['album' => 'A Very Long Album Title That Overflows', 'artist' => 'Artist One', 'year' => '2020'],
            ['album' => 'Short', 'artist' => 'Artist Two', 'year' => '2021'],
            ['album' => 'Mid Album', 'artist' => 'Three', 'year' => '2019'],
        ];
    }

    // ---- borderless --------------------------------------------------------

    public function testBorderlessHasNoBoxBorderCharacters(): void
    {
        $out = $this->table($this->albumColumns(), $this->albumRows())
            ->withBorderless()->withWidth(40)->View();

        $stripped = Ansi::strip($out);
        // No vertical rules and no box corners (the header rule "─" is allowed).
        $this->assertStringNotContainsString('│', $stripped);
        foreach (['╭', '╮', '╰', '╯', '┌', '┐', '└', '┘', '├', '┤'] as $corner) {
            $this->assertStringNotContainsString($corner, $stripped);
        }
    }

    public function testBorderlessFirstLineIsHeaderNotTopBorder(): void
    {
        $out = $this->table($this->albumColumns(), $this->albumRows())
            ->withBorderless()->withWidth(40)->View();

        $first = Ansi::strip(self::lines($out)[0]);
        $this->assertStringContainsString('Album', $first);   // header, not a ╭───╮ rule
        $this->assertStringContainsString('Artist', $first);
    }

    public function testBorderlessHeaderSeparatorRuleIsPresent(): void
    {
        $out = $this->table($this->albumColumns(), $this->albumRows())
            ->withBorderless()->withWidth(40)->View();

        // Second line is the "────" rule spanning the full width.
        $rule = Ansi::strip(self::lines($out)[1]);
        $this->assertSame(str_repeat('─', 40), $rule);
    }

    // ---- width-exact -------------------------------------------------------

    public function testBorderlessWidthIsExactlyRequested(): void
    {
        foreach ([20, 33, 40, 80] as $w) {
            $out = $this->table($this->albumColumns(), $this->albumRows())
                ->withBorderless()->withWidth($w)->View();
            foreach (self::lines($out) as $i => $line) {
                $this->assertSame($w, Width::string($line), "line {$i} at width {$w}");
            }
        }
    }

    public function testFlexColumnFillsAndTruncatesLongContent(): void
    {
        $out = $this->table($this->albumColumns(), $this->albumRows())
            ->withBorderless()->withWidth(40)->View();

        $stripped = Ansi::strip($out);
        // The long album title is truncated to its (filled) column, not overflowing.
        $this->assertStringNotContainsString('That Overflows', $stripped);
        $this->assertStringContainsString('A Very Long', $stripped);
    }

    public function testMultipleFlexColumnsSumToExactWidth(): void
    {
        $cols = [
            Column::new('a', 'A', 0)->withColumnWidth(ColumnWidth::Flex)->withAlignLeft(),
            Column::new('b', 'B', 0)->withColumnWidth(ColumnWidth::Flex)->withAlignLeft(),
            Column::new('c', 'C', 4),
        ];
        $out = $this->table($cols, [['a' => 'x', 'b' => 'y', 'c' => 'z']])
            ->withBorderless()->withWidth(31)->View();

        foreach (self::lines($out) as $line) {
            $this->assertSame(31, Width::string($line)); // remainder absorbed → exact
        }
    }

    public function testWidthExactWithZeroCellPaddingIsDeterministic(): void
    {
        // cellPadding defaults to 0; the table width equals exactly the request.
        $t = $this->table($this->albumColumns(), $this->albumRows())->withBorderless()->withWidth(50);
        $this->assertSame(0, $this->readCellPadding($t));
        foreach (self::lines($t->View()) as $line) {
            $this->assertSame(50, Width::string($line));
        }
    }

    private function readCellPadding(Table $t): int
    {
        $r = new \ReflectionProperty($t, 'cellPadding');
        $r->setAccessible(true);

        return $r->getValue($t);
    }

    // ---- reverse-video selection ------------------------------------------

    public function testSelectedRowRendersReverseVideoAcrossFullWidth(): void
    {
        $out = $this->table($this->albumColumns(), $this->albumRows())
            ->withBorderless()->withWidth(40)->withSelectable()->withSelectedIndex(1)->View();

        $lines = self::lines($out);
        // header(0), rule(1), data row 0 (2), data row 1 == selected (3)
        $selected = $lines[3];
        $this->assertTrue(self::hasReverse($selected), 'selected row carries reverse SGR');
        // Reverse opens before the first visible cell → the whole row is highlighted.
        $this->assertSame(0, strpos($selected, "\e["), 'highlight starts at column 0');
        $this->assertStringContainsString('Short', Ansi::strip($selected));
        // The highlight is the full content width. (The trailing SGR reset is the
        // host's job — sugar-boxer's ANSI-aware placement closes any open span so
        // colour never bleeds; a borderless table composes inside that shell.)
        $this->assertSame(40, Width::string($selected));
    }

    public function testUnselectedRowsHaveNoReverse(): void
    {
        $out = $this->table($this->albumColumns(), $this->albumRows())
            ->withBorderless()->withWidth(40)->withSelectable()->withSelectedIndex(1)->View();

        $lines = self::lines($out);
        $this->assertFalse(self::hasReverse($lines[2]), 'data row 0 not selected');
        $this->assertFalse(self::hasReverse($lines[4]), 'data row 2 not selected');
    }

    public function testSelectionDisabledRendersNoReverse(): void
    {
        $out = $this->table($this->albumColumns(), $this->albumRows())
            ->withBorderless()->withWidth(40)->withSelectable(false)->View();

        foreach (self::lines($out) as $line) {
            $this->assertFalse(self::hasReverse($line));
        }
    }

    public function testTooNarrowWidthDegradesGracefullyWithoutError(): void
    {
        // Width far smaller than the fixed columns: rendering must not throw and
        // every line stays clamped to the buffer (the defensive bounds guards).
        $cols = [
            Column::new('a', 'A', 0)->withColumnWidth(ColumnWidth::Flex)->withAlignLeft(),
            Column::new('b', 'B', 12)->withAlignLeft(),
            Column::new('c', 'C', 12)->withAlignLeft(),
        ];
        $out = $this->table($cols, [['a' => 'x', 'b' => str_repeat('b', 12), 'c' => str_repeat('c', 12)]])
            ->withBorderless()->withWidth(8)->withSelectable()->withSelectedIndex(0)->View();

        $this->assertIsString($out);
        foreach (self::lines($out) as $i => $line) {
            $this->assertLessThanOrEqual(8, Width::string($line), "clamped line {$i}");
        }
    }

    // ---- multi-byte / styled cells ----------------------------------------

    public function testMultibyteCellsKeepVisibleWidth(): void
    {
        $cols = [
            Column::new('name', 'Name', 0)->withColumnWidth(ColumnWidth::Flex)->withAlignLeft(),
            Column::new('lang', 'Lang', 8)->withAlignLeft(),
        ];
        $out = $this->table($cols, [
            ['name' => 'café', 'lang' => '日本語'],          // accented + CJK (wide)
            ['name' => 'naïve', 'lang' => 'ascii'],
        ])->withBorderless()->withWidth(30)->View();

        foreach (self::lines($out) as $i => $line) {
            $this->assertSame(30, Width::string($line), "multibyte line {$i} width");
        }
        $stripped = Ansi::strip($out);
        $this->assertStringContainsString('café', $stripped);
        $this->assertStringContainsString('日本語', $stripped);
    }

    public function testStyledCellsViaStyleFuncKeepVisibleWidth(): void
    {
        $cols = [
            Column::new('a', 'A', 0)->withColumnWidth(ColumnWidth::Flex)->withAlignLeft(),
            Column::new('b', 'B', 6),
        ];
        $out = $this->table($cols, [['a' => 'green', 'b' => '99']])
            ->withBorderless()->withWidth(24)
            ->withStyleFunc(static fn (int $r, int $c, string $v): string => $c === 0 ? '1;32' : '')
            ->View();

        $line = self::lines($out)[2]; // the data row
        $this->assertStringContainsString("\e[", $line);              // it IS styled
        $this->assertSame(24, Width::string($line));                  // style adds no width
        $this->assertStringContainsString('green', Ansi::strip($line));
    }

    // ---- regression: bordered mode unchanged ------------------------------

    public function testBorderedModeStillDrawsABox(): void
    {
        $out = $this->table($this->albumColumns(), $this->albumRows())->View(); // default = bordered
        $stripped = Ansi::strip($out);
        $this->assertStringContainsString('│', $stripped);
        $this->assertStringContainsString('┌', $stripped);
        $this->assertStringContainsString('┘', $stripped);
    }
}
