<?php

declare(strict_types=1);

namespace SugarCraft\Top\Theme;

use SugarCraft\Core\Util\Color;

/**
 * A box outline's colour flow: `<box>_box` → optional `<box>_box_mid` →
 * `<box>_box_end`, sampled at a position 0..1 along the box's diagonal.
 *
 * candy-top extension — btop has only the flat `<box>_box` colour, and its
 * theme loader ignores these extra keys, so a theme using them still loads
 * (flat) in btop.
 */
final class BoxFlow
{
    private function __construct(
        public readonly Color $start,
        public readonly ?Color $mid,
        public readonly Color $end,
    ) {
    }

    public static function new(Color $start, ?Color $mid, Color $end): self
    {
        return new self($start, $mid, $end);
    }

    /** Colour at `$t` (clamped 0..1): two legs through mid when set, else one. */
    public function at(float $t): Color
    {
        $t = max(0.0, min(1.0, $t));
        if ($this->mid === null) {
            return self::mix($this->start, $this->end, $t);
        }

        return $t <= 0.5
            ? self::mix($this->start, $this->mid, $t * 2)
            : self::mix($this->mid, $this->end, $t * 2 - 1);
    }

    /** Linear blend `$a` → `$b` at `$t` (0 = a, 1 = b), rounded per channel. */
    public static function mix(Color $a, Color $b, float $t): Color
    {
        return Color::rgb(
            (int) round($a->r + ($b->r - $a->r) * $t),
            (int) round($a->g + ($b->g - $a->g) * $t),
            (int) round($a->b + ($b->b - $a->b) * $t),
        );
    }
}
