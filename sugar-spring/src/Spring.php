<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

use SugarCraft\ColorProfile\Probe;

/**
 * Damped harmonic oscillator (Ryan Juckett's "Damped Springs"), with
 * under-/critically-/over-damped branches chosen by the damping ratio —
 * readable back off any live spring via {@see self::dampingRatio()}.
 *
 * Mirrors charmbracelet/harmonica.Spring (constructed via
 * charmbracelet/harmonica.NewSpring) — the Juckett attribution above is not
 * decoration: harmonica's implementation itself consumes Juckett's "Damped
 * Springs" closed form, so the port names the Go type and the math's author
 * both, in one breath (upstream-of-record spot-check against
 * docs/research/libraries/sugar-spring-research.md).
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
     * The tuning triple, remembered verbatim for the bare accessors below —
     * a live Spring was otherwise write-only (finding 017): persist/restore
     * and debug had to keep their own copy of what the constructor was fed.
     */
    private readonly float $deltaTime;
    private readonly float $angularFrequency;
    private readonly float $dampingRatio;

    /**
     * Process-wide memo of the host reduced-motion signal. Probe::reducedMotion()
     * rebuilt two full env snapshots (`array_merge($_ENV, getenv())`) on EVERY
     * call, so a frame loop of live springs paid an env scan per spring per
     * frame — a CLI/TUI process does not grow new a11y settings mid-run, so
     * the verdict is read once and remembered. {@see resetReducedMotionProbe()}
     * is the documented seam for the one legitimate flipper: tests.
     */
    private static ?bool $reducedMotionProbe = null;

    /**
     * @param float      $deltaTime              seconds elapsed per integration step; must be > 0
     * @param float      $angularFrequency       rad/sec (note: NOT Hz; multiply Hz by 2π)
     * @param float      $dampingRatio           <1 = oscillating, =1 = critical, >1 = over-damped
     * @param bool|null  $reducedMotionOverride  if provided, wins over the (memoized)
     *                                           environment lookup, evaluated per call
     */
    public function __construct(
        float $deltaTime,
        float $angularFrequency,
        float $dampingRatio,
        ?bool $reducedMotionOverride = null,
    ) {
        $this->reducedMotionOverride = $reducedMotionOverride;

        // Fail-fast at the boundary.
        //
        // HOW non-finite inputs poison the oscillator: NAN folds silently
        // through comparisons, and an INF dampingRatio flows into the
        // over-damped branch where 0·INF produces NAN coefficients. A
        // negative deltaTime integrates the oscillator backwards.
        //
        // A spring that cannot converge is ILLEGAL, not slow: ω below the
        // numeric floor carries no restoring force (the former identity
        // transform froze position forever), ζ = 0 oscillates with
        // undecaying amplitude, and ζ < 0 diverges. All three make the
        // documented `while (!Spring::settled(...))` loop spin forever.
        //
        // CONSEQUENCE: the constructor refuses every one of them here, and
        // every frame downstream is trusted.
        if (!is_finite($deltaTime) || !is_finite($angularFrequency) || !is_finite($dampingRatio)) {
            throw new \InvalidArgumentException(Lang::t('spring.inputs_finite', [
                'deltaTime' => $deltaTime,
                'angularFrequency' => $angularFrequency,
                'dampingRatio' => $dampingRatio,
            ]));
        }
        if ($deltaTime <= 0.0) {
            // dt = 0 here would freeze the state in place (identity
            // coefficients) — meaningless for an oscillator whose whole
            // contract is one real step per update(). NOTE the deliberate
            // asymmetry (finding 049): Projectile ACCEPTS dt = 0, where the
            // freeze is a useful pause-the-motion idiom; the two classes
            // rule differently ON PURPOSE, not by drift.
            throw new \InvalidArgumentException(Lang::t('spring.dt_positive', ['deltaTime' => $deltaTime]));
        }
        if ($angularFrequency < self::EPSILON) {
            throw new \InvalidArgumentException(Lang::t('spring.angular_too_small', ['angularFrequency' => $angularFrequency]));
        }
        if ($dampingRatio <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('spring.damping_positive', ['dampingRatio' => $dampingRatio]));
        }

        // Inputs are parsed-and-trusted from here down; remember them raw.
        $this->deltaTime = $deltaTime;
        $this->angularFrequency = $angularFrequency;
        $this->dampingRatio = $dampingRatio;

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
     * Factory alias of the constructor — the house `::new()` entry shape,
     * one vocabulary across the library (finding 052). `Spring::new(...)`
     * and `new Spring(...)` are the same door, not two behaviours.
     */
    public static function new(
        float $deltaTime,
        float $angularFrequency,
        float $dampingRatio,
        ?bool $reducedMotionOverride = null,
    ): self {
        return new self($deltaTime, $angularFrequency, $dampingRatio, $reducedMotionOverride);
    }

    /**
     * Advance one step toward $target, returning the new position and velocity.
     *
     * If reduced motion is enabled via environment (REDUCE_MOTION or
     * PREFERS_REDUCED_MOTION) — or pinned per spring via the constructor's
     * $reducedMotionOverride — the spring snaps to its target instantly. The
     * environment signal is probed once per process and remembered (see
     * {@see resetReducedMotionProbe()} for the test/hot-config seam); the
     * override is evaluated per call and always wins.
     * Anything composed of springs inherits that behaviour. {@see SpringChain}
     * advances one stage per tick: under reduced motion its final positions
     * land after 2n−1 ticks, and it completes on tick 2n — one snap tick plus
     * one hand-off tick per stage. A {@see SpringCollection} instead ticks
     * all members at once and snaps them all in one tick flat.
     * {@see Projectile} and the easing curves are NOT spring-based and need
     * application-level gating.
     *
     * Every call returns a fresh pair array (finding 079: one 4-entry array
     * per spring per frame). At 60 fps a handful of springs this is noise
     * beside the solver work it carries; a large-N swarm should pool or
     * reuse the arrays caller-side rather than ask this method to mutate.
     *
     * @throws \InvalidArgumentException if any input is non-finite — a NAN
     *         target would otherwise ride the snap branch verbatim and pin
     *         settled() to false forever (a non-terminating animation loop).
     *
     * @return array{0: float, 1: float, position: float, velocity: float}
     *         Positional 0/1 stays the destructuring contract (existing
     *         `[$pos, $vel] =` callers and list() keep working verbatim);
     *         the named keys exist so a transposed read is a documented
     *         static-analysis hit instead of a silent swap.
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

        if (($this->reducedMotionOverride ?? self::probeReducedMotion()) === true) {
            return self::statePair($target, 0.0);
        }

        $oldPos = $pos - $target;
        $newPos = $oldPos * $this->posPosCoef + $vel * $this->posVelCoef + $target;
        $newVel = $oldPos * $this->velPosCoef + $vel * $this->velVelCoef;
        return self::statePair($newPos, $newVel);
    }

    /**
     * The one construction site of update()'s return shape (finding 018):
     * position first under BOTH views, velocity second — so the list and
     * keyed readings can never disagree.
     *
     * @return array{0: float, 1: float, position: float, velocity: float}
     */
    private static function statePair(float $position, float $velocity): array
    {
        return [
            'position' => $position,
            'velocity' => $velocity,
            0 => $position,
            1 => $velocity,
        ];
    }

    /** Tuning triple: seconds elapsed per integration step. */
    public function deltaTime(): float
    {
        return $this->deltaTime;
    }

    /** Tuning triple: rad/sec (NOT Hz) — see the constructor note. */
    public function angularFrequency(): float
    {
        return $this->angularFrequency;
    }

    /** Tuning triple: <1 oscillating, =1 critical, >1 over-damped. */
    public function dampingRatio(): float
    {
        return $this->dampingRatio;
    }

    /** The pinned reduced-motion override, or null when it defers to the environment. */
    public function reducedMotionOverride(): ?bool
    {
        return $this->reducedMotionOverride;
    }

    /**
     * The memoized environment leg of the reduced-motion decision (the
     * per-spring $reducedMotionOverride still wins outright — it is consulted
     * with ?? before this runs, so a pinned spring never touches the memo).
     */
    private static function probeReducedMotion(): bool
    {
        return self::$reducedMotionProbe ??= Probe::reducedMotion();
    }

    /**
     * Test seam: forget the memoized reduced-motion verdict so the next probe
     * re-reads the environment. Production callers should never need this —
     * the point of the memo is that a process's a11y setting is read once.
     * Environments that DO hot-swap REDUCE_MOTION mid-run (long-lived daemons
     * re-reading config) are the documented reason this seam is public.
     */
    public static function resetReducedMotionProbe(): void
    {
        self::$reducedMotionProbe = null;
    }

    /**
     * Whether a spring resting at ($pos, $vel) has settled at $target.
     *
     * Settled means both the displacement from target AND the velocity have
     * fallen below $threshold. The default is {@see SETTLING_THRESHOLD}; callers
     * that need a different band (e.g. {@see SpringChain}'s looser stage-handoff
     * threshold) pass their own.
     *
     * Non-finite inputs return FALSE — the safe direction: a poisoned state is
     * never reported settled. {@see update()} refuses to produce one, so a
     * spring-driven loop that only feeds this predicate values it emitted
     * terminates by construction.
     */
    public static function settled(float $pos, float $vel, float $target, float $threshold = self::SETTLING_THRESHOLD): bool
    {
        return abs($pos - $target) < $threshold && abs($vel) < $threshold;
    }

    /**
     * Frame-time (seconds) for a given frame rate. `Spring::fps(60)` → 1/60.
     *
     * Refuses non-finite and non-positive rates AND rates so small the
     * reciprocal itself overflows (1e-310 → INF): whatever this returns is
     * always a usable, finite, positive frame time.
     */
    public static function fps(float $n): float
    {
        // is_finite() first: `INF <= 0.0` is false, so fps(INF) would
        // otherwise "succeed" at 0.0 — a frozen step time, the very value the
        // Spring constructor now refuses outright (dt <= 0); catching it at
        // this door keeps callers from ever holding the poison.
        if (!is_finite($n) || $n <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('spring.fps_positive', ['fps' => $n]));
        }

        // The input guard alone was not enough to keep the promise: a finite,
        // positive 1e-310 passes it, yet 1/1e-310 overflows to INF — the very
        // frozen step this door exists to keep out. Guard the OUTPUT so the
        // docblock claim ("callers never hold the poison") is literally true.
        $frameTime = 1.0 / $n;
        if (!is_finite($frameTime)) {
            throw new \InvalidArgumentException(Lang::t('spring.fps_rate_too_small', ['fps' => $n]));
        }

        return $frameTime;
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
