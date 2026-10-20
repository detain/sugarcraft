<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Render;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Render\Bands;

/**
 * D-02a: bands partition rows into unit-aligned [start, end) ranges. The
 * load-bearing property is band-split equality — walking every band of a
 * partition and stitching must visit each row of the whole range exactly
 * once, the same precondition C-04 pinned for texture phase
 * (OrderedTextureTest::testOrderedGridRowsAreInvariantToBandSplitting),
 * here proven against a pure row-label function because no renderer
 * consumes Bands yet (S7.6 wires the callers).
 */
final class BandsTest extends TestCase
{
    /**
     * D-02a: the two public numbers are part of the contract — chafa's
     * SIXEL_CELL_HEIGHT (chafa-sixel-renderer.c:31) and the plan's cost-gate
     * threshold (S5.2 / survey D-02a free-win 2).
     */
    public function testConstantsPinTheSixelUnitAndTheCostGate(): void
    {
        $this->assertSame(6, Bands::SIXEL_UNIT);
        $this->assertSame(64, Bands::COST_GATE_ROWS);
    }

    /**
     * D-02a: zero rows carry no work — chafa returns before creating any
     * batch (chafa-batch.c:92-93), so the partition is the empty list, not
     * one empty band.
     */
    public function testZeroRowsProduceNoBands(): void
    {
        $this->assertSame([], Bands::partition(0));
        $this->assertSame([], Bands::partition(0, unit: 6, maxBands: 24));
    }

    /**
     * D-02a: nonsense geometry is refused loudly at the door — a negative
     * canvas or a zero/negative unit or band ceiling.
     *
     * @param array{int, int, int} $args
     */
    #[DataProvider('refusedGeometryArguments')]
    public function testNonsenseGeometryIsRefusedLoudly(array $args, string $expectedMessage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($expectedMessage);

        Bands::partition(...$args);
    }

    /**
     * @return \Generator<string, array{array{int, int, int}, string}>
     */
    public static function refusedGeometryArguments(): \Generator
    {
        yield 'negative row count' => [[-1, 6, 4], 'non-negative row count, got -1'];
        yield 'zero unit' => [[100, 0, 4], 'unit must be at least one row, got 0'];
        yield 'negative unit' => [[100, -3, 4], 'unit must be at least one row, got -3'];
        yield 'zero band ceiling' => [[100, 6, 0], 'ceiling must be at least one, got 0'];
        yield 'negative band ceiling' => [[100, 6, -5], 'ceiling must be at least one, got -5'];
    }

    /**
     * D-02a: the whole contract as one sweep — heights crossing the cost
     * gate and unit remainders, checked for contiguous exact coverage,
     * non-emptiness, unit alignment of every non-final band, and the two
     * count ceilings (maxBands, and the row count itself). Violations are
     * collected so the sweep costs one assertion regardless of size, the
     * house invariant-sweep shape.
     */
    public function testThePartitionContractHoldsAcrossAHeightUnitCeilingSweep(): void
    {
        $heights = [1, 2, 5, 6, 7, 11, 12, 13, 63, 64, 65, 70, 120, 126, 127, 128, 200, 255, 256, 257, 512, 1000];
        $units = [1, 2, 5, 6, 7];
        $ceilings = [1, 2, 3, 5, 8, 13, 24, 100];

        $violations = [];
        foreach ($heights as $totalRows) {
            foreach ($units as $unit) {
                foreach ($ceilings as $maxBands) {
                    $bands = Bands::partition($totalRows, $unit, $maxBands);
                    $label = "rows={$totalRows} unit={$unit} max={$maxBands}";

                    if ($bands === []) {
                        $violations[] = "{$label}: empty partition for positive rows";
                        continue;
                    }
                    if (count($bands) > $maxBands || count($bands) > $totalRows) {
                        $violations[] = "{$label}: band count " . count($bands) . ' exceeds a ceiling';
                    }
                    if ($bands[0][0] !== 0 || $bands[count($bands) - 1][1] !== $totalRows) {
                        $violations[] = "{$label}: does not cover [0,{$totalRows})";
                    }
                    foreach ($bands as $i => [$start, $end]) {
                        if ($start >= $end) {
                            $violations[] = "{$label}: band {$i} is empty";
                        }
                        $isFinal = $i === count($bands) - 1;
                        if (!$isFinal && ($bands[$i + 1][0] !== $end || ($end - $start) % $unit !== 0)) {
                            $violations[] = "{$label}: band {$i} not contiguous/unit-aligned";
                        }
                        if ($isFinal && $end !== $totalRows) {
                            $violations[] = "{$label}: final band ends at {$end}, not {$totalRows}";
                        }
                    }
                }
            }
        }

        $this->assertSame([], $violations);
    }

    /**
     * D-02a: the remainder lands in the last band (chafa-batch.c:129-131
     * clamps only the final row_ofs). 70 rows at unit 6 across two bands is
     * 36 (six full sixel groups) + 34 (short, still exact).
     */
    public function testTheFinalBandCarriesTheShortRemainder(): void
    {
        $this->assertSame([[0, 36], [36, 70]], Bands::partition(70, 6, 2));
        $this->assertSame([[0, 24], [24, 48], [48, 70]], Bands::partition(70, 6, 3));
    }

    /**
     * D-02a: asking for more bands than there are whole units clamps the
     * count instead of emitting empty bands — chafa's truncation leg
     * (chafa-batch.c:134-139) is structural here. 256 rows at unit 6 is 43
     * units; 100 requested bands collapse to 43 non-empty ones.
     */
    public function testMoreBandsThanUnitsClampWithoutEmptying(): void
    {
        $bands = Bands::partition(256, 6, 100);

        $this->assertCount(43, $bands);
        $this->assertSame(0, $bands[0][0]);
        $this->assertSame(256, $bands[42][1]);
        foreach ($bands as [$start, $end]) {
            $this->assertGreaterThan($start, $end, 'no band may be empty');
        }
    }

    /**
     * D-02a: cost gate on both sides — 63 rows is one covering band no
     * matter how much parallelism is asked for; 64 rows is the first height
     * allowed to split, and at unit 1 the split is exact eighths.
     */
    public function testTheCostGateBoundaryCollapsesShortCanvasesOnly(): void
    {
        $this->assertSame([[0, 63]], Bands::partition(63, 6, 8));
        $this->assertSame([[0, 63]], Bands::partition(63, 1, 64));
        $this->assertSame([[0, 62]], Bands::partition(62, 7, 9));
    }

    /**
     * D-02a: same gate, positive leg — at exactly 64 rows unit-1 groups do
     * split into the requested ceiling.
     */
    public function testTheCostGateReleasesAtItsThreshold(): void
    {
        $this->assertSame(
            [[0, 8], [8, 16], [16, 24], [24, 32], [32, 40], [40, 48], [48, 56], [56, 64]],
            Bands::partition(64, 1, 8),
        );
        $this->assertGreaterThan(1, count(Bands::partition(64, 6, 8)));
    }

    /**
     * D-02a: the inline-when-single polarity (chafa-batch.c:153-159 — the
     * caller runs the one batch itself). A single-band ask never splits,
     * however tall the canvas, and below the gate the partition is always
     * exactly one band, so consumers keying on count($bands) === 1 take the
     * inline path for both doors.
     */
    public function testSingleBandAskAndGateBothYieldTheInlineShape(): void
    {
        $this->assertSame([[0, 1000]], Bands::partition(1000, 6, 1));

        foreach ([1, 7, 32, 63] as $rows) {
            $bands = Bands::partition($rows, 6, 24);
            $this->assertCount(1, $bands, "height {$rows} must collapse to one inline band");
            $this->assertSame([[0, $rows]], $bands);
        }
    }

    /**
     * D-02a: band-split equality in the C-04 pin shape — a pure function of
     * the ABSOLUTE row, evaluated across the whole canvas, must equal the
     * same function stitched band-by-band (every row once, in order). The
     * second leg keeps it non-vacuous: stitching with band-LOCAL rows
     * (the wrong-phase bug a consumer could write) must differ.
     */
    public function testStitchingBandsEqualsAWholeCanvasPassOfTheSameFunction(): void
    {
        $labelRow = static fn (int $row): string => sprintf('r%04d.%02d', $row, $row % Bands::SIXEL_UNIT);

        $totalRows = 200;
        $wholeCanvas = array_map($labelRow, range(0, $totalRows - 1));

        foreach ([2, 3, 5, 7, 13, 64] as $maxBands) {
            $stitched = [];
            $wrongPhase = [];
            foreach (Bands::partition($totalRows, Bands::SIXEL_UNIT, $maxBands) as [$start, $end]) {
                for ($row = $start; $row < $end; $row++) {
                    $stitched[] = $labelRow($row);
                    $wrongPhase[] = $labelRow($row - $start);
                }
            }

            $this->assertSame($wholeCanvas, $stitched, "partition at {$maxBands} bands must cover each row once");
            $this->assertNotSame(
                $wholeCanvas,
                $wrongPhase,
                'the fixture must cross band boundaries — a same-length canvas proves nothing',
            );
        }
    }

    /**
     * D-02a: pure function, so repeated calls answer identically — the
     * partition must not carry hidden state between invocations.
     */
    public function testPartitionIsDeterministicAcrossRepeatedCalls(): void
    {
        foreach ([64, 100, 256, 1000] as $rows) {
            $this->assertSame(
                Bands::partition($rows, 6, 7),
                Bands::partition($rows, 6, 7),
                "rows={$rows} must partition identically on every call",
            );
        }
    }
}
