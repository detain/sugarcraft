<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Tests\Commands;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Width;
use SugarCraft\Crush\Commands\TranscriptTable;

/**
 * The layout invariants {@see TranscriptTable} exists to hold, pinned on
 * RENDERED OUTPUT rather than on the source that produced it.
 *
 * @internal
 *
 * @see TranscriptTable
 */
final class TranscriptTableTest extends TestCase
{
    public function testCellLeavesTextThatFitsAlone(): void
    {
        $this->assertSame('read, write', TranscriptTable::cell('read, write', 13));
    }

    public function testCellClipsToTheBudgetAndMarksTheClip(): void
    {
        $clipped = TranscriptTable::cell(str_repeat('x', 40), 10);

        $this->assertSame(str_repeat('x', 9) . '…', $clipped);
        $this->assertSame(10, Width::string($clipped));
    }

    /**
     * The whole point of the change: the old code measured `strlen()`.
     * `Descripción` is 11 CELLS and 12 BYTES, so a byte-budget clips it one
     * character early and — worse — could cut mid-sequence.
     */
    public function testCellBudgetsCellsNotBytes(): void
    {
        $text = 'Descripción';

        $this->assertSame(12, \strlen($text), 'fixture must be multi-byte for this to mean anything');
        $this->assertSame(11, Width::string($text));
        $this->assertSame($text, TranscriptTable::cell($text, 11));
    }

    /**
     * A double-width grapheme costs two cells, so a 5-cell budget holds two
     * of them plus the ellipsis, not five.
     */
    public function testCellCountsDoubleWidthGraphemesAsTwoCells(): void
    {
        $clipped = TranscriptTable::cell('日本語テキスト', 5);

        $this->assertLessThanOrEqual(5, Width::string($clipped));
        $this->assertStringEndsWith('…', $clipped);
    }

    /**
     * ANSI is stripped on the way through, in BOTH branches — the fits case
     * and the clipped case. This is what stops a preset that names itself
     * `"\033[31mroot"` from putting an escape into the transcript, which the
     * source-reading {@see NoRawAnsiInTranscriptTest} cannot see.
     */
    public function testCellStripsAnsiFromTextThatFits(): void
    {
        $this->assertSame('root', TranscriptTable::cell("\033[31mroot\033[0m", 20));
    }

    public function testCellStripsAnsiFromTextItAlsoClips(): void
    {
        $clipped = TranscriptTable::cell("\033[31m" . str_repeat('a', 40) . "\033[0m", 8);

        $this->assertStringNotContainsString("\033", $clipped);
        $this->assertSame(str_repeat('a', 7) . '…', $clipped);
    }

    public function testCellRefusesANonPositiveBudget(): void
    {
        $this->assertSame('', TranscriptTable::cell('anything', 0));
        $this->assertSame('', TranscriptTable::cell('anything', -3));
    }

    /**
     * `maxCells()` is only worth having if it is EXACT: it is what makes
     * `Table::width()` a backstop that never fires. Fill every column to its
     * budget and the rendered width must equal the prediction, not merely
     * stay under it.
     */
    public function testMaxCellsPredictsTheRenderedWidthExactly(): void
    {
        $columns = ['A' => 6, 'Bee' => 10, 'C' => 4];

        $rendered = TranscriptTable::headed($columns)
            ->row(str_repeat('x', 6), str_repeat('y', 10), str_repeat('z', 4))
            ->render();

        $lines = explode("\n", $rendered);
        foreach ($lines as $i => $line) {
            $this->assertSame(
                TranscriptTable::maxCells($columns),
                Width::string($line),
                "line {$i} does not match the predicted width",
            );
        }
    }

    /**
     * A header wider than its own budget widens the column, so the
     * prediction has to take the larger of the two or it under-reports.
     */
    public function testMaxCellsAccountsForAHeaderWiderThanItsBudget(): void
    {
        $columns = ['Description' => 3];

        $rendered = TranscriptTable::headed($columns)->row('x')->render();

        $this->assertSame(
            TranscriptTable::maxCells($columns),
            Width::string(explode("\n", $rendered)[0]),
        );
        $this->assertSame(15, TranscriptTable::maxCells($columns), '11-cell header + 3 padding/border + 1');
    }

    /**
     * Every row the same width is the property the hand-built columns kept
     * losing. Assert it across a mix of ASCII, accented and CJK cells.
     */
    public function testEveryRenderedRowIsTheSameWidth(): void
    {
        $columns = ['Name' => 14, 'Note' => 20];

        $rendered = TranscriptTable::headed($columns)
            ->row(TranscriptTable::cell('ascii', 14), TranscriptTable::cell('plain', 20))
            ->row(TranscriptTable::cell('révisé-ünicodé', 14), TranscriptTable::cell('Descripción', 20))
            ->row(TranscriptTable::cell('日本語テキスト', 14), TranscriptTable::cell('ok', 20))
            ->render();

        $widths = array_map(
            static fn (string $line): int => Width::string($line),
            explode("\n", $rendered),
        );

        $this->assertCount(7, $widths, 'top + header + separator + 3 body rows + bottom');
        $this->assertSame([$widths[0]], array_values(array_unique($widths)));
    }

    /** The border is box-drawing, never SGR. */
    public function testRenderedTableCarriesNoEscapeBytes(): void
    {
        $rendered = TranscriptTable::headed(['A' => 5, 'B' => 5])->row('x', 'y')->render();

        $this->assertStringNotContainsString("\033", $rendered);
    }

    // -------------------------------------------------------------------------
    // fit() — the pane the table has to live in
    // -------------------------------------------------------------------------

    /**
     * The number this class fits INSIDE has to be the number
     * {@see \SugarCraft\Crush\Renderer} actually wraps the transcript at,
     * or "fits the pane" means two different things on the two sides of the
     * `ob_start()` capture. `Renderer::SHELL_CHROME_COLS` is private, so this
     * reads it by reflection rather than restating it — a restated constant is
     * the drift this whole change-set is about.
     */
    public function testChromeColsMatchesTheRenderersOwnShellChrome(): void
    {
        $constant = (new \ReflectionClass(\SugarCraft\Crush\Renderer::class))
            ->getReflectionConstant('SHELL_CHROME_COLS');
        self::assertNotFalse($constant, 'Renderer must still declare SHELL_CHROME_COLS');

        $this->assertSame(
            $constant->getValue(),
            TranscriptTable::CHROME_COLS,
            'the transcript is wrapped at cols() - SHELL_CHROME_COLS, so a table that fits '
                . 'cols() - CHROME_COLS only fits the pane while the two agree',
        );
    }

    /** A table that already fits is returned untouched, not re-derived. */
    public function testFitLeavesBudgetsAloneWhenTheyAlreadyFit(): void
    {
        $columns = ['Server' => 30, 'Status' => 16];

        $this->assertSame($columns, TranscriptTable::fit($columns, 200));
        $this->assertSame($columns, TranscriptTable::fit($columns, TranscriptTable::maxCells($columns)));
    }

    /**
     * The whole point: after fitting, the DERIVED cap is within the pane. Swept
     * rather than spot-checked, because the remainder hand-back is the step
     * most likely to overshoot by one.
     */
    public function testFitAlwaysProducesATableThatFitsThePane(): void
    {
        $columns = ['Server' => 30, 'Status' => 16, 'Expires' => 16, 'Scopes' => 13];

        for ($pane = 60; $pane <= 88; $pane++) {
            $this->assertLessThanOrEqual(
                $pane,
                TranscriptTable::maxCells(TranscriptTable::fit($columns, $pane)),
                "fit() overshot a {$pane}-cell pane",
            );
        }
    }

    /** And it does not UNDERSHOOT by more than the rounding it cannot avoid. */
    public function testFitUsesThePaneItIsGiven(): void
    {
        $columns = ['Server' => 30, 'Status' => 16, 'Expires' => 16, 'Scopes' => 13];

        for ($pane = 60; $pane <= 87; $pane++) {
            $this->assertGreaterThanOrEqual(
                $pane,
                TranscriptTable::maxCells(TranscriptTable::fit($columns, $pane)) + 1,
                "fit() left more than one cell of a {$pane}-cell pane unused",
            );
        }
    }

    /**
     * A floored column keeps its cells and the free-text columns pay. This is
     * the difference from {@see \SugarCraft\Sprinkles\Table\Table::width()}'s
     * proportional shrink, which was measured clipping `2026-08-22 03:00` to
     * `2026-08-22 03`.
     */
    public function testFitDoesNotShrinkAFlooredColumn(): void
    {
        $columns = ['Server' => 30, 'Status' => 16, 'Expires' => 16, 'Scopes' => 13];
        $floors = ['Status' => 15, 'Expires' => 16];

        // 74 is the pane an 80-column terminal actually gives, so this is the
        // case a user hits rather than a chosen number.
        $this->assertSame(
            ['Server' => 20, 'Status' => 16, 'Expires' => 16, 'Scopes' => 9],
            TranscriptTable::fit($columns, 74, $floors),
            'the free-text columns take the whole loss before either floor is reached',
        );

        // 60, where the Status floor binds and Expires still has not moved.
        $tight = TranscriptTable::fit($columns, 60, $floors);
        $this->assertSame(16, $tight['Expires'], 'a timestamp column clipped is a wrong date, not a short one');
        $this->assertSame(15, $tight['Status']);
        $this->assertSame(60, TranscriptTable::maxCells($tight));
    }

    /** Without the floors the same pane DOES shrink them — the floors are load-bearing. */
    public function testWithoutAFloorTheTimestampColumnWouldShrink(): void
    {
        $unfloored = TranscriptTable::fit(
            ['Server' => 30, 'Status' => 16, 'Expires' => 16, 'Scopes' => 13],
            60,
        );

        $this->assertLessThan(16, $unfloored['Expires'], 'the proportional split alone mangles the timestamp');
    }

    /** A header is the implicit floor: no column renders narrower than its own label. */
    public function testNoColumnIsFittedBelowItsHeader(): void
    {
        $columns = ['Description' => 36, 'Agent' => 20, 'Status' => 10];

        foreach ([80, 60, 40, 20, 1] as $pane) {
            foreach (TranscriptTable::fit($columns, $pane) as $header => $budget) {
                $this->assertGreaterThanOrEqual(
                    Width::string((string) $header),
                    $budget,
                    "{$header} was fitted below its own header at a {$pane}-cell pane",
                );
            }
        }
    }

    /**
     * A floor above its own budget is clamped down rather than widening the
     * table — otherwise a caller could make the box bigger by asking for a
     * minimum, which is the opposite of what a floor is for.
     */
    public function testAFloorWiderThanItsBudgetDoesNotWidenTheTable(): void
    {
        $columns = ['Server' => 30, 'Scopes' => 13];

        $this->assertLessThanOrEqual(
            50,
            TranscriptTable::maxCells(TranscriptTable::fit($columns, 50, ['Scopes' => 999])),
        );
    }

    public function testFitOnNoColumnsIsEmptyRatherThanADivisionByZero(): void
    {
        $this->assertSame([], TranscriptTable::fit([], 80));
    }
}
