<?php

declare(strict_types=1);

namespace SugarCraft\Spring\Easing;

use SugarCraft\Spring\Lang;

/**
 * Cubic bezier easing functions — matches the CSS `cubic-bezier()` spec.
 *
 * Use the static factory methods to construct named presets. The algorithm
 * follows the canonical CSS spec: find t such that x(t) = input, then return y(t).
 * The solver only INVERTS x(t) — it does not make anything monotonic. The
 * x(t) time mapping is guaranteed monotone by the constructor's x1/x2 ∈ [0, 1]
 * range check (the very condition the CSS spec imposes for that reason); the
 * y output may overshoot [0, 1] when the y control points do, which is the
 * whole point of back/elastic-style curves.
 *
 * The named family beyond the four CSS keywords ships the ORIGINAL (pre-2022)
 * easings.net cubic-bezier approximation table; the site's 2022 redesign
 * revised several rows. Do not mix generations — the easeInOutCirc repair
 * restored the original-table Circ value precisely so the family stays
 * internally consistent with its Quint/Expo neighbours.
 *
 * @see https://www.w3.org/TR/css-easing-3/#cubic-bezier-algo
 * @see https://github.com/ai/easings.net (2022 table in src/easings.yml; pre-2022 table mirrored at https://30secondsofcode.org/css/s/easing-variables/)
 */
final readonly class CubicBezier
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
     */
    private const MAX_SUBDIVISION_ITERATIONS = 64; // float bisection converges < 53; 64 is a loud safety net

    /** Control point 1 x */
    private float $x1;
    /** Control point 1 y */
    private float $y1;
    /** Control point 2 x */
    private float $x2;
    /** Control point 2 y */
    private float $y2;

    private float $epsilon;

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
        $this->epsilon = self::SUBDIVISION_PRECISION;
    }

    // ─── CSS named easings ────────────────────────────────────────────────

    public static function ease(): self        { return new self(0.25, 0.10, 0.25, 1.00); }
    public static function easeIn(): self      { return new self(0.42, 0.00, 1.00, 1.00); }
    public static function easeOut(): self     { return new self(0.00, 0.00, 0.58, 1.00); }
    public static function easeInOut(): self   { return new self(0.42, 0.00, 0.58, 1.00); }
    public static function linear(): self      { return new self(0.00, 0.00, 1.00, 1.00); }

    public static function easeInSine(): self     { return new self(0.47, 0.00, 0.75, 0.72); }
    public static function easeOutSine(): self      { return new self(0.39, 0.58, 0.57, 1.00); }
    public static function easeInOutSine(): self    { return new self(0.37, 0.00, 0.63, 1.00); }

    public static function easeInQuad(): self       { return new self(0.55, 0.09, 0.68, 0.53); }
    public static function easeOutQuad(): self      { return new self(0.25, 0.46, 0.45, 0.94); }
    public static function easeInOutQuad(): self     { return new self(0.46, 0.03, 0.52, 0.64); }

    public static function easeInCubic(): self      { return new self(0.55, 0.06, 0.68, 0.19); }
    public static function easeOutCubic(): self     { return new self(0.22, 0.61, 0.36, 1.00); }
    public static function easeInOutCubic(): self   { return new self(0.65, 0.05, 0.35, 1.00); }

    public static function easeInQuart(): self      { return new self(0.70, 0.00, 0.84, 0.00); }
    public static function easeOutQuart(): self     { return new self(0.17, 0.89, 0.32, 1.00); }
    public static function easeInOutQuart(): self    { return new self(0.76, 0.00, 0.24, 1.00); }

    public static function easeInQuint(): self      { return new self(0.86, 0.00, 0.07, 0.00); }
    public static function easeOutQuint(): self     { return new self(0.23, 1.00, 0.32, 1.00); }

    /**
     * Pre-2022 easings.net approximation: cubic-bezier(0.86, 0, 0.07, 1).
     * (The site's 2022 redesign table lists (0.83, 0, 0.17, 1) instead; this
     * preset family consistently ships the original table, see the class
     * docblock.)
     */
    public static function easeInOutQuint(): self    { return new self(0.86, 0.00, 0.07, 1.00); }

    public static function easeInExpo(): self        { return new self(0.95, 0.05, 0.80, 0.00); }
    public static function easeOutExpo(): self       { return new self(0.19, 1.00, 0.22, 1.00); }
    public static function easeInOutExpo(): self     { return new self(1.00, 0.00, 0.00, 1.00); }

    public static function easeInCirc(): self        { return new self(0.60, 0.04, 0.98, 0.34); }
    public static function easeOutCirc(): self      { return new self(0.16, 1.00, 0.30, 1.00); }

    /**
     * Pre-2022 easings.net approximation: cubic-bezier(0.785, 0.135, 0.15, 0.86).
     *
     * Repair: this row once shipped byte-identical to {@see easeInOutQuint()}
     * (0.86, 0, 0.07, 1) — a copy-paste error; the Quint row is the genuine
     * table value, the Circ row was the corrupted one. Restored from the
     * original easings.net approximation table (mirrored at
     * https://30secondsofcode.org/css/s/easing-variables/ and
     * https://github.com/NG-ZORRO/ng-zorro-antd default.less), of which every
     * neighbouring preset here is a member.
     */
    public static function easeInOutCirc(): self      { return new self(0.785, 0.135, 0.150, 0.860); }

    // ─── Evaluation ───────────────────────────────────────────────────────

    /**
     * Evaluate the cubic bezier at normalized time $t ∈ [0, 1].
     *
     * Range honesty: designed for $t in [0, 1]; a non-finite $t (NAN, INF)
     * is refused outright — before that door existed such values silently
     * returned a bogus endpoint.
     * A finite out-of-range $t is NOT rejected and NOT clamped — the Newton
     * solve walks the polynomial outside [0, 1], though past roughly 1e2 the
     * solve loses to the bounded subdivision fallback, which returns an
     * endpoint: on ease(), evaluate(1e3) is exactly 1.0 and evaluate(-1e3)
     * lands near 0. The RETURN value is likewise unclamped: control points with y
     * outside [0, 1] (the back/elastic style of control point) legitimately overshoot
     * beyond 0..1 progress — note that the `Easing` enum's Back and Elastic cases are
     * analytic overshoots, not beziers, and CubicBezier ships no Back preset itself —
     * and clamping would flatten exactly the motion those curves exist to express.
     */
    public function evaluate(float $t): float
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

    // Bézier polynomial: (1-3B+3C-D)t³ + (3B-6C+3D)t² + (-3B+3C)t + D
    // with B=x1, C=x2, D=0 → simplifies to (3x1-3x2)t³ + (-6x1+3x2)t² + (3x1)t

    private function sampleCurveX(float $t): float
    {
        // WebKit UnitBezier coefficient form — the SAME representation the
        // Newton derivative uses below, so slope and value cannot drift out of
        // agreement (the Bernstein spelling `3(1-t)²t·x1 + 3(1-t)t²·x2 + t³`
        // is algebraically identical but rounds differently).
        // x(t) = ax·t³ + bx·t² + cx·t, endpoints x(0)=0, x(1)=1 baked in.
        $cx = 3.0 * $this->x1;
        $bx = 3.0 * ($this->x2 - $this->x1) - $cx;
        $ax = 1.0 - $cx - $bx;

        return ($ax * $t + $bx) * $t * $t + $cx * $t;
    }

    private function sampleCurveY(float $t): float
    {
        $cy = 3.0 * $this->y1;
        $by = 3.0 * ($this->y2 - $this->y1) - $cy;
        $ay = 1.0 - $cy - $by;

        return ($ay * $t + $by) * $t * $t + $cy * $t;
    }

    private function sampleCurveDerivativeX(float $t): float
    {
        // Repair note: the previous spelling 3(t-1)((2-t)x1+(t-2)x2) is NOT
        // d/dt of sampleCurveX() — e.g. at t=0 it returns -6·x1 where the true
        // derivative is 3·x1, and it collapses to 0 for every x1===x2 curve.
        // Closed form of x(t) = at³ + bt² + ct with the WebKit UnitBezier
        // coefficients (the Newton step's slope must match the polynomial or
        // the solver relies on the subdivision fallback to mask the damage).
        $ax = 1.0 - 3.0 * $this->x2 + 3.0 * $this->x1;
        $bx = 3.0 * $this->x2 - 6.0 * $this->x1;
        $cx = 3.0 * $this->x1;

        return (3.0 * $ax * $t + 2.0 * $bx) * $t + $cx;
    }
}
