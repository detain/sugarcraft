<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Tests\Components\GridTable;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dashboard\Components\GridTable\BorderConfig;
use SugarCraft\Dashboard\Components\GridTable\Column;
use SugarCraft\Dashboard\Components\GridTable\GridTable;
use SugarCraft\Dashboard\Components\GridTable\Row;

/**
 * D-63 (sugar-dashboard-150) pins: the freeze junction family with intent
 * chosen by reading the geometry.
 *
 * The heavy freeze divider (║) spans the body only. It meets a horizontal
 * rule at exactly two places — the header separator (freeze top junction,
 * ╥) and the footer separator (freeze bottom junction, ╨). The outer top
 * and bottom border rows carry plain column junctions at every seat,
 * including the seat above/below the freeze divider, because no vertical
 * line continues to them there. The former dead if/else arms in
 * renderTopBorder/renderBottomBorder emitted identical characters in both
 * branches; these pins keep that geometry honest in both directions:
 * a frozen(3) frame must NOT grow special characters on the outer borders,
 * and the header/footer freeze seats must keep theirs.
 *
 * frozen(0) is the byte-baseline: no freeze glyph anywhere in the frame.
 */
final class FrozenJunctionTest extends TestCase
{
    /**
     * @return list<Column>
     */
    private static function columns(): array
    {
        return [
            Column::new('a', 'AA'),
            Column::new('b', 'BB'),
            Column::new('c', 'CC'),
            Column::new('d', 'DD'),
            Column::new('e', 'EE'),
        ];
    }

    /**
     * @return list<Row>
     */
    private static function rows(): array
    {
        return [
            new Row(['a' => '1', 'b' => '2', 'c' => '3', 'd' => '4', 'e' => '5']),
            new Row(['a' => '6', 'b' => '7', 'c' => '8', 'd' => '9', 'e' => '10']),
        ];
    }

    private static function grid(int $frozen): GridTable
    {
        return (new GridTable(self::columns(), self::rows(), null, BorderConfig::new()))
            ->freezeColumns($frozen);
    }

    /**
     * @return list<string>
     */
    private static function lines(GridTable $grid): array
    {
        return explode("\n", $grid->render());
    }

    public function testOuterBordersCarryPlainJunctionsEvenWithFrozenBand(): void
    {
        $lines = self::lines(self::grid(3));
        $top = $lines[0];
        $bottom = $lines[count($lines) - 1];

        // Every interior seat is a plain heavy junction (topMid ┳ /
        // bottomMid ┻): the freeze seat must NOT diverge here.
        self::assertStringNotContainsString('╥', $top, 'outer top border must not carry the freeze junction');
        self::assertStringNotContainsString('╨', $bottom, 'outer bottom border must not carry the freeze junction');
        self::assertSame(4, substr_count($top, '┳'), 'four interior column junctions, freeze seat included');
        self::assertSame(4, substr_count($bottom, '┻'), 'four interior column junctions, freeze seat included');
    }

    public function testHeaderSeparatorShowsFreezeTopJunctionAtBandSeatOnly(): void
    {
        $lines = self::lines(self::grid(3));
        $headerSep = $lines[2]; // after top border + header row

        self::assertStringStartsWith('┣', $headerSep);
        self::assertSame(1, substr_count($headerSep, '╥'), 'exactly the band seat carries the freeze top junction');
        self::assertSame(3, substr_count($headerSep, '┳'), 'the other three interior seats stay plain');
    }

    public function testFooterSeparatorShowsFreezeBottomJunctionAtBandSeatOnly(): void
    {
        $lines = self::lines(self::grid(3));
        $footerSep = $lines[count($lines) - 2]; // above the bottom border

        self::assertStringStartsWith('┣', $footerSep);
        self::assertSame(1, substr_count($footerSep, '╨'), 'exactly the band seat carries the freeze bottom junction');
        self::assertSame(3, substr_count($footerSep, '┻'), 'the other three interior seats stay plain');
    }

    public function testFrozenRowsInBandModeCarryHeavyDividerOnce(): void
    {
        $lines = self::lines(self::grid(3));
        // Frame: 0 top border, 1 header, 2 header separator, 3..4 data
        // rows, 5 footer separator, 6 bottom border. The header seat and
        // every data row carry the heavy band divider exactly once. The
        // data rows prove the band SELECTOR itself: renderRow's rtrim and
        // renderFrozenRow agree byte-for-byte while the band stays inside
        // the rendered column window, so this pin only holds while the
        // loop routes band rows through the frozen renderer. With the
        // predicate disabled (M1) these bytes are unchanged, so this pin
        // cannot kill M1. The M1 kill is FrozenRowAlignmentTest: its rounded,
        // minimal and borderless sweeps fail under the mutation (P0-A14).
        foreach ([1, 3, 4] as $i) {
            self::assertSame(1, substr_count($lines[$i], '║'), "interior text row $i carries the heavy freeze divider once");
        }
    }

    public function testBandModeBeyondColumnWindowDropsDanglingDivider(): void
    {
        // frozen(5) with five columns: the band swallows the whole window, so
        // there is no scrollable seat for the divider. The row must end on the
        // closing wall exactly like the header, with no divider and no
        // overhang (P0-A14). The pins below check that geometry only; they do
        // not assert which render path produced the row.
        $lines = self::lines(self::grid(5));

        self::assertSame(0, substr_count($lines[3], '║'), 'a fully frozen row has no seat for the divider');
        self::assertStringEndsWith('┃', $lines[3]);
        self::assertStringNotContainsString('║┃', $lines[3], 'no dangling divider tail');
        self::assertSame(
            Width::string($lines[1]),
            Width::string($lines[3]),
            'the fully frozen row has the same cell count as the header',
        );
    }

    public function testFrozenZeroFrameCarriesNoFreezeGlyphs(): void
    {
        $rendered = self::grid(0)->render();

        foreach (['║', '╥', '╨'] as $glyph) {
            self::assertSame(0, substr_count($rendered, $glyph), "frozen(0) frame must not grow '$glyph'");
        }
    }

    public function testFrozenBandCountBeyondColumnsKeepsLastSeatPlain(): void
    {
        // frozen(5) with five columns: the band seat would be the final
        // column, whose junction is the right border — no interior freeze
        // seat exists, so the separators keep plain junctions throughout.
        $lines = self::lines(self::grid(5));
        $headerSep = $lines[2];

        self::assertSame(0, substr_count($headerSep, '╥'), 'out-of-range band seat emits no freeze junction');
    }
}
