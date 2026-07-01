<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Easing;

/**
 * Interface for custom easing functions.
 *
 * Implement this interface to create custom animation curves that can be
 * used wherever an easing function is expected (e.g., animation libraries,
 * physics simulations, or UI transitions).
 *
 * PHP 8.1+ backed enums that implement this interface (such as {@see Easing})
 * are already suitable for this role.
 */
interface EasingFunction
{
    /**
     * Apply the easing function to a normalized time value.
     *
     * @param float $t Time value in range [0.0, 1.0]
     * @return float Eased value (may exceed [0,1] for curves with intentional overshoot)
     */
    public function ease(float $t): float;
}
