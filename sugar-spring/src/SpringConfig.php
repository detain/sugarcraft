<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

/**
 * Physical spring parameters that translate into the coefficients consumed
 * by the underlying damped-harmonic-oscillator integration in {@see Spring}.
 *
 * The two derived values are:
 *   angularFrequency = sqrt(tension / mass)
 *   dampingRatio    = friction / (2 * sqrt(tension * mass))
 *
 * SpringConfig is immutable and can be used to construct a {@see Spring}
 * via its factory methods.
 */
final readonly class SpringConfig
{
    public float $angularFrequency;
    public float $dampingRatio;

    /**
     * Factory alias of the constructor — the house `::new()` entry shape
     * (finding 052); the same door as `new SpringConfig(...)`.
     */
    public static function new(float $tension, float $friction, float $mass): self
    {
        return new self($tension, $friction, $mass);
    }

    /**
     * @param float $tension  Restoring force constant (higher = snappier).
     * @param float $friction Damping force (higher = less oscillation).
     * @param float $mass     Oscillator mass (higher = slower response).
     */
    public function __construct(
        public float $tension,
        public float $friction,
        public float $mass,
    ) {
        // Boundary validation mirrors Spring's own.
        //
        // HOW the old shape leaked: NAN slips past `<= 0` (every NAN
        // comparison is false), and the former max(0.0, ·) clamps folded
        // zero/negative tension or friction into ω = 0 / ζ = 0.
        //
        // WHERE it landed: those derived values LEAK through the public
        // readonly properties and, once handed to Spring, produce a spring
        // that never settles.
        //
        // SO: a non-converging config is refused here, at its cause.
        if (!is_finite($tension) || !is_finite($friction) || !is_finite($mass)) {
            throw new \InvalidArgumentException(Lang::t('spring.config_inputs_finite', [
                'tension' => $tension,
                'friction' => $friction,
                'mass' => $mass,
            ]));
        }
        if ($mass <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('spring.config_mass', ['mass' => $mass]));
        }
        if ($tension <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('spring.config_tension', ['tension' => $tension]));
        }
        if ($friction <= 0.0) {
            throw new \InvalidArgumentException(Lang::t('spring.config_friction', ['friction' => $friction]));
        }

        $root = sqrt($tension * $mass);
        $this->angularFrequency = sqrt($tension / $mass);
        // An underflowed zero root must not reach the raw float division —
        // PHP 8.3 faults that as DivisionByZeroError, which would escape the
        // constructor un-guarded. The IEEE answer is infinite damping; hand
        // that honest value to the derived guard below and let it refuse.
        $this->dampingRatio = $root === 0.0 ? INF : $friction / (2.0 * $root);

        // Extreme-but-finite triples (tension/mass overflow, underflow to a
        // zero root) can still derive a non-converging spring. The derived
        // props are public, so poisoned values must not escape them.
        //
        // Huge ζ joins the club: once ζ² − 1 rounds back to ζ²
        // (ζ ≥ 2^26.5), the two over-damped roots are numerically
        // indistinguishable and the baked coefficients freeze the position
        // forever — illegal, not slow.
        if (!is_finite($this->angularFrequency)
            || !is_finite($this->dampingRatio)
            || $this->angularFrequency <= 0.0
            || $this->dampingRatio <= 0.0
            || ($this->dampingRatio * $this->dampingRatio - 1.0) === ($this->dampingRatio * $this->dampingRatio)
        ) {
            throw new \InvalidArgumentException(Lang::t('spring.config_derived', [
                'tension' => $tension,
                'friction' => $friction,
                'mass' => $mass,
                'angularFrequency' => $this->angularFrequency,
                'dampingRatio' => $this->dampingRatio,
            ]));
        }
    }

    /**
     * Construct a spring pre-configured at 60 fps by default.
     *
     * @param bool|null $reducedMotionOverride Overrides the environment
     *        reduced-motion lookup for the produced spring (null = consult env).
     */
    public function spring(float $deltaTime = 1.0 / 60.0, ?bool $reducedMotionOverride = null): Spring
    {
        return new Spring($deltaTime, $this->angularFrequency, $this->dampingRatio, $reducedMotionOverride);
    }

    /**
     * Spring at 60 fps using this config's derived values.
     */
    public function springAt60Fps(?bool $reducedMotionOverride = null): Spring
    {
        return $this->spring(1.0 / 60.0, $reducedMotionOverride);
    }
}
