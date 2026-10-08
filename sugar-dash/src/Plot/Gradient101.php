<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot;

use SugarCraft\Core\Util\Color;

/**
 * btop's 101-entry gradient expansion (index = percent), shared by every
 * Plot widget that colors by percent so the integer law lives in one place.
 *
 * Mirrors aristocratos/btop Theme::generateGradients.
 */
final class Gradient101
{
    private function __construct()
    {
    }

    /**
     * Expand ordered low→high stops into a 101-entry lookup.
     *
     * Each channel uses btop's integer law,
     * `start + (i - offset) * (end - start) / range` with C++ truncating
     * division, so every entry is byte-identical to btop's (a rounding
     * blend drifts by one on odd deltas, e.g. 127 vs 128 at the midpoint).
     * With more than two stops the 0..100 range is split into equal
     * segments; for three stops that is btop's start/mid/end split at 50.
     *
     * @param list<Color> $stops ordered low→high, at least 2
     * @return list<Color> exactly 101 entries
     * @throws \InvalidArgumentException on fewer than 2 stops or a non-Color stop
     */
    public static function expand(array $stops): array
    {
        $stops = array_values($stops);
        if (count($stops) < 2) {
            throw new \InvalidArgumentException(sprintf(
                'Gradient needs at least 2 color stops, got %d',
                count($stops),
            ));
        }
        foreach ($stops as $i => $stop) {
            if (!$stop instanceof Color) {
                throw new \InvalidArgumentException(sprintf(
                    'Gradient stop %d must be a %s, got %s',
                    $i,
                    Color::class,
                    get_debug_type($stop),
                ));
            }
        }

        $segments = count($stops) - 1;
        $memo = [$stops[0]];
        $from = 0;
        for ($k = 0; $k < $segments; $k++) {
            $to = (int) round(($k + 1) * 100 / $segments);
            $range = $to - $from;
            $a = $stops[$k];
            $b = $stops[$k + 1];
            // Index $from already holds this segment's start (the previous
            // segment's end), so adjacent segments share their boundary.
            for ($i = $from + 1; $i <= $to; $i++) {
                $step = $i - $from;
                // intdiv truncates toward zero, matching C++ int division
                // on the negative deltas of a descending channel.
                $memo[] = Color::rgb(
                    $a->r + intdiv($step * ($b->r - $a->r), $range),
                    $a->g + intdiv($step * ($b->g - $a->g), $range),
                    $a->b + intdiv($step * ($b->b - $a->b), $range),
                );
            }
            $from = $to;
        }

        return $memo;
    }
}
