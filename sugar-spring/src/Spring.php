<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

use SugarCraft\ColorProfile\Probe;

/**
 * Damped harmonic oscillator (Ryan Juckett's "Damped Springs"), with
 * under-/critically-/over-damped branches chosen by {@see $dampingRatio}.
 *
 * Construct once per (deltaTime, angularFrequency, dampingRatio) tuple — the
 * coefficients are precomputed. {@see update()} integrates one frame at the
 * deltaTime baked into the spring.
 */
final class Spring
{
    private const EPSILON = 1e-9;

    /** Threshold below which a spring is considered settled (resting at target). */
    public const SETTLING_THRESHOLD = 1e-4;

    private readonly float $posPosCoef;
    private readonly float $posVelCoef;
    private readonly float $velPosCoef;
    private readonly float $velVelCoef;

    private readonly ?bool $reducedMotionOverride;

    /**
     * @param float  $deltaTime           seconds elapsed per integration step
     * @param float  $angularFrequency    rad/sec (note: NOT Hz; multiply Hz by 2π)
     * @param float  $dampingRatio        <1 = oscillating, =1 = critical, >1 = over-damped
     * @param bool|null $reducedMotionOverride If provided, overrides Probe::reducedMotion() lookup
     */
    public function __construct(
        float $deltaTime,
        float $angularFrequency,
        float $dampingRatio,
        ?bool $reducedMotionOverride = null,
    ) {
        $this->reducedMotionOverride = $reducedMotionOverride;

        // Fail-fast at the boundary. Non-finite inputs poison the oscillator
        // (NAN folds silently through comparisons, an INF dampingRatio flows
        // into the over-damped branch where 0·INF produces NAN coefficients),
        // and a negative deltaTime integrates the oscillator backwards.
        // Beyond that, a spring that cannot converge is ILLEGAL, not slow:
        // ω below the numeric floor carries no restoring force (the former
        // identity transform froze position forever), ζ = 0 oscillates with
        // undecaying amplitude, and ζ < 0 diverges. All three make the
        // documented `while (!Spring::settled(...))` loop spin forever, so the
        // constructor refuses them here and every frame downstream is trusted.
        if (!is_finite($deltaTime) || !is_finite($angularFrequency) || !is_finite($dampingRatio)) {
            throw new \InvalidArgumentException(Lang::t('spring.inputs_finite', [
                'deltaTime' => $deltaTime,
                'angularFrequency' => $angularFrequency,
                'dampingRatio' => $dampingRatio,
            ]));
        }
        if ($deltaTime < 0.0) {
            throw new \InvalidArgumentException(Lang::t('spring.negative_dt', ['deltaTime' => $deltaTime]));
        }
        if ($angularFrequency < self::EPSILON) {
            throw new \InvalidArgumentException(Lang::t('spring.angular_too_small', ['angularFrequency' => $angularFrequency]));
        }
        if ($dampingRatio <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('spring.damping_positive', ['dampingRatio' => $dampingRatio]));
        }

        if ($dampingRatio > 1.0 + self::EPSILON) {
            $za = -$angularFrequency * $dampingRatio;
            $zb = $angularFrequency * sqrt($dampingRatio * $dampingRatio - 1.0);
            $z1 = $za - $zb;
            $z2 = $za + $zb;

            $e1 = exp($z1 * $deltaTime);
            $e2 = exp($z2 * $deltaTime);

            $invTwoZb       = 1.0 / (2.0 * $zb);
            $e1OverTwoZb    = $e1 * $invTwoZb;
            $e2OverTwoZb    = $e2 * $invTwoZb;
            $z1e1OverTwoZb  = $z1 * $e1OverTwoZb;
            $z2e2OverTwoZb  = $z2 * $e2OverTwoZb;

            $this->posPosCoef = $e1OverTwoZb * $z2 - $z2e2OverTwoZb + $e2;
            $this->posVelCoef = -$e1OverTwoZb + $e2OverTwoZb;
            $this->velPosCoef = ($z1e1OverTwoZb - $z2e2OverTwoZb + $e2) * $z2;
            $this->velVelCoef = -$z1e1OverTwoZb + $z2e2OverTwoZb;
        } elseif ($dampingRatio < 1.0 - self::EPSILON) {
            $omegaZeta = $angularFrequency * $dampingRatio;
            $alpha     = $angularFrequency * sqrt(1.0 - $dampingRatio * $dampingRatio);

            $expTerm = exp(-$omegaZeta * $deltaTime);
            $cosTerm = cos($alpha * $deltaTime);
            $sinTerm = sin($alpha * $deltaTime);

            $invAlpha = 1.0 / $alpha;
            $expSin   = $expTerm * $sinTerm;
            $expCos   = $expTerm * $cosTerm;
            $expOmegaZetaSinOverAlpha = $expTerm * $omegaZeta * $sinTerm * $invAlpha;

            $this->posPosCoef = $expCos + $expOmegaZetaSinOverAlpha;
            $this->posVelCoef = $expSin * $invAlpha;
            $this->velPosCoef = -$expSin * $alpha - $omegaZeta * $expOmegaZetaSinOverAlpha;
            $this->velVelCoef = $expCos - $expOmegaZetaSinOverAlpha;
        } else {
            // Critically damped (|ζ - 1| ≤ ε).
            $expTerm     = exp(-$angularFrequency * $deltaTime);
            $timeExp     = $deltaTime * $expTerm;
            $timeExpFreq = $timeExp * $angularFrequency;

            $this->posPosCoef = $timeExpFreq + $expTerm;
            $this->posVelCoef = $timeExp;
            $this->velPosCoef = -$angularFrequency * $timeExpFreq;
            $this->velVelCoef = -$timeExpFreq + $expTerm;
        }

        // Belt: finite inputs do not imply finite coefficients. ω on the order
        // of 1e155 overflows the intermediates (ω·ζ, exp(z·dt), the sqrt
        // argument under ζ²) into NAN, and a NAN coefficient corrupts every
        // later frame in silence. Refuse such a spring at construction.
        foreach ([$this->posPosCoef, $this->posVelCoef, $this->velPosCoef, $this->velVelCoef] as $coefficient) {
            if (!is_finite($coefficient)) {
                throw new \InvalidArgumentException(Lang::t('spring.coeffs_finite', [
                    'deltaTime' => $deltaTime,
                    'angularFrequency' => $angularFrequency,
                    'dampingRatio' => $dampingRatio,
                ]));
            }
        }
    }

    /**
     * Advance one step toward $target, returning the new position and velocity.
     *
     * If reduced motion is enabled via environment (REDUCE_MOTION or
     * PREFERS_REDUCED_MOTION) — or pinned per spring via the constructor's
     * $reducedMotionOverride — the spring snaps to its target instantly.
     * Anything composed of springs inherits that behaviour: {@see SpringChain}
     * advances one stage per tick, so under reduced motion its final positions
     * land after 2n−1 ticks and it completes on tick 2n (one snap tick plus
     * one hand-off tick per stage), while a {@see SpringCollection} ticks all
     * members at once and snaps them all in one tick flat. {@see Projectile} and the easing curves are NOT spring-based and
     * need application-level gating.
     *
     * @throws \InvalidArgumentException if any input is non-finite — a NAN
     *         target would otherwise ride the snap branch verbatim and pin
     *         settled() to false forever (a non-terminating animation loop).
     *
     * @return array{0:float,1:float}
     */
    public function update(float $pos, float $vel, float $target): array
    {
        // The data plane (pos/vel/target) is host state — accumulated
        // keyboard input, divided layout values — and arrives unvalidated
        // every frame. The parameter plane was guarded in the constructor;
        // this closes the value plane ahead of the snap branch.
        if (!is_finite($pos) || !is_finite($vel) || !is_finite($target)) {
            throw new \InvalidArgumentException(Lang::t('spring.update_finite', [
                'pos' => $pos,
                'vel' => $vel,
                'target' => $target,
            ]));
        }

        if (($this->reducedMotionOverride ?? Probe::reducedMotion()) === true) {
            return [$target, 0.0];
        }

        $oldPos = $pos - $target;
        $newPos = $oldPos * $this->posPosCoef + $vel * $this->posVelCoef + $target;
        $newVel = $oldPos * $this->velPosCoef + $vel * $this->velVelCoef;
        return [$newPos, $newVel];
    }

    /**
     * Whether a spring resting at ($pos, $vel) has settled at $target.
     *
     * Settled means both the displacement from target AND the velocity have
     * fallen below $threshold. The default is {@see SETTLING_THRESHOLD}; callers
     * that need a different band (e.g. {@see SpringChain}'s looser stage-handoff
     * threshold) pass their own.
     *
     * Non-finite inputs answer FALSE — the safe direction: a poisoned state is
     * never reported settled. {@see update()} refuses to produce one, so a
     * spring-driven loop that only feeds this predicate values it emitted
     * terminates by construction.
     */
    public static function settled(float $pos, float $vel, float $target, float $threshold = self::SETTLING_THRESHOLD): bool
    {
        return abs($pos - $target) < $threshold && abs($vel) < $threshold;
    }

    /** Frame-time (seconds) for a given frame rate. `Spring::fps(60)` → 1/60. */
    public static function fps(float $n): float
    {
        // is_finite() first: `INF <= 0.0` is false, so fps(INF) would
        // otherwise "succeed" at 0.0 — a frozen step time, the identity
        // spring's other door to never advancing.
        if (!is_finite($n) || $n <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('spring.fps_positive', ['fps' => $n]));
        }
        return 1.0 / $n;
    }

    /**
     * Construct a spring from a named preset at 60 fps.
     *
     * @param SpringPreset $preset  One of Gentle / Wobbly / Stiff / Slow / Molasses
     * @param float|null   $deltaTime Overrides the default 1/60s frame time
     * @param bool|null    $reducedMotionOverride Overrides the environment reduced-motion lookup
     */
    public static function fromPreset(SpringPreset $preset, ?float $deltaTime = null, ?bool $reducedMotionOverride = null): Spring
    {
        $config = $preset->resolve();
        return $config->spring($deltaTime ?? (1.0 / 60.0), $reducedMotionOverride);
    }
}
