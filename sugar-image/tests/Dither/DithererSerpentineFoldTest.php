<?php

declare(strict_types=1);

namespace SugarCraft\Image\Tests\Dither;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SugarCraft\Image\Dither;
use SugarCraft\Image\Dither\Ditherer;

/**
 * C-06/rev-s4-1: the opt-in serpentine edge-grain error fold.
 *
 * chafa's fs_dither re-points a BOUNDARY grain's out-of-range neighbour
 * argument onto visible cells (chafa/internal/chafa-pixops.c:376-382 forwards
 * — the leftmost grain is called with error_row[1]+1 where the 3/16 arm would
 * point off-canvas; :399-405 and the backwards pair at :417-449 carry the
 * mirror image). Its +2 padding columns (:348-363) are memset-0 layout slack
 * that no edge error is ever written into, so chafa CONSERVES row-end error.
 * SugarCraft's default walk drops those taps at the bounds test — the
 * divergence documented on {@see Ditherer::diffuseError()}'s $fold.
 *
 * The cure is opt-in and DOUBLE-GATED ($serpentineErrorFold × $serpentine):
 *  1. fold-ON recovers the strictly-positive row-end error the clamp can
 *     actually land; the trailing-edge dy=0 horizontal arms (FS 7/16, Stucki
 *     12/42, Atkinson 2/8) are dead on arrival — they fold onto the grain
 *     being diffused, whose accumulator is consumed before the write lands —
 *     and drop in both modes. Every recovered tap is pinned against an
 *     INDEPENDENTLY DERIVED spread (the tap tables below are spelled out
 *     here, never read back from the implementation);
 *  2. fold-OFF keeps the historical drop, pinned at every position so the two
 *     modes can never silently swap;
 *  3. every pre-cure byte stream is frozen. The `f0` digests (and the honest
 *     no-op `stucki|s1|f1`) were captured from the PARENT-COMMIT file — the
 *     shipped Ditherer before $fold existed at all — so they are a freeze
 *     proof, not a restatement of current output. The `f1` digests are
 *     sanctioned new streams of the opt-in path.
 */
final class DithererSerpentineFoldTest extends TestCase
{
    /**
     * Kernel taps as [dx, dy, numerator] over a shared denominator, spelled
     * independently of the implementation so a table drift reddens here.
     * Atkinson propagates 6/8 by design, so its whole is the numerator sum.
     *
     * @var array<string, array{den: float, taps: list<array{int,int,float}>}>
     */
    private const KERNELS = [
        'floyd-steinberg' => [
            'den' => 16.0,
            'taps' => [[1, 0, 7.0], [-1, 1, 3.0], [0, 1, 5.0], [1, 1, 1.0]],
        ],
        'stucki' => [
            'den' => 42.0,
            'taps' => [
                [1, 0, 8.0], [2, 0, 4.0],
                [-2, 1, 2.0], [-1, 1, 4.0], [0, 1, 8.0], [1, 1, 4.0], [2, 1, 2.0],
                [-2, 2, 1.0], [-1, 2, 2.0], [0, 2, 4.0], [1, 2, 2.0], [2, 2, 1.0],
            ],
        ],
        'atkinson' => [
            'den' => 8.0,
            'taps' => [[1, 0, 1.0], [2, 0, 1.0], [-1, 1, 1.0], [0, 1, 1.0], [1, 1, 1.0], [0, 2, 1.0]],
        ],
    ];

    /**
     * Streams over {@see canvas()} / {@see lumaGrid()}, keyed
     * `<kernel>|s<serpentine>|f<fold>` (grain legs carry `|grain`).
     *
     * Rows marked PRE-CURE were captured from the parent-commit Ditherer with
     * the $fold parameter absent; rows marked ARMED exist only under the
     * opt-in. `stucki|s1|f1` equals its `f0` twin: an honest data-dependent
     * no-op, witnessed non-vacuously by `stucki|grain|s1|f1` diverging.
     */
    private const STREAMS = [
        'fs|s0|f0' => '20aa837d2b624dec134d8aa6b2e5f40e7c14d519', // PRE-CURE
        'fs|s0|f1' => '20aa837d2b624dec134d8aa6b2e5f40e7c14d519', // inert: gate
        'fs|s1|f0' => 'b81e1f43c5b248bceb834f15c64215ddc0256701', // PRE-CURE
        'fs|s1|f1' => '8f58e42b8701e906ba8053617d2934cc429a102d', // ARMED
        'stucki|s0|f0' => 'f1bb337a0b7b5fbdd3831c1a04c1c98547cde894', // PRE-CURE
        'stucki|s0|f1' => 'f1bb337a0b7b5fbdd3831c1a04c1c98547cde894', // inert: gate
        'stucki|s1|f0' => '1f08e56578ce3a06e0693d2e33669589edf36d31', // PRE-CURE
        'stucki|s1|f1' => '1f08e56578ce3a06e0693d2e33669589edf36d31', // honest no-op
        'atkinson|s0|f0' => '37362616d21da455dba376d2a0a9bc5980bcc086', // PRE-CURE
        'atkinson|s0|f1' => '37362616d21da455dba376d2a0a9bc5980bcc086', // inert: gate
        'atkinson|s1|f0' => 'b3e8003a1b4ddcab01d8cde08ebec5c6a110f35c', // PRE-CURE
        'atkinson|s1|f1' => 'be50baadadc2798706ce9015b41d9c4882092574', // ARMED
        'fs|grain|s1|f0' => '1530320ef6776eea2c6b325524ebdfc8acebddda', // PRE-CURE
        'fs|grain|s1|f1' => 'f05e74f0467324088b9d8f2b7f8b6d08b6b5ed51', // ARMED
        'stucki|grain|s1|f0' => 'cd7d19fbde2c1669d219732ed183f460e8f6ffca', // PRE-CURE
        'stucki|grain|s1|f1' => '85c3b74339d2e292a6885e7a9f3478bfa9eb53c3', // ARMED
        'atkinson|grain|s1|f0' => '47b2570635d55ca8b2f4ea2cf211d968a73959c6', // PRE-CURE
        'atkinson|grain|s1|f1' => 'b590489d058d1b6d1434120f0295d9bb3641a573', // ARMED
        'luma|s0|f0' => '77d22f442c17323d287f97a02d2f236d076afeb7', // PRE-CURE
        'luma|s0|f1' => '77d22f442c17323d287f97a02d2f236d076afeb7', // inert: gate
        'luma|s1|f0' => '5e147d7f0c1b84905cbf758627848b6e00bffb44', // PRE-CURE
        'luma|s1|f1' => '3f00209163f79220fe3bf0c6eb0dfccfa7a94154', // ARMED
    ];

    // ─── tap level: fold-ON conserves, fold-OFF drops ────────────────────────

    public function testForwardLeftEdgeFoldRecoversTheArmThatPointsOffCanvas(): void
    {
        // FS at x=0,y=0 walking forward: the [-1,1] 3/16 arm leaves the
        // canvas. Dropped by default; folded onto column 0 of its own target
        // row when armed, so row 1 carries 5/16 + 3/16 = 8/16.
        $drop = $this->diffuse(3, 2, 0, 0, Dither::FloydSteinberg, 1, false);
        self::assertSame([0.0, 7.0, 0.0], self::redRow($drop, 0));
        self::assertSame([5.0, 1.0, 0.0], self::redRow($drop, 1));

        $fold = $this->diffuse(3, 2, 0, 0, Dither::FloydSteinberg, 1, true);
        self::assertSame([0.0, 7.0, 0.0], self::redRow($fold, 0));
        self::assertSame([8.0, 1.0, 0.0], self::redRow($fold, 1));
        self::assertSame(16.0, self::gridTotal($fold), 'the fold must conserve the whole FS error');
        self::assertSame(13.0, self::gridTotal($drop), 'the documented drop loses exactly 3/16');
    }

    public function testForwardRightEdgeFoldRecoversBothOffCanvasArms(): void
    {
        // FS at x=w-1 walking forward: the [1,0] 7/16 and [1,1] 1/16 arms
        // leave the canvas; both fold onto the last column. The in-bounds
        // [-1,1]/[0,1] arms keep their historical landing.
        $drop = $this->diffuse(3, 2, 2, 0, Dither::FloydSteinberg, 1, false);
        self::assertSame([0.0, 0.0, 0.0], self::redRow($drop, 0));
        self::assertSame([0.0, 3.0, 5.0], self::redRow($drop, 1));

        $fold = $this->diffuse(3, 2, 2, 0, Dither::FloydSteinberg, 1, true);
        self::assertSame([0.0, 0.0, 7.0], self::redRow($fold, 0));
        self::assertSame([0.0, 3.0, 6.0], self::redRow($fold, 1));
        self::assertSame(16.0, self::gridTotal($fold));
        self::assertSame(8.0, self::gridTotal($drop), 'the documented drop loses 7/16 + 1/16');
    }

    public function testReversedRowFoldsTheMirroredArmsAtBothRowEnds(): void
    {
        // Serpentine reversed row (dxSign -1): at x=0 the mirrored [1,0] and
        // [1,1] arms fly past the LEFT edge; at x=w-1 the mirrored [-1,1] arm
        // flies past the RIGHT edge. Both ends conserve under the cure.
        $left = $this->diffuse(3, 2, 0, 0, Dither::FloydSteinberg, -1, true);
        self::assertSame([7.0, 0.0, 0.0], self::redRow($left, 0));
        self::assertSame([6.0, 3.0, 0.0], self::redRow($left, 1));
        self::assertSame(16.0, self::gridTotal($left));

        $right = $this->diffuse(3, 2, 2, 0, Dither::FloydSteinberg, -1, true);
        self::assertSame([0.0, 7.0, 0.0], self::redRow($right, 0));
        self::assertSame([0.0, 1.0, 8.0], self::redRow($right, 1));
        self::assertSame(16.0, self::gridTotal($right));

        // The drop mode loses different arms per end, because the mirrored
        // taps that point off-canvas differ: at x=0 it is the 7/16 and 1/16,
        // at x=w-1 it is the 3/16.
        self::assertSame(8.0, self::gridTotal($this->diffuse(3, 2, 0, 0, Dither::FloydSteinberg, -1, false)));
        self::assertSame(13.0, self::gridTotal($this->diffuse(3, 2, 2, 0, Dither::FloydSteinberg, -1, false)));
    }

    public function testInteriorOvershootIsNeverFolded(): void
    {
        // chafa re-points only its BOUNDARY call sites. A dx=-2 Stucki arm
        // fired from column 1 is an interior grain reaching past the edge: it
        // keeps dropping, or the fold would invent a placement chafa has
        // nowhere for. Column 0 of row 1 therefore carries the 4/42 arm only
        // — a folded dx=-2 arm would show 6.0.
        $accum = $this->diffuse(5, 3, 1, 0, Dither::Stucki, 1, true);
        self::assertSame(4.0, $accum[1][0][0]);
        self::assertSame(8.0, $accum[0][2][0]);
        self::assertSame(4.0, $accum[0][3][0]);
        self::assertSame(39.0, self::gridTotal($accum), 'the off-canvas dx=-2 arms (2/42 + 1/42) stay lost');
    }

    public function testTapsAimedBelowTheLastRowStillDropWithTheFoldArmed(): void
    {
        // chafa pads COLUMNS only: its trailing error row is freed unrendered,
        // so error aimed below the canvas is lost there too. One row tall —
        // every dy>0 arm misses in BOTH modes, including the horizontally
        // folded 3/16 whose target row does not exist.
        $fold = $this->diffuse(3, 1, 0, 0, Dither::FloydSteinberg, 1, true);
        $drop = $this->diffuse(3, 1, 0, 0, Dither::FloydSteinberg, 1, false);
        self::assertSame(self::redRow($drop, 0), self::redRow($fold, 0));
        self::assertSame([0.0, 7.0, 0.0], self::redRow($fold, 0));
        self::assertSame(7.0, self::gridTotal($fold), 'the fold must not claim bottom-edge error');
    }

    public function testTopRowLosesNothingVerticallyInEitherMode(): void
    {
        // No kernel tap points upward, so the TOP edge never drops: with the
        // fold armed a boundary grain in row 0 conserves the kernel's whole
        // propagated total, while the drop mode is still lossy there.
        foreach (self::KERNELS as $name => $kernel) {
            $whole = self::wholeOf($kernel);
            $dither = self::ditherFor($name);

            self::assertSame(
                $whole,
                self::gridTotal($this->diffuse(4, 4, 0, 0, $dither, 1, true)),
                $name . ': top-row fold must conserve all of it',
            );
            self::assertLessThan(
                $whole,
                self::gridTotal($this->diffuse(4, 4, 0, 0, $dither, 1, false)),
                $name . ': the drop mode is genuinely lossy here',
            );
        }
    }

    /**
     * The census: every cell of a 5x4 accumulator, every kernel, both walk
     * directions, fold on and off. Each grid total is compared against an
     * independently derived spread; every boundary position must recover
     * strictly (side edges with room below) and no position may ever lose
     * error the drop mode kept.
     */
    public function testEdgeCensusMatchesAnIndependentlyDerivedSpreadForBothModes(): void
    {
        $w = 5;
        $h = 4;
        $positions = 0;
        $recoveries = 0;

        foreach (self::KERNELS as $name => $kernel) {
            $dither = self::ditherFor($name);

            for ($dxSign = -1; $dxSign <= 1; $dxSign += 2) {
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        $folded = $this->diffuse($w, $h, $x, $y, $dither, $dxSign, true);
                        $dropped = $this->diffuse($w, $h, $x, $y, $dither, $dxSign, false);
                        $positions++;

                        self::assertSame(
                            self::derivedTotal($kernel, $x, $y, $w, $h, $dxSign, true),
                            self::gridTotal($folded),
                            sprintf('%s dxSign=%d at (%d,%d): fold total off the derived spread', $name, $dxSign, $x, $y),
                        );
                        self::assertSame(
                            self::derivedTotal($kernel, $x, $y, $w, $h, $dxSign, false),
                            self::gridTotal($dropped),
                            sprintf('%s dxSign=%d at (%d,%d): drop total off the derived spread', $name, $dxSign, $x, $y),
                        );

                        $recovered = self::gridTotal($folded) - self::gridTotal($dropped);
                        self::assertGreaterThanOrEqual(
                            0.0,
                            $recovered,
                            sprintf('%s at (%d,%d): the fold may never LOSE error the drop kept', $name, $x, $y),
                        );

                        if ($recovered > 0.0) {
                            $recoveries++;
                        }

                        // A side-edge grain whose taps still have a row below
                        // them must gain from the cure. The bottom two rows
                        // are exempt: their downward arms are off-canvas and
                        // chafa loses exactly the same error there.
                        if (($x === 0 || $x === $w - 1) && $y <= $h - 3) {
                            self::assertGreaterThan(
                                0.0,
                                $recovered,
                                sprintf('%s at (%d,%d): side-edge fold recovered nothing', $name, $x, $y),
                            );
                        }
                    }
                }
            }
        }

        self::assertSame(120, $positions, 'the census must walk 3 kernels x 2 directions x 20 cells');
        self::assertGreaterThan(0, $recoveries, 'a fold that never recovers error is a dead switch');
    }

    // ─── stream level: the double gate and the frozen bytes ──────────────────

    /**
     * Arming the fold while the serpentine walk is disarmed must be inert.
     *
     * @return array<string, array{string, Dither}>
     */
    public static function diffusionKernels(): array
    {
        return [
            'floyd-steinberg' => ['floyd-steinberg', Dither::FloydSteinberg],
            'stucki' => ['stucki', Dither::Stucki],
            'atkinson' => ['atkinson', Dither::Atkinson],
        ];
    }

    #[DataProvider('diffusionKernels')]
    public function testFoldIsInertWhileSerpentineIsDisarmed(string $name, Dither $dither): void
    {
        $straight = $this->stream($dither, false, false);
        $foldButStraight = $this->stream($dither, false, true);

        self::assertSame($straight, $foldButStraight, $name . ': fold moved bytes without serpentine');
        self::assertSame(self::STREAMS[$this->key($name, 0, 0)], sha1(serialize($straight)), $name . ': default stream drifted');
        self::assertSame(self::STREAMS[$this->key($name, 0, 1)], sha1(serialize($foldButStraight)), $name . ': inert stream drifted');
    }

    /**
     * @return array<string, array{string, Dither}>
     */
    public static function divergingKernels(): array
    {
        return [
            'floyd-steinberg' => ['floyd-steinberg', Dither::FloydSteinberg],
            'atkinson' => ['atkinson', Dither::Atkinson],
        ];
    }

    #[DataProvider('divergingKernels')]
    public function testArmedFoldMovesTheSerpentineStream(string $name, Dither $dither): void
    {
        $dropped = $this->stream($dither, true, false);
        $folded = $this->stream($dither, true, true);

        self::assertSame(self::STREAMS[$this->key($name, 1, 0)], sha1(serialize($dropped)), $name . ': pre-cure serpentine stream drifted');
        self::assertSame(self::STREAMS[$this->key($name, 1, 1)], sha1(serialize($folded)), $name . ': sanctioned armed stream drifted');
        self::assertNotSame($dropped, $folded, $name . ': arming both gates changed nothing — the cure is dead');
    }

    public function testStuckiPixelStreamIsAnHonestNoOpWitnessedByItsGrainStream(): void
    {
        // On this canvas the Stucki pixel cascade never flips a palette
        // decision because of the recovered arms, so serpentine+fold equals
        // the pre-cure stream. Pinned BOTH ways, with the witness that the
        // very same flags over a grain walk DO move bytes: the equality is a
        // property of the stream, not of a dead switch.
        self::assertSame(
            $this->stream(Dither::Stucki, true, false),
            $this->stream(Dither::Stucki, true, true),
        );
        self::assertSame(self::STREAMS['stucki|s1|f0'], sha1(serialize($this->stream(Dither::Stucki, true, true))));
        self::assertNotSame(
            $this->grainStream(Dither::Stucki, false),
            $this->grainStream(Dither::Stucki, true),
        );
        self::assertSame(self::STREAMS['stucki|grain|s1|f0'], sha1(serialize($this->grainStream(Dither::Stucki, false))));
        self::assertSame(self::STREAMS['stucki|grain|s1|f1'], sha1(serialize($this->grainStream(Dither::Stucki, true))));
    }

    /**
     * The grain walk carries the same cure through the same gate; every
     * kernel's serpentine grain stream is pinned pre-cure AND armed, and all
     * three genuinely diverge there (no no-op hiding a dead leg).
     *
     * @return array<string, array{string, Dither}>
     */
    public static function grainKernels(): array
    {
        return self::diffusionKernels();
    }

    #[DataProvider('grainKernels')]
    public function testGrainWalkCarriesTheFoldUnderTheSameGate(string $name, Dither $dither): void
    {
        $dropped = $this->grainStream($dither, false);
        $folded = $this->grainStream($dither, true);
        $short = $this->key($name, 1, 0);
        $grainDrop = str_replace('|s1|f0', '|grain|s1|f0', $short);
        $grainArmed = str_replace('|s1|f0', '|grain|s1|f1', $short);

        self::assertSame(self::STREAMS[$grainDrop], sha1(serialize($dropped)), $name . ': pre-cure grain stream drifted');
        self::assertSame(self::STREAMS[$grainArmed], sha1(serialize($folded)), $name . ': sanctioned armed grain stream drifted');
        self::assertNotSame($dropped, $folded, $name . ': the grain fold moved nothing');
    }

    public function testLumaWalkHonoursTheDoubleGateAndMovesWhenArmed(): void
    {
        $straight = $this->lumaStream(false, false);
        $inert = $this->lumaStream(false, true);
        $serpentineDrop = $this->lumaStream(true, false);
        $armed = $this->lumaStream(true, true);

        self::assertSame($straight, $inert, 'luma fold must be inert without serpentine');
        self::assertNotSame($serpentineDrop, $armed, 'luma fold must move bytes with both gates open');
        self::assertNotSame($straight, $serpentineDrop, 'luma serpentine must still be a real walk');
        self::assertSame(self::STREAMS['luma|s0|f0'], sha1(serialize($straight)));
        self::assertSame(self::STREAMS['luma|s1|f0'], sha1(serialize($serpentineDrop)));
        self::assertSame(self::STREAMS['luma|s1|f1'], sha1(serialize($armed)));
    }

    public function testPreCureCallShapeWithoutTheSeventhArgumentIsUnmoved(): void
    {
        // AsciiRenderer still calls lumaIndexRows() with six arguments — the
        // pre-cure shape. It must keep producing the historical grid.
        $quantize = self::quantizer();
        $preCure = iterator_to_array(Ditherer::lumaIndexRows(self::lumaGrid(), $quantize, Dither::FloydSteinberg, 1.0, null, true));

        self::assertSame($this->lumaStream(true, false), array_values($preCure));
        self::assertSame(self::STREAMS['luma|s1|f0'], sha1(serialize($preCure)));
    }

    public function testDiffuseErrorCallersThatOmitTheTenthArgumentKeepTheDrop(): void
    {
        // The other half of the same law on the static surface: existing
        // callers (and the reflection pins) pass nine arguments.
        $accum = self::zeroGrid(3, 2);
        Ditherer::diffuseError($accum, 3, 2, 0, 0, 16.0, 0.0, 0.0, Dither::FloydSteinberg, 1);

        self::assertSame($this->diffuse(3, 2, 0, 0, Dither::FloydSteinberg, 1, false), $accum);
        self::assertSame(13.0, self::gridTotal($accum));
    }

    public function testAccessorsReportThePairSoTheGateIsInspectable(): void
    {
        $off = new Ditherer(dither: Dither::FloydSteinberg);
        self::assertFalse($off->serpentine());
        self::assertFalse($off->serpentineErrorFold());

        $foldOnly = new Ditherer(dither: Dither::FloydSteinberg, serpentineErrorFold: true);
        self::assertFalse($foldOnly->serpentine(), 'the flag alone must not imply the walk');
        self::assertTrue($foldOnly->serpentineErrorFold());

        $armed = new Ditherer(dither: Dither::FloydSteinberg, serpentine: true, serpentineErrorFold: true);
        self::assertTrue($armed->serpentine());
        self::assertTrue($armed->serpentineErrorFold());
    }

    public function testFeedbackFreeDithersIgnoreTheFoldEntirely(): void
    {
        // Ordered/None/Noise carry no error, so there is nothing to fold: the
        // fully armed stream must equal the plain one.
        foreach ([Dither::Ordered, Dither::None, Dither::Noise] as $dither) {
            self::assertSame(
                $this->stream($dither, false, false),
                $this->stream($dither, true, true),
                $dither->name . ': fold moved a feedback-free stream',
            );
        }
    }

    // ─── harness ─────────────────────────────────────────────────────────────

    /** @return array<int, array<int, array{float,float,float}>> */
    private function diffuse(int $w, int $h, int $x, int $y, Dither $dither, int $dxSign, bool $fold): array
    {
        $accum = self::zeroGrid($w, $h);
        // One denominator of error keeps every landing site an exact float:
        // factor * den == the tap's numerator.
        Ditherer::diffuseError($accum, $w, $h, $x, $y, self::KERNELS[self::nameFor($dither)]['den'], 0.0, 0.0, $dither, $dxSign, $fold);

        return $accum;
    }

    /** @return array<int, array<int, array{float,float,float}>> */
    private static function zeroGrid(int $w, int $h): array
    {
        $accum = [];
        for ($row = 0; $row < $h; $row++) {
            $line = [];
            for ($col = 0; $col < $w; $col++) {
                $line[] = [0.0, 0.0, 0.0];
            }
            $accum[] = $line;
        }

        return $accum;
    }

    /**
     * Where the error lands, derived from THIS file's tap tables — the oracle
     * the implementation is scored against.
     *
     * @param array{den: float, taps: list<array{int,int,float}>} $kernel
     */
    private static function derivedTotal(array $kernel, int $x, int $y, int $w, int $h, int $dxSign, bool $fold): float
    {
        $total = 0.0;
        foreach ($kernel['taps'] as [$dx, $dy, $num]) {
            $nx = $x + $dx * $dxSign;
            $ny = $y + $dy;
            if ($fold && $nx < 0 && $x === 0) {
                $nx = 0;
            } elseif ($fold && $nx >= $w && $x === $w - 1) {
                $nx = $w - 1;
            }
            if ($nx >= 0 && $nx < $w && $ny >= 0 && $ny < $h) {
                $total += $num;
            }
        }

        return round($total, 9);
    }

    /** @param array{den: float, taps: list<array{int,int,float}>} $kernel */
    private static function wholeOf(array $kernel): float
    {
        return array_sum(array_column($kernel['taps'], 2));
    }

    /** @return array<int, array<int, array{float,float,float}>> */
    private static function redRow(array $accum, int $y): array
    {
        return array_map(static fn (array $cell): float => round($cell[0], 9), $accum[$y]);
    }

    /** @param array<int, array<int, array{float,float,float}>> $accum */
    private static function gridTotal(array $accum): float
    {
        $total = 0.0;
        foreach ($accum as $row) {
            foreach ($row as $cell) {
                $total += $cell[0];
            }
        }

        return round($total, 9);
    }

    /** @return list<list<int>> */
    private function stream(Dither $dither, bool $serpentine, bool $fold): array
    {
        $ditherer = new Ditherer(dither: $dither, serpentine: $serpentine, serpentineErrorFold: $fold);

        return array_values(iterator_to_array($ditherer->indexRows(self::canvas(), self::palette(), 0)));
    }

    /** @return list<list<int>> */
    private function grainStream(Dither $dither, bool $fold): array
    {
        $ditherer = new Ditherer(
            dither: $dither,
            serpentine: true,
            grainWidth: 2,
            grainHeight: 1,
            serpentineErrorFold: $fold,
        );

        return array_values(iterator_to_array($ditherer->indexRows(self::canvas(), self::palette(), 0)));
    }

    /** @return list<list<int>> */
    private function lumaStream(bool $serpentine, bool $fold): array
    {
        return array_values(iterator_to_array(Ditherer::lumaIndexRows(
            self::lumaGrid(),
            self::quantizer(),
            Dither::FloydSteinberg,
            1.0,
            null,
            $serpentine,
            $fold,
        )));
    }

    /** @return callable(float): array{int, float} */
    private static function quantizer(): callable
    {
        return static fn (float $v): array => [
            (int) min(3, max(0, round($v / 85))),
            (int) min(3, max(0, round($v / 85))) * 85.0,
        ];
    }

    /**
     * The same eight-step ramp the capture script walked: red and blue trade
     * off across the ramp so a folded arm's small correction has a visible
     * effect on which pen a pixel picks.
     *
     * @return list<array{int,int,int}>
     */
    private static function palette(): array
    {
        $palette = [];
        for ($i = 0; $i < 8; $i++) {
            $palette[] = [intdiv($i * 255, 7), 60, intdiv((7 - $i) * 255, 7)];
        }

        return $palette;
    }

    private static function canvas(): \GdImage
    {
        $img = imagecreatetruecolor(12, 5);
        self::assertNotFalse($img);
        for ($y = 0; $y < 5; $y++) {
            for ($x = 0; $x < 12; $x++) {
                imageSetPixel(
                    $img,
                    $x,
                    $y,
                    ((($x * 17 + $y * 29) % 256) << 16) | ((($x * 13 + $y * 7) % 256) << 8) | (($x * 23 + $y * 5) % 256),
                );
            }
        }

        return $img;
    }

    /** @return list<list<float>> */
    private static function lumaGrid(): array
    {
        $rows = [];
        for ($y = 0; $y < 5; $y++) {
            $line = [];
            for ($x = 0; $x < 9; $x++) {
                $line[] = (float) (($x * 7 * 13 + $y * 7 * 29) % 256);
            }
            $rows[] = $line;
        }

        return $rows;
    }

    private function key(string $name, int $serpentine, int $fold): string
    {
        $short = ['floyd-steinberg' => 'fs', 'stucki' => 'stucki', 'atkinson' => 'atkinson'][$name];

        return $short . '|s' . $serpentine . '|f' . $fold;
    }

    private static function nameFor(Dither $dither): string
    {
        return match ($dither) {
            Dither::FloydSteinberg => 'floyd-steinberg',
            Dither::Stucki => 'stucki',
            Dither::Atkinson => 'atkinson',
            default => throw new \LogicException('not a diffusion kernel: ' . $dither->name),
        };
    }

    private static function ditherFor(string $name): Dither
    {
        return match ($name) {
            'floyd-steinberg' => Dither::FloydSteinberg,
            'stucki' => Dither::Stucki,
            'atkinson' => Dither::Atkinson,
            default => throw new \LogicException($name),
        };
    }
}
