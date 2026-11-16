<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Tests\Canvas;

use PHPUnit\Framework\TestCase;
use SugarCraft\Charts\Canvas\Canvas;
use SugarCraft\Charts\Canvas\Graph;

/**
 * The constant fill rune is measured once per call, not once per cell
 * (sugar-charts-113), and Graph::fillRect now paints through the same
 * hoisted Canvas::fill instead of a per-cell setCell loop. The hoist
 * must be invisible: byte-identical output everywhere.
 */
final class FillHoistTest extends TestCase
{
    /** Era gate host (bare, best of 3): hoisted 171ms vs re-inlined 256ms. */
    private const LEGACY_CEILING_MS = 200.0;

    /** Dated 2026-11-16 CI-F4: hoisted measured 214.5-280.0ms under PCOV; re-inlined 460.7ms. */
    private const COVERAGE_CEILING_MS = 400.0;

    /** Hoisted measured 0.51-0.59 of the control; the re-inlined signature is 0.77-0.81. */
    private const MAX_HOIST_RATIO = 0.70;

    public function testFillIsByteIdenticalToPerCellLoop(): void
    {
        foreach (['x', '日', ''] as $rune) {
            $a = new Canvas(9, 3);
            $a->fill(1, 0, 7, 2, $rune);
            $b = new Canvas(9, 3);
            for ($y = 0; $y <= 2; $y++) {
                for ($x = 1; $x <= 7; $x++) {
                    $b->setCell($x, $y, $rune);
                }
            }
            self::assertSame($b->view(), $a->view(), "fill vs per-cell setCell diverge for rune {$rune}");
        }
    }

    public function testFillHandlesSwappedCoordinates(): void
    {
        $a = new Canvas(5, 2);
        $a->fill(4, 1, 0, 0, 'x');
        $b = new Canvas(5, 2);
        $b->fill(0, 0, 4, 1, 'x');
        self::assertSame($b->view(), $a->view());
    }

    public function testFillRectDelegatesByteIdenticallyIncludingClamping(): void
    {
        // Old behavior = per-cell setCell, out-of-range writes silently
        // ignored; new = Canvas::fill clamps the same rect. Both must
        // paint the identical pixels for rects crossing every edge.
        foreach ([[0, 0, 4, 4], [-3, -2, 10, 12], [6, 6, 20, 20], [9, 9, 0, 0]] as [$x0, $y0, $x1, $y1]) {
            $a = new Canvas(6, 6);
            Graph::fillRect($a, $x0, $y0, $x1, $y1, '▒');
            $b = new Canvas(6, 6);
            if ($x0 > $x1) {
                [$x0, $x1] = [$x1, $x0];
            }
            if ($y0 > $y1) {
                [$y0, $y1] = [$y1, $y0];
            }
            for ($y = $y0; $y <= $y1; $y++) {
                for ($x = $x0; $x <= $x1; $x++) {
                    $b->setCell($x, $y, '▒');
                }
            }
            self::assertSame($b->view(), $a->view(), "fillRect delegation changed pixels for rect [{$x0},{$y0},{$x1},{$y1}]");
        }
    }

    public function testFillRectStillRefusesMultiCellRunes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Graph::fillRect(new Canvas(4, 4), 0, 0, 3, 3, 'ab');
    }

    /**
     * The hoisted fill must stay materially faster than the per-cell
     * measurement it replaced — on every host, in every run mode.
     *
     * Dated 2026-11-16 (CI-F4): the Coverage leg reddened persistently
     * (214.5ms, then 268.6ms against the 200.0ms pin) while the test leg
     * stayed green. PCOV line instrumentation roughly doubles this
     * 262k-cell loop (cure-host best-of-3: hoisted 257-270ms under pcov
     * vs 72ms bare; re-inlined 460.7ms vs 109.4ms), and shared runners
     * add a further ~2x load swing. A single absolute ceiling is
     * host-parochial both ways: this lane's idle box measures the
     * REVERTED hoist at 109ms — under the era's 200ms pin. So the
     * absolute ceiling is now mode-aware (200.0ms unchanged when the run
     * collects no coverage; dated 400.0ms coverage ceiling, sitting
     * between the measured 270ms hoisted and 460ms re-inlined arms under
     * pcov), and the load-bearing discriminator is the unconditional
     * same-run ratio against the per-cell setCell control. Mutation
     * proven 2026-11-16: re-inlining the per-cell width measurement
     * reddens the bare leg (249.4ms > 200) and, with the absolute arm
     * temporarily relaxed clear, the ratio arm catches it on its own
     * (248.3/319.8 = 0.776 > 0.70 — load-invariant, matching the idle
     * 0.81 and pcov 0.76 signatures); under pcov the ceiling itself
     * reddens (477.7 > 400). The hoist passes both arms with margin
     * (0.51-0.59). On an idle host the reverted hoist measures 109ms —
     * under the absolute pin — so loosening the ratio toward 0.90 would
     * let it through: 0.70 sits on the measured signatures, not the
     * convenience of the current host.
     */
    public function testFillOf512SquareStaysUnderHoistedBudget(): void
    {
        $hoisted = PHP_FLOAT_MAX;
        $control = PHP_FLOAT_MAX;
        for ($i = 0; $i < 3; $i++) {
            $canvas = new Canvas(512, 512);
            $start = hrtime(true);
            $canvas->fill(0, 0, 511, 511, 'x');
            $hoisted = min($hoisted, (hrtime(true) - $start) / 1_000_000);

            // Control: exactly what the hoist replaced — the per-cell
            // width measurement paid once per setCell.
            $canvas = new Canvas(512, 512);
            $start = hrtime(true);
            for ($y = 0; $y <= 511; $y++) {
                for ($x = 0; $x <= 511; $x++) {
                    $canvas->setCell($x, $y, 'x');
                }
            }
            $control = min($control, (hrtime(true) - $start) / 1_000_000);
        }

        $ceiling = self::runCollectsCoverage() ? self::COVERAGE_CEILING_MS : self::LEGACY_CEILING_MS;
        self::assertLessThan($ceiling, $hoisted, "constant-fill must measure once per call; took {$hoisted}ms (ceiling {$ceiling}ms)");
        self::assertLessThan(self::MAX_HOIST_RATIO * $control, $hoisted, 'hoisted fill must stay under ' . self::MAX_HOIST_RATIO . ' of the per-cell setCell control; took ' . $hoisted . 'ms vs ' . $control . 'ms');
    }

    /**
     * Did the surrounding phpunit run request coverage collection? PCOV
     * 1.0.x exposes no status function, and argv is exactly what the CI
     * legs differ on: the test leg never passes a --coverage* flag, the
     * coverage legs always do.
     */
    private static function runCollectsCoverage(): bool
    {
        foreach ((array) ($_SERVER['argv'] ?? []) as $arg) {
            if (str_starts_with((string) $arg, '--coverage')) {
                return true;
            }
        }

        return false;
    }
}
