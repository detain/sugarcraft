<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Support\OutputOptimizer;

/**
 * A-02 RepeatCells arm of the streaming optimizer: identical-glyph runs
 * under an unchanged pen may encode as `ESC[count b` — but ONLY through
 * sugar-cellbuffer's RepeatGate byte arithmetic, and only when the level
 * is on. With the level off the run buffer must flush byte-identical to
 * the historical inline appends (the frozen mosaic goldens' guarantee).
 */
final class OutputOptimizerRepeatTest extends TestCase
{
    public function testDisabledLevelFlushesLiteralBytesUnchanged(): void
    {
        $plain = new OutputOptimizer();
        $plain->cell('a', OutputOptimizer::trueColor(1, 2, 3));
        $plain->cell('a', OutputOptimizer::trueColor(1, 2, 3));
        for ($i = 0; $i < 30; $i++) {
            $plain->blank();
        }

        self::assertSame(
            "\x1b[38;2;1;2;3ma" . str_repeat('a', 1) . str_repeat(' ', 30) . "\x1b[39m",
            $plain->finishRow(),
            'mask-off rows are the pre-A-02 byte stream (run buffering is invisible)',
        );
    }

    public function testLosingRunsStayLiteralEvenWhenEnabled(): void
    {
        $pen = new OutputOptimizer(repeatCells: true);
        $pen->cell('a');
        $pen->cell('a');
        $pen->cell('a');
        $pen->cell('a'); // total run 4 → REP count 3 costs 4 bytes vs literal 3

        self::assertSame('aaaa', $pen->finishRow(), 'sub-threshold ASCII run keeps the literal form');
    }

    public function testWinningAsciiRunBecomesOneRepSequence(): void
    {
        $pen = new OutputOptimizer(repeatCells: true);
        for ($i = 0; $i < 40; $i++) {
            $pen->blank();
        }

        self::assertSame(" \x1b[39b", $pen->finishRow(), 'the survey REP-forgery shape: 40 spaces must carry ESC[39b');
    }

    /**
     * The pen is the run key, not the glyph: a same-glyph cell with a NEW
     * colour must split the run (the delta SGR writes between the halves),
     * and the attribute toggle must flush before its bytes land.
     */
    public function testPenChangeSplitsTheRun(): void
    {
        $pen = new OutputOptimizer(repeatCells: true);
        $pen->cell('x', OutputOptimizer::trueColor(1, 1, 1));
        $pen->cell('x', OutputOptimizer::trueColor(1, 1, 1));
        $pen->cell('x', OutputOptimizer::trueColor(2, 2, 2));

        self::assertSame(
            "\x1b[38;2;1;1;1mxx\x1b[38;2;2;2;2mx\x1b[39m",
            $pen->finishRow(),
            'two-cell first arm (REP would lose), then the re-armed pen for the third cell',
        );
    }

    public function testAttributeToggleFlushesBeforeItWrites(): void
    {
        $pen = new OutputOptimizer(repeatCells: true);
        for ($i = 0; $i < 60; $i++) {
            $pen->blank();
        }
        $pen->attribute('inverse', true);
        $pen->cell('z');

        $row = $pen->finishRow();
        self::assertStringStartsWith(" \x1b[59b\x1b[7mz", $row);
        self::assertStringEndsWith("\x1b[27m", $row, 'only the touched attribute channel is restored');
    }

    /** Multi-byte glyphs flip the gate early: ▀×4 (3-byte) wins at count 3. */
    public function testWideRuneWinsEarly(): void
    {
        $pen = new OutputOptimizer(repeatCells: true);
        $fg = OutputOptimizer::trueColor(9, 9, 9);
        $pen->cell("\u{2580}", $fg);
        $pen->cell("\u{2580}", $fg);
        $pen->cell("\u{2580}", $fg);
        $pen->cell("\u{2580}", $fg);

        self::assertSame(
            "\x1b[38;2;9;9;9m\u{2580}\x1b[3b\x1b[39m",
            $pen->finishRow(),
            '9 literal bytes lose to the 4-byte frame',
        );
    }

    public function testFinishRowFlushesTheTailRun(): void
    {
        $pen = new OutputOptimizer(repeatCells: true);
        $pen->cell('k');
        $pen->cell('k');
        $pen->cell('k');
        $pen->cell('k');
        $pen->cell('k');
        $pen->cell('k'); // count 5 → strict win

        self::assertSame("k\x1b[5b", $pen->finishRow(), 'the row-end flush is the last gate stop');
    }
}
