<?php

declare(strict_types=1);

namespace SugarCraft\Bounce;

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
     * @param float $tension  Restoring force constant (higher = snappier).
     * @param float $friction Damping force (higher = less oscillation).
     * @param float $mass     Oscillator mass (higher = slower response).
     */
    public function __construct(
        public float $tension,
        public float $friction,
        public float $mass,
    ) {
        $safeMass   = max(0.001, $mass);
        $safeTension = max(0.0, $tension);

        $root = sqrt($safeTension * $safeMass);
        if ($root < 1e-12) {
            $this->angularFrequency = 0.0;
            $this->dampingRatio     = 0.0;
        } else {
            $this->angularFrequency = sqrt($safeTension / $safeMass);
            $this->dampingRatio     = max(0.0, $friction) / (2.0 * $root);
        }
    }

    /**
     * Construct a spring pre-configured at 60 fps by default.
     */
    public function spring(float $deltaTime = 1.0 / 60.0): Spring
    {
        return new Spring($deltaTime, $this->angularFrequency, $this->dampingRatio);
    }

    /**
     * Spring at 60 fps using this config's derived values.
     */
    public function springAt60Fps(): Spring
    {
        return $this->spring(1.0 / 60.0);
    }
}
