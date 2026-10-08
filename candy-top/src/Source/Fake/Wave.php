<?php

declare(strict_types=1);

namespace SugarCraft\Top\Source\Fake;

/**
 * The deterministic signal every fake source draws from: a sum of two
 * sines, so consecutive samples move like real load (smooth, never
 * periodic over a short demo) yet a given (step, phase) is always the
 * same number — snapshot tests and VHS tapes stay byte-stable.
 */
final class Wave
{
    private function __construct()
    {
    }

    /** A value in [0, 100] for `$step` on channel `$phase`. */
    public static function percent(int $step, float $phase = 0.0): float
    {
        $v = 50.0 + 30.0 * sin($step * 0.45 + $phase) + 15.0 * sin($step * 0.13 + $phase * 2.1);

        return round(max(0.0, min(100.0, $v)), 1);
    }
}
