<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Easing;

use SugarCraft\Spring\Lang;

/**
 * Cubic bezier easing functions — matches the CSS `cubic-bezier()` spec.
 *
 * Use the static factory methods to construct named presets. The algorithm
 * follows the canonical CSS spec: find t such that x(t) = input, then return
 * y(t). The solver only INVERTS x(t) — it does not make anything monotonic.
 * The monotonicity the inversion relies on comes from the constructor's
 * x1/x2 ∈ [0, 1] range check, which is exactly why the CSS spec imposes that
 * same condition; the y output may overshoot [0, 1] when the y control
 * points do, which is the whole point of back/elastic-style curves.
 *
 * The named family beyond the four CSS keywords ships the ORIGINAL (pre-2022)
 * easings.net cubic-bezier approximation table, rendered in this file's 2-dp
 * control-point convention (0.445 → 0.45, 0.685 → 0.69); the site's 2022
 * redesign revised several rows. Do not mix generations — the easeInOutCirc
 * repair restored the original-table Circ value precisely so the family stays
 * internally consistent with its Quint/Expo neighbours.
 *
 * Implements the {@see EasingFunction} seam through its sampler
 * {@see ease()}. A bezier curve and the same-named {@see Easing} enum case
 * are different value families, not drop-in twins: the enum is analytic
 * (CubicIn is literally t³), this curve is a table fit, and their non-finite
 * policies diverge by design — {@see Easing} passes NAN/INF through, this
 * sampler refuses them (a Newton iteration has nothing honest to answer
 * with).
 *
 * **Reduced motion.** Deliberately ignores REDUCE_MOTION: NOT spring-based,
 * a pure sampler over a fixed curve with no target to snap *to* — the gate
 * belongs in the application (mirror of the note on
 * {@see \SugarCraft\Spring\Spring}).
 *
 * @see https://www.w3.org/TR/css-easing-3/#cubic-bezier-algo
 * @see https://github.com/ai/easings.net (2022 redesign table)
 * @see https://30secondsofcode.org/css/s/easing-variables/ (pre-2022 table
 *      mirror — the generation this family ships; this docblock once cited
 *      a src/easings.yml that exists in no relevant repo)
 */
// Per-property readonly rather than a `readonly class`: the named-preset
// memo below is a private *static* array, and PHP marks every property of a
// readonly class — statics included — immutable, which forbids it a default.
// The ten instance fields are each individually readonly; the curve's
// immutability (what makes sharing a preset safe) is unchanged.
final class CubicBezier implements EasingFunction
{
    private const NEWTON_ITERATIONS    = 8;
    private const NEWTON_MIN_STEP      = 0.000_001;
    private const SUBDIVISION_PRECISION = 0.000_000_1;
    /**
     * The two solvers aim at different tolerances BY DESIGN: the Newton pass
     * stops at NEWTON_MIN_STEP (1e-6 — plenty for a 60 fps frame), and only
     * its derivative-stall fallback walks the subdivision down to
     * SUBDIVISION_PRECISION (1e-7). Answers from the two paths may therefore
     * disagree in the sixth decimal; both are inside a frame's worth of
     * visible motion.
     *
     * MAX_SUBDIVISION_ITERATIONS is not a tripwire: float bisection
     * collapses its own bracket in under 53 passes, and the loop bound is a
     * silent termination guarantee — were it ever reached, the fallback
     * returns the best bracket so far without complaint (honest wording,
     * 040: the old "loud safety net" claim contradicted the silent cap-exit
     * the solver comments describe).
     */
    private const MAX_SUBDIVISION_ITERATIONS = 64; // silent termination bound, never a hang

    /** Control point 1 x */
    private readonly float $x1;
    /** Control point 1 y */
    private readonly float $y1;
    /** Control point 2 x */
    private readonly float $x2;
    /** Control point 2 y */
    private readonly float $y2;

    /**
     * Bézier polynomial coefficients (endpoints P0=0, P3=1 baked in), one
     * constructor-hoisted set per axis: x(t) = ax·t³ + bx·t² + cx·t and the
     * y mirror. The sampler AND the Newton derivative consume the SAME six
     * floats — before the hoist each method recomputed them inline per call,
     * the derivative from a differently-associated spelling that differs from
     * the polynomial's by one ulp on most non-dyadic control points (the
     * very value/slope drift the old comment claimed was impossible).
     */
    private readonly float $cx;
    private readonly float $bx;
    private readonly float $ax;
    private readonly float $cy;
    private readonly float $by;
    private readonly float $ay;

    private readonly float $epsilon;

    /**
     * The 26 named presets, one shared immutable instance per factory name.
     * Every instance field is readonly and all derived values are computed
     * in the constructor, so handing the same object to every caller is
     * safe; the per-frame consumer path (sugar-overlay's
     * `$this->easing ?? CubicBezier::easeX()`) stops allocating a curve each
     * frame. Ad-hoc `new CubicBezier(...)` curves bypass this memo — it holds
     * at most the 26 named entries, keyed by final factory name.
     */
    private static array $presets = [];

    /**
     * @param float $x1 Control point 1 x (CSS requires 0 ≤ x1 ≤ 1)
     * @param float $y1 Control point 1 y
     * @param float $x2 Control point 2 x (CSS requires 0 ≤ x2 ≤ 1)
     * @param float $y2 Control point 2 y
     */
    public function __construct(float $x1, float $y1, float $x2, float $y2)
    {
        // Fail fast on non-finite control points. The range check below uses
        // bare < / > comparisons, which are ALWAYS false for NAN — without
        // this guard a NaN point sails through, every solve degenerates to a
        // NaN progress value, and the corruption surfaces frames later far
        // from its cause.
        if (!is_finite($x1) || !is_finite($y1) || !is_finite($x2) || !is_finite($y2)) {
            throw new \InvalidArgumentException(Lang::t('bezier.points_finite', [
                'x1' => $x1,
                'y1' => $y1,
                'x2' => $x2,
                'y2' => $y2,
            ]));
        }

        if ($x1 < 0.0 || $x1 > 1.0 || $x2 < 0.0 || $x2 > 1.0) {
            throw new \InvalidArgumentException(Lang::t('bezier.x_range', [
                'x1' => $x1,
                'x2' => $x2,
            ]));
        }
        $this->x1 = $x1;
        $this->y1 = $y1;
        $this->x2 = $x2;
        $this->y2 = $y2;
        // WebKit UnitBezier coefficient form — hoisted once per instance so
        // every sample is pure evaluation, never re-derivation. The local
        // spelling below is load-bearing for byte-fidelity: 1-cx-bx and the
        // old derivative spelling 1-3x2+3x1 are algebraically equal but
        // differ by one ulp in float, and existing sample pins are asserted
        // on this form (pinned in CubicBezierTest).
        $this->cx = $cx = 3.0 * $x1;
        $this->bx = $bx = 3.0 * ($x2 - $x1) - $cx;
        $this->ax = 1.0 - $cx - $bx;
        $this->cy = $cy = 3.0 * $y1;
        $this->by = $by = 3.0 * ($y2 - $y1) - $cy;
        $this->ay = 1.0 - $cy - $by;
        $this->epsilon = self::SUBDIVISION_PRECISION;
    }

    // ─── CSS named easings ────────────────────────────────────────────────

    /**
     * Memo door for the 26 named presets: the key is the factory name and
     * the value is a shared immutable instance (see {@see self::$presets}
     * for the consumer payoff). Private and reached only from the named
     * factories below — never from the constructor, so custom curves stay
     * unshared.
     */
    private static function memo(string $name, float $x1, float $y1, float $x2, float $y2): self
    {
        return self::$presets[$name] ??= new self($x1, $y1, $x2, $y2);
    }

    /**
     * Factory alias of the constructor — the house `::new()` entry shape
     * (finding 052), SHIPPED rather than exempted on purpose: this class
     * already answers static calls with named presets, and the uniform door
     * costs nothing next to teaching five classes one vocabulary. Custom
     * (off-table) curves take this door; the 26 named ones take their
     * presets, which stay the memoized shared instances.
     */
    public static function new(float $x1, float $y1, float $x2, float $y2): self
    {
        return new self($x1, $y1, $x2, $y2);
    }

    /**
     * The CSS `ease` transition-timing-function keyword. Named `cssEase()`
     * because the seam sampler {@see ease()} owns the bare spelling — PHP
     * forbids one name as both static preset and instance sampler. The
     * legacy static `CubicBezier::ease()` cannot forward through
     * `__callStatic()` (the engine resolves it to the instance method and
     * raises an Error) and fails loudly instead; migrate to `cssEase()`.
     */
    public static function cssEase(): self       { return self::memo('cssEase', 0.25, 0.10, 0.25, 1.00); }
    public static function easeIn(): self         { return self::memo('easeIn', 0.42, 0.00, 1.00, 1.00); }
    public static function easeOut(): self        { return self::memo('easeOut', 0.00, 0.00, 0.58, 1.00); }
    public static function easeInOut(): self      { return self::memo('easeInOut', 0.42, 0.00, 0.58, 1.00); }
    public static function linear(): self         { return self::memo('linear', 0.00, 0.00, 1.00, 1.00); }

    /**
     * BC door for the CSS-keyword preset, honestly stated: PHP resolves a
     * static call to the instance sampler `ease()` and raises an Error
     * WITHOUT consulting `__callStatic`, so no deprecation forwarder can
     * exist for `CubicBezier::ease()`. The legacy spelling fails LOUDLY
     * until 2.0.0; `cssEase()` is the preset. This hook guards the other
     * static misses only: a typo must fail with a named refusal here, not
     * quietly become some preset.
     */
    public static function __callStatic(string $name, array $arguments): self
    {
        throw new \BadMethodCallException(Lang::t('bezier.unknown_static', ['name' => $name]));
    }

    public static function easeInSine(): self     { return self::memo('easeInSine', 0.47, 0.00, 0.75, 0.72); }
    public static function easeOutSine(): self    { return self::memo('easeOutSine', 0.39, 0.58, 0.57, 1.00); }
    public static function easeInOutSine(): self  { return self::memo('easeInOutSine', 0.45, 0.05, 0.55, 0.95); }

    public static function easeInQuad(): self     { return self::memo('easeInQuad', 0.55, 0.09, 0.68, 0.53); }
    public static function easeOutQuad(): self    { return self::memo('easeOutQuad', 0.25, 0.46, 0.45, 0.94); }
    public static function easeInOutQuad(): self  { return self::memo('easeInOutQuad', 0.46, 0.03, 0.52, 0.96); }

    public static function easeInCubic(): self    { return self::memo('easeInCubic', 0.55, 0.06, 0.68, 0.19); }
    public static function easeOutCubic(): self   { return self::memo('easeOutCubic', 0.22, 0.61, 0.36, 1.00); }
    public static function easeInOutCubic(): self { return self::memo('easeInOutCubic', 0.65, 0.05, 0.35, 1.00); }

    public static function easeInQuart(): self    { return self::memo('easeInQuart', 0.90, 0.03, 0.69, 0.22); }
    public static function easeOutQuart(): self   { return self::memo('easeOutQuart', 0.17, 0.84, 0.44, 1.00); }
    public static function easeInOutQuart(): self { return self::memo('easeInOutQuart', 0.77, 0.00, 0.18, 1.00); }

    public static function easeInQuint(): self    { return self::memo('easeInQuint', 0.76, 0.05, 0.86, 0.06); }
    public static function easeOutQuint(): self   { return self::memo('easeOutQuint', 0.23, 1.00, 0.32, 1.00); }

    /**
     * Pre-2022 easings.net approximation: cubic-bezier(0.86, 0, 0.07, 1).
     * (The site's 2022 redesign table lists (0.83, 0, 0.17, 1) instead; this
     * preset family consistently ships the original table, see the class
     * docblock.)
     */
    public static function easeInOutQuint(): self { return self::memo('easeInOutQuint', 0.86, 0.00, 0.07, 1.00); }

    public static function easeInExpo(): self     { return self::memo('easeInExpo', 0.95, 0.05, 0.80, 0.00); }
    public static function easeOutExpo(): self    { return self::memo('easeOutExpo', 0.19, 1.00, 0.22, 1.00); }
    public static function easeInOutExpo(): self  { return self::memo('easeInOutExpo', 1.00, 0.00, 0.00, 1.00); }

    public static function easeInCirc(): self     { return self::memo('easeInCirc', 0.60, 0.04, 0.98, 0.34); }
    public static function easeOutCirc(): self    { return self::memo('easeOutCirc', 0.08, 0.82, 0.17, 1.00); }

    /**
     * Pre-2022 easings.net approximation: cubic-bezier(0.785, 0.135, 0.15, 0.86).
     *
     * Repair: this row once shipped byte-identical to {@see easeInOutQuint()}
     * (0.86, 0, 0.07, 1) — a copy-paste error; the InOutQuint row cited there
     * is the genuine table value, the Circ row was the corrupted one.
     * Restored from the original easings.net approximation table (mirrored at
     * https://30secondsofcode.org/css/s/easing-variables/ and
     * https://github.com/NG-ZORRO/ng-zorro-antd default.less), rounded to the
     * 2-dp convention stated in the class docblock.
     */
    public static function easeInOutCirc(): self  { return self::memo('easeInOutCirc', 0.785, 0.135, 0.150, 0.860); }

    // ─── Evaluation ───────────────────────────────────────────────────────

    /**
     * Ease normalized time $t ∈ [0, 1] through this curve — the seam sampler
     * of {@see EasingFunction}.
     *
     * Range honesty: designed for $t in [0, 1]; a non-finite $t (NAN, INF)
     * is refused outright — before that door existed such values silently
     * returned a bogus endpoint.
     * A finite out-of-range $t is NOT rejected and NOT clamped — the Newton
     * solve walks the polynomial outside [0, 1], though past roughly 1e2 the
     * solve loses to the bounded subdivision fallback, which returns an
     * endpoint: on cssEase(), ease(1e3) is exactly 1.0 and ease(-1e3)
     * lands near 0. The RETURN value is likewise unclamped: control points
     * with y outside [0, 1] legitimately overshoot beyond 0..1 progress —
     * that is what the back/elastic style of control point is for, and
     * clamping would flatten exactly the motion those curves exist to
     * express. (Distinct from the `Easing` enum's Back and Elastic cases,
     * which are analytic overshoots rather than beziers; CubicBezier itself
     * ships no Back preset.)
     */
    public function ease(float $t): float
    {
        if (!is_finite($t)) {
            throw new \InvalidArgumentException(Lang::t('bezier.t_finite', ['t' => $t]));
        }

        if ($t === 0.0 || $t === 1.0) {
            return $t;
        }

        // For the trivial linear case, bypass the full solver
        if ($this->x1 === $this->y1 && $this->x2 === $this->y2) {
            return $t;
        }

        // Find t' such that x(t') = $t, then return y(t')
        $tPrime = $this->solveCubicX($t);
        return $this->sampleCurveY($tPrime);
    }

    /**
     * @deprecated Use {@see ease()}, the seam sampler. REMOVED-IN: 2.0.0.
     */
    public function evaluate(float $t): float
    {
        trigger_error(
            'CubicBezier::evaluate() is deprecated; use ease() which satisfies the EasingFunction seam. REMOVED-IN: 2.0.0.',
            E_USER_DEPRECATED,
        );

        return $this->ease($t);
    }

    /**
     * Solve x(t) = $x for t using Newton-Raphson with a binary-search fallback.
     */
    private function solveCubicX(float $x): float
    {
        $t = $x;

        for ($i = 0; $i < self::NEWTON_ITERATIONS; $i++) {
            $currentX = $this->sampleCurveX($t) - $x;
            if (abs($currentX) < self::NEWTON_MIN_STEP) {
                return $t;
            }
            $derivative = $this->sampleCurveDerivativeX($t);
            if (abs($derivative) < self::NEWTON_MIN_STEP) {
                break;
            }
            $t -= $currentX / $derivative;
        }

        return $this->solveCubicXSubdivision($x);
    }

    private function solveCubicXSubdivision(float $x): float
    {
        $lower = 0.0;
        $upper = 1.0;

        // Bounded by construction: each pass halves the bracket, so 64
        // iterations is past the point where $lower and $upper collapse onto
        // the same double (the natural exit). The bound turns a would-be
        // infinite loop on a pathological polynomial into a best-bracket
        // answer — same value, never a hang.
        for ($i = 0; $i < self::MAX_SUBDIVISION_ITERATIONS && $lower < $upper; $i++) {
            $mid = ($lower + $upper) / 2.0;
            $sampleX = $this->sampleCurveX($mid);
            if (abs($sampleX - $x) < $this->epsilon) {
                return $mid;
            }
            if ($sampleX < $x) {
                $lower = $mid;
            } else {
                $upper = $mid;
            }
        }

        // Float-precision exit: $lower and $upper have collapsed onto the same
        // double, straddling the root as tightly as the representation allows.
        // Return that bracket (== $lower == $upper); the earlier spelling
        // returned the initial guess carried in a variable never written here
        // — a dead value that would be WRONG if this branch ever fired.
        return ($lower + $upper) / 2.0;
    }

    // Bézier polynomial with endpoints P0=0, P3=1 and B=x1, C=x2:
    // x(t) = (1+3B-3C)t³ + (3C-6B)t² + 3Bt  (the ax/bx/cx fields hoisted in
    // the constructor are its terms; the samplers below only evaluate)

    private function sampleCurveX(float $t): float
    {
        // WebKit UnitBezier coefficient form — computed once in the
        // constructor (see the coefficient fields) and consumed here and by
        // the Newton derivative from the SAME six floats, so slope and value
        // cannot drift out of agreement (the Bernstein spelling
        // `3(1-t)²t·x1 + 3(1-t)t²·x2 + t³` is algebraically identical but
        // rounds differently).
        // x(t) = ax·t³ + bx·t² + cx·t, endpoints x(0)=0, x(1)=1 baked in.
        return ($this->ax * $t + $this->bx) * $t * $t + $this->cx * $t;
    }

    private function sampleCurveY(float $t): float
    {
        return ($this->ay * $t + $this->by) * $t * $t + $this->cy * $t;
    }

    private function sampleCurveDerivativeX(float $t): float
    {
        // Repair note: the previous spelling 3(t-1)((2-t)x1+(t-2)x2) is NOT
        // d/dt of sampleCurveX() — e.g. at t=0 it returns -6·x1 where the true
        // derivative is 3·x1, and it collapses to 0 for every x1===x2 curve.
        // Closed form of x(t) = ax·t³ + bx·t² + cx·t with the SAME
        // constructor-hoisted coefficients the polynomial sampler reads —
        // the Newton step's slope cannot contradict the value it walks
        // (pre-hoist, this method re-derived ax/bx from a different float
        // association, up to one ulp apart).
        return (3.0 * $this->ax * $t + 2.0 * $this->bx) * $t + $this->cx;
    }
}
