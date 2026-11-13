<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Easing;

/**
 * Interface for custom easing functions.
 *
 * Implement this interface to create custom animation curves that can be
 * used wherever an easing function is expected (e.g., animation libraries,
 * physics simulations, or UI transitions).
 *
 * PHP 8.1+ enums implementing this interface (such as {@see Easing}) are
 * already suitable for this role — Easing is a UNIT enum, and unit-ness is
 * what matters here: what makes a case an easing function is the
 * interface, not a backing value. The library's curve class
 * {@see CubicBezier} implements it through its solver-backed sampler
 * {@see CubicBezier::ease()}; an enum case and a same-named preset are
 * value families, not drop-in twins (analytic vs Bezier fit, and the enum
 * passes non-finite `t` through where CubicBezier refuses it).
 */
interface EasingFunction
{
    /**
     * Apply the easing function to a normalized time value.
     *
     * @param float $t Time value in range [0.0, 1.0]
     * @return float Eased value (may exceed [0, 1] for curves with intentional overshoot)
     */
    public function ease(float $t): float;
}
