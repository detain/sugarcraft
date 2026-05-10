<?php

declare(strict_types=1);

namespace SugarCraft\Bounce\Easing;

/**
 * Standard easing functions for animation.
 *
 * Each case applies an easing curve to a normalized time t (0.0 to 1.0).
 *
 * @see https://easings.net/ for visual reference
 */
enum Easing
{
    case Linear;

    case QuadraticIn;
    case QuadraticOut;
    case QuadraticInOut;

    case CubicIn;
    case CubicOut;
    case CubicInOut;

    case ElasticIn;
    case ElasticOut;
    case ElasticInOut;

    case BounceIn;
    case BounceOut;
    case BounceInOut;

    case BackIn;
    case BackOut;
    case BackInOut;

    /**
     * Apply the easing function to a normalized time value.
     *
     * @param float $t Time value in range [0.0, 1.0]
     * @return float Eased value (typically also in [0.0, 1.0])
     */
    public function ease(float $t): float
    {
        $result = match ($this) {
            self::Linear => $t,

            self::QuadraticIn => $t * $t,
            self::QuadraticOut => $t * (2.0 - $t),
            self::QuadraticInOut => $t < 0.5
                ? 2.0 * $t * $t
                : -1.0 + (4.0 - 2.0 * $t) * $t,

            self::CubicIn => $t * $t * $t,
            self::CubicOut => 1.0 - pow(1.0 - $t, 3.0),
            self::CubicInOut => $t < 0.5
                ? 4.0 * $t * $t * $t
                : 1.0 - pow(-2.0 * $t + 2.0, 3.0) / 2.0,

            self::ElasticIn => sin($t * M_PI) * pow(2.0, 10.0 * ($t - 1.0)),
            self::ElasticOut => sin(13.0 * M_PI / 2.0 * $t) * pow(2.0, -10.0 * $t) + $t,
            self::ElasticInOut => $t < 0.5
                ? -(pow(2.0, 20.0 * $t - 10.0) * sin((20.0 * $t - 11.125) * M_PI * 2.5)) / 2.0
                : (pow(2.0, -20.0 * $t + 10.0) * sin((20.0 * $t - 11.125) * M_PI * 2.5)) / 2.0 + 1.0,

            self::BounceIn => 1.0 - $this->bounceOut(1.0 - $t),
            self::BounceOut => $this->bounceOut($t),
            self::BounceInOut => $t < 0.5
                ? (1.0 - $this->bounceOut(1.0 - 2.0 * $t)) / 2.0
                : (1.0 + $this->bounceOut(2.0 * $t - 1.0)) / 2.0,

            self::BackIn => pow($t, 3.0) - $t * sin($t * M_PI),
            self::BackOut => 1.0 - pow(1.0 - $t, 3.0) + (1.0 - $t) * sin((1.0 - $t) * M_PI),
            self::BackInOut => $t < 0.5
                ? (pow(2.0 * $t, 3.0) - (2.0 * $t) * sin((2.0 * $t) * M_PI)) / 2.0
                : (1.0 - pow(-2.0 * $t + 2.0, 3.0) + (1.0 - $t) * sin((-2.0 * $t + 2.0) * M_PI)) / 2.0 + 0.5,
        };

        // Clamp to [0, 1] to handle floating point precision issues
        return $result < 0.0 ? 0.0 : ($result > 1.0 ? 1.0 : $result);
    }

    /**
     * BounceOut helper used by BounceIn and BounceInOut.
     */
    private function bounceOut(float $t): float
    {
        if ($t < 1.0 / 2.75) {
            return 7.5625 * $t * $t;
        }
        if ($t < 2.0 / 2.75) {
            $t -= 1.5 / 2.75;
            return 7.5625 * $t * $t + 0.75;
        }
        if ($t < 2.5 / 2.75) {
            $t -= 2.25 / 2.75;
            return 7.5625 * $t * $t + 0.9375;
        }
        $t -= 2.625 / 2.75;
        return 7.5625 * $t * $t + 0.984375;
    }
}
