<?php

declare(strict_types=1);

namespace SugarCraft\CodeShot\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\CodeShot\AnsiParser;
use SugarCraft\CodeShot\TabStops;

/**
 * cs-05 — tab expansion rides a running column counter.
 *
 * The historic execute() re-measured the whole pending buffer with
 * `Width::of($this->textBuf)` AND looped over every flushed segment on each
 * HT, so a line of T tabs cost O(T·W) — measured 16.4 s at 10k tabs and
 * >230 s at 40k. The cure keeps an O(1) cell-count per printed codepoint.
 * These tests pin both halves of the promise: the wall-clock bound (which
 * the old arithmetic cannot meet — the mutation proof), and byte-identical
 * segment text against the whole-buffer measure, including the cluster-merge
 * shapes (emoji modifiers, ZWJ sequences, combining marks, regional
 * indicators) a naive per-codepoint sum would get wrong.
 */
final class TabExpansionPerfTest extends TestCase
{
    /** Join every segment's text (one segment carries the run between SGRs). */
    private static function textOf(array $segments): string
    {
        return implode('', array_map(static fn ($s) => $s->text, $segments));
    }

    public function testLineOfTwentyThousandTabsParsesInsideTheWallClockBound(): void
    {
        // Machine-dependent bound, deliberately generous: the pre-cure
        // arithmetic needed ~16.4 s for 10k tabs on the auditing host, i.e.
        // quadratically north of 60 s here — this leg REDDENS on the old
        // execute() and clears in milliseconds on the running counter.
        $segments = [];
        $start = microtime(true);
        $segments = AnsiParser::parse('x' . str_repeat("\t", 20000));
        $elapsed = microtime(true) - $start;

        self::assertLessThan(2.0, $elapsed, '20k tabs must expand on a running column, not a re-measure');

        // Pad arithmetic: 'x' sits on column 1, the first tab pads 7 to the
        // stop, and every later tab starts on an exact stop so it advances
        // the full 8 — total 160000 cells, all in one pending run.
        $text = self::textOf($segments);
        self::assertSame(1 + 7 + 8 * 19999, strlen($text));
        self::assertSame('x' . str_repeat(' ', 160000 - 1), $text);
    }

    public function testEmojiModifierBeforeTabPadsLikeTheWholeBufferMeasure(): void
    {
        // 😀 + skin-tone modifier is ONE cluster two cells wide (the modifier
        // rides the base emoji, see the sugar-core Width census note). A
        // per-codepoint sum would charge 2+2 and pad 4 instead of 6.
        $line = "\u{1F600}\u{1F3FD}\tB";
        $segments = AnsiParser::parse($line);

        self::assertSame(["\u{1F600}\u{1F3FD}" . str_repeat(' ', 6) . 'B'], array_map(static fn ($s) => $s->text, $segments));
    }

    public function testZwjSequenceBeforeTabPadsLikeTheWholeBufferMeasure(): void
    {
        // 👨 ZWJ 👩 joins into a single cluster (UAX#29 GB9/GB11); its width
        // is the first codepoint's — 2 cells — so the tab pads 6. The old
        // whole-buffer measure agrees; a naive codepoint sum (2+0+2) would
        // not.
        $line = "\u{1F468}\u{200D}\u{1F469}\tB";
        $segments = AnsiParser::parse($line);

        self::assertSame(["\u{1F468}\u{200D}\u{1F469}" . str_repeat(' ', 6) . 'B'], array_map(static fn ($s) => $s->text, $segments));
    }

    public function testCombiningMarkAndRegionalIndicatorsChargeOncePerCluster(): void
    {
        // e + U+0301 is one 1-cell cluster → pad 7.
        $mark = AnsiParser::parse("e\u{0301}\tz");
        self::assertSame(["e\u{0301}" . str_repeat(' ', 7) . 'z'], array_map(static fn ($s) => $s->text, $mark));

        // US flag (RI pair) is one 2-cell cluster → pad 6.
        $flag = AnsiParser::parse("\u{1F1FA}\u{1F1F8}\tz");
        self::assertSame(["\u{1F1FA}\u{1F1F8}" . str_repeat(' ', 6) . 'z'], array_map(static fn ($s) => $s->text, $flag));
    }

    public function testTabOnAnExactStopAdvancesTheFullEight(): void
    {
        $segments = AnsiParser::parse("\tb");
        self::assertSame([str_repeat(' ', 8) . 'b'], array_map(static fn ($s) => $s->text, $segments));

        // Eight spaces put the column back on a stop: the next tab is 8 again.
        $segments = AnsiParser::parse(str_repeat(' ', 8) . "\tb");
        self::assertSame([str_repeat(' ', 8) . str_repeat(' ', 8) . 'b'], array_map(static fn ($s) => $s->text, $segments));
    }

    public function testFlushedSegmentsFoldIntoTheRunningColumn(): void
    {
        // The SGR mid-line forces a flush; the counter must keep charging the
        // already-flushed run, exactly as the historic segment loop did.
        $segments = AnsiParser::parse("aaa\u{1b}[31m\tb");
        self::assertCount(2, $segments);
        self::assertSame('aaa', $segments[0]->text);
        self::assertSame(str_repeat(' ', 5) . 'b', $segments[1]->text);

        // Two flushes before the tab: 2 + 3 cells, pad 3.
        // cs-25 DISCLOSED re-pin: `SGR 7` (inverse) is untracked, so the
        // state does not change at that dispatch — the run after it is
        // style-identical to the bold "bbb" and merges into it (-047).
        // The cell accounting above is untouched: the flush pays its cells
        // before the merge decision.
        $segments = AnsiParser::parse("aa\u{1b}[1mbbb\u{1b}[7m\tc");
        self::assertCount(2, $segments);
        self::assertSame('bbb' . str_repeat(' ', 3) . 'c', $segments[1]->text);
    }

    public function testWideCharactersAdvanceTheColumnByTwoCellsPerCluster(): void
    {
        // 宽 (2) + 宽 (2) = 4 cells → tab pads 4.
        $segments = AnsiParser::parse("\u{5bbd}\u{5bbd}\tz");
        self::assertSame(["\u{5bbd}\u{5bbd}" . str_repeat(' ', 4) . 'z'], array_map(static fn ($s) => $s->text, $segments));
    }

    public function testPaintAndBudgetHomesShareOneStopRule(): void
    {
        // The twin walkers (PHP-CS-07) now read the pad from one definition:
        // handler paint columns and LayoutCalculator frame budget cannot
        // disagree on where a stop is.
        self::assertSame(8, TabStops::CELL_COUNT);
        self::assertSame(7, TabStops::pad(1));
        self::assertSame(8, TabStops::pad(8));
        self::assertSame(1, TabStops::pad(7));
        self::assertSame(6, TabStops::pad(2));
    }
}
