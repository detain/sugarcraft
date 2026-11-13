<?php

declare(strict_types=1);

namespace SugarCraft\AnsiInspect\Tests;

use PHPUnit\Framework\TestCase;
use SugarCraft\AnsiInspect\Inspector;

/**
 * Bounded performance smoke for the byte-fidelity spine (queue row AIP-39).
 *
 * The finding this guards is the quadratic family: any change that makes the
 * report cost grow FASTER than the input (e.g. re-scanning the whole window
 * per byte, or re-rendering labels per segment) must go red here rather than
 * silently turning a 3 MB capture into a stall. Ceilings are deliberately
 * generous (multi-fold headroom over the measured host figures) so the guard
 * catches complexity regressions, not CI jitter.
 *
 * Measured on the memo + streaming baseline (PHP 8.3, host): 1 MB of dense
 * SGR runs ~2.6 s for one Inspector::report(); the same corpus doubled costs
 * ~2.0x, never 4x. The annotation's <1 s per 3 MB-dense target is bound by
 * the sugar-ansi-parser per-byte state machine (owned elsewhere); the rider
 * to make it sub-second is a parser-side run fast path, not a change here.
 */
final class PerfGuardTest extends TestCase
{
    private const DENSE_UNIT = "\x1b[1m"; // 4 bytes, one dispatch each

    public function testDenseEscapeStreamCompletesWithinCiCeiling(): void
    {
        $bytes = str_repeat(self::DENSE_UNIT, 262144); // 1 MB
        $start = microtime(true);
        $report = Inspector::report($bytes);
        $elapsed = microtime(true) - $start;

        $this->assertStringContainsString('SGR bold', $report);
        $this->assertLessThan(
            25.0,
            $elapsed,
            sprintf('1 MB dense took %.1fs — the spine is supposed to be linear (~2.6s measured).', $elapsed)
        );
    }

    public function testCostScalesLinearlyWithDenseInput(): void
    {
        $small = str_repeat(self::DENSE_UNIT, 65536);  // 256 KB
        $large = str_repeat(self::DENSE_UNIT, 131072); // 512 KB — one doubling

        $tSmall = self::timed($small);
        $tLarge = self::timed($large);

        // Quadratic would need >= ~4x; linear measures ~2x. 3.2x + a fixed
        // second grants headroom for shared CI noise while still failing a
        // re-introduced per-byte whole-window scan at 512 KB scale.
        $this->assertLessThanOrEqual(
            $tSmall * 3.2 + 1.0,
            $tLarge,
            sprintf('doubling input cost %.1fx — super-linear growth detected (%.3fs -> %.3fs).', $tLarge / max($tSmall, 1e-9), $tSmall, $tLarge)
        );
    }

    private static function timed(string $bytes): float
    {
        $start = microtime(true);
        Inspector::report($bytes);

        return microtime(true) - $start;
    }
}
