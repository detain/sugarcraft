<?php

declare(strict_types=1);

namespace SugarCraft\Mouse\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Mouse\SelectionRange;

/**
 * P2-8 (d): `SelectionRange::highlight()`, ported from candy-code's
 * `Tui\TextSelection::highlight()` (the head / patch / tail splice), with the
 * style injected as a closure so sugar-mouse stays free of sugar-style.
 * `extract()` already lived here; its pins are in {@see SelectionRangeTest}.
 */
final class SelectionRangeHighlightTest extends TestCase
{
    public function testDefaultPaintIsReverseVideoOverThePlainRun(): void
    {
        $lines = SelectionRange::new(1, 7, 1, 11)->highlight(['hello world', 'second line']);

        self::assertSame([
            "hello \e[0m\e[7mworld\e[27m\e[0m",
            'second line',
        ], $lines);
    }

    public function testStreamShapeAcrossRowsKeepsTheStylingEitherSide(): void
    {
        $range = SelectionRange::new(1, 3, 3, 2, 1, 6);
        $lines = $range->highlight(
            ["\e[31mabcdef\e[0m", '', 'xyz', 'untouched'],
            static fn (string $run): string => '[' . $run . ']',
        );

        self::assertSame([
            // First row: from the start cell to the right edge; the red head
            // keeps its color and the run is repainted from plain text.
            "\e[31mab\e[0m\e[0m[cdef]\e[0m\e[31m\e[0m",
            // A blank middle row still shows as one continuous band.
            "\e[0m[      ]\e[0m",
            // Last row stops at the end cell, inclusive.
            "\e[0m[xy]\e[0mz",
            // Outside the range: byte-for-byte untouched.
            'untouched',
        ], $lines);
    }

    public function testRowsTheFrameLacksAreSkipped(): void
    {
        $lines = SelectionRange::new(2, 1, 5, 2)->highlight(['a', 'bcd']);

        self::assertSame(['a', "\e[0m\e[7mbc\e[27m\e[0md"], $lines);
    }

    public function testWideCellsAreCutOnCellBoundaries(): void
    {
        // 日 and 本 are two cells each: cells 3-4 are exactly 本.
        $lines = SelectionRange::new(1, 3, 1, 4)->highlight(['日本語'], static fn (string $run): string => '<' . $run . '>');

        self::assertSame(["日\e[0m<本>\e[0m語"], $lines);
    }

    public function testASelectionStartingOnAWideGlyphsSecondHalfKeepsTheRowWidth(): void
    {
        // 中 spans cells 1-2 and 文 cells 3-4: the span [2,3] starts on 中's
        // second half. Its covered half paints blank, 文 stays on cells 3-4,
        // and "ab" stays on cells 5-6 instead of shifting one column left.
        $lines = SelectionRange::new(1, 2, 1, 3)->highlight(['中文ab'], static fn (string $run): string => '<' . $run . '>');

        self::assertSame(["\e[0m < 文>\e[0mab"], $lines);
    }

    public function testEverySpanOverWideGlyphsKeepsTheRowsCellCount(): void
    {
        $row = '中文ab';
        $width = Width::string($row);

        for ($from = 1; $from <= $width; $from++) {
            for ($to = $from; $to <= $width; $to++) {
                [$painted] = SelectionRange::new(1, $from, 1, $to)->highlight([$row]);

                self::assertSame($width, Width::string($painted), "span [{$from},{$to}]");
            }
        }
    }

    public function testHighlightAndExtractCoverTheSameCells(): void
    {
        $frame = ['  first line  ', '  second', '  third line'];
        $range = SelectionRange::new(1, 3, 3, 7, 3, 12);

        $marked = $range->highlight($frame, static fn (string $run): string => "\x01{$run}\x02");
        $covered = [];
        foreach ($marked as $line) {
            if (preg_match("/\x01([^\x02]*)\x02/", $line, $m) === 1) {
                $covered[] = rtrim($m[1]);
            }
        }

        self::assertSame($range->extract($frame), implode("\n", $covered));
    }

    public function testExtractStopsAtTheSpanWhenItStartsOnAWideGlyphsSecondHalf(): void
    {
        // 中 spans cells 1-2. Span [2,2] covers only 中's second half, and
        // span [2,3] covers that half plus "a" (cell 3), never "b" (cell 4).
        self::assertSame('', SelectionRange::new(1, 2, 1, 2)->extract(['中ab']));
        self::assertSame('a', SelectionRange::new(1, 2, 1, 3)->extract(['中ab']));
        self::assertSame('文', SelectionRange::new(1, 2, 1, 4)->extract(['中文ab']));
    }

    public function testHighlightAndExtractCoverTheSameCellsOverWideGlyphs(): void
    {
        // No row carries a leading space of its own, so the only leading
        // blanks in a painted run are the straddler halves highlight() blanks.
        $rows = ['中文ab', 'a中b文', '日本語x'];

        foreach ($rows as $row) {
            $width = Width::string($row);
            for ($from = 1; $from <= $width; $from++) {
                for ($to = $from; $to <= $width; $to++) {
                    $range = SelectionRange::new(1, $from, 1, $to);
                    [$marked] = $range->highlight([$row], static fn (string $run): string => "\x01{$run}\x02");
                    self::assertSame(1, preg_match("/\x01([^\x02]*)\x02/", $marked, $m));

                    self::assertSame(trim($m[1]), $range->extract([$row]), "{$row} span [{$from},{$to}]");
                }
            }
        }
    }
}
