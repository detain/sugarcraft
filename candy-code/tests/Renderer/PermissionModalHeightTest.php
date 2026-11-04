<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tests\Renderer;

use PHPUnit\Framework\TestCase;
use SugarCraft\Core\Util\Sanitize;
use SugarCraft\Core\Util\Width;
use SugarCraft\Code\Renderer;
use SugarCraft\Code\Theme;

/**
 * CC-UXD-01 (owner ruling OD-4): the permission modal SHRINKS TO FIT — its
 * box may be at most rows - 2 tall, so a hostile request (long prompt body,
 * unbounded PtyRun step blocks) can never push the answer keys below the
 * viewport, where the overlay's blind bottom-clip used to drop them.
 *
 * This file drives the private {@see Renderer::shrinkPermissionModal()}
 * directly — the arithmetic of the window (head kept, counted marker, tail
 * whole or head-of-tail) and its height invariant for every budget shape,
 * including the degenerate ones the live caller cannot build but a future
 * refactor could. The same rule end-to-end, through a real Chat holding a
 * pending permission request, is pinned in
 * {@see \SugarCraft\Code\Tests\RendererTest}: a file of its own that
 * constructs a `PermissionRequestMsg` would widen the measured domain that
 * {@see KeyHelpTest::testTheGuardMutationDomainIsTheFilesThatBuildAPermissionRequestMsg()}
 * pins for `Chat::requestPermission()`'s mutation table.
 */
final class PermissionModalHeightTest extends TestCase
{
    /** Inner width the marker is truncated to; the modal's own 60 minus chrome. */
    private const INNER = 54;

    /**
     * The invariant must hold for every budget a caller can compute
     * (`max(1, rows - 4)`), not just comfortable ones: the sweep covers the
     * whole floor-to-roomy range and asserts height, throw-freedom, and that
     * the tail (spacer + option rows) is never silently title-substituted
     * when it still fits.
     */
    public function testTheHeightInvariantHoldsForEveryBudgetShape(): void
    {
        $head = self::headLines(9);
        $tail = self::tailLines(5);

        foreach (range(1, 14) as $budget) {
            $shrunk = self::shrink([...$head, ...$tail], $budget, \count($head));

            self::assertLessThanOrEqual(
                $budget,
                \count($shrunk),
                'a box of ' . \count($shrunk) . ' content rows does not fit a budget of ' . $budget,
            );
        }
    }

    public function testNothingIsElidedWhenTheHeadStillFitsBesideTheWholeTail(): void
    {
        $head = self::headLines(3);
        $tail = self::tailLines(4);
        $lines = [...$head, ...$tail];

        // The live caller only reaches for the window when count > budget;
        // this exercises the door inside the window itself — head + marker +
        // tail already fit, so not one row is dropped.
        self::assertSame($lines, self::shrink($lines, 8, \count($head)), 'when nothing needs eliding the window is the identity');
    }

    public function testTheOptionTailSurvivesWholeAndTheMarkerCountsTheElidedHead(): void
    {
        $head = self::headLines(5);
        $tail = self::tailLines(4);

        $shrunk = self::shrink([...$head, ...$tail], 8, \count($head));

        self::assertCount(8, $shrunk);
        self::assertSame(\array_slice($head, 0, 3), \array_slice($shrunk, 0, 3), 'the head keeps its opening rows');
        self::assertSame($tail, \array_slice($shrunk, 4), 'every option row rides in the window');
        self::assertStringContainsString(
            '2 more lines',
            self::visible($shrunk[3]),
            'the marker names exactly the rows that were elided',
        );
        self::assertStringStartsWith('…', self::visible($shrunk[3]), 'the marker carries the house ellipsis lead');
    }

    public function testTheTitleIsAlwaysTheFirstSurvivingRow(): void
    {
        $head = self::headLines(12);
        $tail = self::tailLines(3);

        $shrunk = self::shrink([...$head, ...$tail], 7, \count($head));

        self::assertSame($head[0], $shrunk[0], 'a shrunk modal still says which tool is asking');
    }

    public function testAPathologicallyWideTailDegradesToItsLeadingRowsAndStillHoldsHeight(): void
    {
        $head = self::headLines(10);
        $tail = self::tailLines(8);

        $shrunk = self::shrink([...$head, ...$tail], 5, \count($head));

        self::assertSame(\array_slice($tail, 0, 5), $shrunk, 'the options-window shape: first rows of the tail, nothing else');
        self::assertLessThanOrEqual(5, \count($shrunk));
    }

    public function testABudgetOfOneKeepsASingleRowWithoutThrowing(): void
    {
        $head = self::headLines(3);
        $tail = self::tailLines(3);

        $shrunk = self::shrink([...$head, ...$tail], 1, \count($head));

        self::assertCount(1, $shrunk);
        self::assertSame($tail[0], $shrunk[0], 'the extreme window keeps the tail lead — the first row of the answer block, never the title');
    }

    public function testTheMarkerFitsTheInnerWidthOnANarrowModal(): void
    {
        $head = self::headLines(12);
        $tail = self::tailLines(2);

        $shrunk = self::shrink([...$head, ...$tail], 6, \count($head), 12);

        self::assertLessThanOrEqual(12, Width::of(self::visible($shrunk[3])));
    }

    public function testAnEmptyHeadCannotMintANegativeCountMarker(): void
    {
        // The live caller always has a title row, but the helper is total by
        // construction: a degenerate split must not fabricate '… -1 more lines'
        // (or throw) — the identity door absorbs it.
        $tail = self::tailLines(4);

        self::assertSame($tail, self::shrink($tail, 6, 0));
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @param list<string> $lines
     * @return list<string>
     */
    private static function shrink(array $lines, int $budget, int $optionsStart, int $inner = self::INNER): array
    {
        return (array) (new \ReflectionMethod(Renderer::class, 'shrinkPermissionModal'))
            ->invoke(null, $lines, $budget, $optionsStart, Theme::default(), $inner);
    }

    /**
     * @return list<string>
     */
    private static function headLines(int $count): array
    {
        return \array_values(\array_map(static fn (int $i): string => 'head-row-' . $i, range(1, $count)));
    }

    /**
     * @return list<string>
     */
    private static function tailLines(int $count): array
    {
        return \array_values(\array_map(static fn (int $i): string => 'tail-row-' . $i, range(1, $count)));
    }

    /** Strip the marker's own SGR paint so text assertions see what the user sees. */
    private static function visible(string $row): string
    {
        return Sanitize::untrusted($row);
    }
}
