<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Easing;

/**
 * Standard easing functions for animation.
 *
 * Each case applies an easing curve to a normalized time t (0.0 to 1.0).
 *
 * **Reduced motion.** Deliberately ignores REDUCE_MOTION: NOT spring-based,
 * pure functions of time with no target to snap *to* — the gate belongs in
 * the application (mirror of the note on {@see \SugarCraft\Spring\Spring}).
 *
 * @see https://easings.net/ for visual reference
 */
enum Easing implements EasingFunction
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
     * Designed for $t ∈ [0, 1]; an out-of-range $t is NOT rejected and NOT
     * clamped — the curves EXTRAPOLATE, so ease(2.0) answers whatever the
     * formula gives. Callers feeding a progress ratio from host state should
     * clamp upstream. The RETURN value is likewise unclamped by design:
     * Elastic/Back curves overshoot past 1.0 (and below 0.0 on their
     * anticipation legs) — that swing IS the effect. Mapping an eased value
     * onto discrete cells (indices, widths) requires your own clamp or
     * rounding gate; `round(ease($t) * 40)` can be a negative cell.
     * Non-finite $t flows through unguarded here: NAN answers NAN from every
     * case, ±INF survives the polynomial curves and flips to NAN inside the
     * Elastic/Back trig — clamp upstream. Contrast CubicBezier::evaluate(),
     * which refuses a non-finite t outright.
     *
     * @param float $t Normalized time, designed for [0.0, 1.0]
     * @return float Eased value (may exceed [0,1] for Elastic/Back curves due to intentional overshoot)
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

            self::ElasticIn => $this->elasticIn($t),
            self::ElasticOut => $this->elasticOut($t),
            self::ElasticInOut => $this->elasticInOut($t),

            self::BounceIn => 1.0 - $this->bounceOut(1.0 - $t),
            self::BounceOut => $this->bounceOut($t),
            self::BounceInOut => $t < 0.5
                ? (1.0 - $this->bounceOut(1.0 - 2.0 * $t)) / 2.0
                : (1.0 + $this->bounceOut(2.0 * $t - 1.0)) / 2.0,

            // Back* here follow the sin-modulated family (t³ ∓ sin shaping),
            // NOT Penner's c1 = 1.70158 polynomial. Same overshoot idea,
            // different amplitude curve — documented variance, not a bug.
            // BackOut carries an exact-endpoint guard because sin(π) is
            // 1.2246e-16, not 0, in binary floating point.
            self::BackIn => pow($t, 3.0) - $t * sin($t * M_PI),
            self::BackOut => $t === 0.0 ? 0.0 : 1.0 - pow(1.0 - $t, 3.0) + (1.0 - $t) * sin((1.0 - $t) * M_PI),
            self::BackInOut => $t < 0.5
                ? (pow(2.0 * $t, 3.0) - (2.0 * $t) * sin((2.0 * $t) * M_PI)) / 2.0
                : (1.0 - pow(-2.0 * $t + 2.0, 3.0) + (1.0 - $t) * sin((-2.0 * $t + 2.0) * M_PI)) / 2.0 + 0.5,
        };

        return $result;
    }

    /**
     * ElasticIn — canonical Penner/easings.net form `-(2^(10t-10)) ·
     * sin((10t - 10.75) · (2π/3))`. The raw formula answers -4.88e-4 at
     * t = 0 (sin of a huge negative phase, not zero), so the exact endpoints
     * are guarded.
     */
    private function elasticIn(float $t): float
    {
        if ($t === 0.0) {
            return 0.0;
        }
        if ($t === 1.0) {
            return 1.0;
        }
        return -pow(2.0, 10.0 * $t - 10.0) * sin((10.0 * $t - 10.75) * ((2.0 * M_PI) / 3.0));
    }

    /**
     * ElasticOut — canonical form `2^(-10t) · sin((10t - 0.75) · (2π/3)) + 1`.
     *
     * The previous spelling `sin(13πt/2) · 2^(-10t) + t` dropped the phase
     * shift and added t instead of 1: it collapsed the rubber-band overshoot
     * to a near-monotonic ramp that DIPPED NEGATIVE at t = 0.2 (-0.0023) —
     * an "ease-out" with an anticipation dip, contradicting the curve's own
     * overshoot contract. Endpoints agreed, which is why endpoint-only pins
     * stayed green over the wrong shape.
     */
    private function elasticOut(float $t): float
    {
        if ($t === 0.0) {
            return 0.0;
        }
        if ($t === 1.0) {
            return 1.0;
        }
        return pow(2.0, -10.0 * $t) * sin((10.0 * $t - 0.75) * ((2.0 * M_PI) / 3.0)) + 1.0;
    }

    /**
     * ElasticInOut — canonical two-half form with the (2π)/4.5 angular
     * constant continuity REQUIRES: at t = 0.5 both halves answer exactly
     * 0.5. The previous M_PI * 2.5 constant left the two halves disagreeing
     * by 0.444 across the half-way frame — a renderer crossing that frame
     * visibly teleported the object.
     */
    private function elasticInOut(float $t): float
    {
        if ($t === 0.0) {
            return 0.0;
        }
        if ($t === 1.0) {
            return 1.0;
        }
        $omega = (2.0 * M_PI) / 4.5;
        return $t < 0.5
            ? -(pow(2.0, 20.0 * $t - 10.0) * sin((20.0 * $t - 11.125) * $omega)) / 2.0
            : (pow(2.0, -20.0 * $t + 10.0) * sin((20.0 * $t - 11.125) * $omega)) / 2.0 + 1.0;
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
