<?php

declare(strict_types=1);

namespace CandyCore\Bounce;

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

    private readonly float $posPosCoef;
    private readonly float $posVelCoef;
    private readonly float $velPosCoef;
    private readonly float $velVelCoef;

    /**
     * @param float $deltaTime         seconds elapsed per integration step
     * @param float $angularFrequency  rad/sec (note: NOT Hz; multiply Hz by 2π)
     * @param float $dampingRatio      <1 = oscillating, =1 = critical, >1 = over-damped
     */
    public function __construct(
        float $deltaTime,
        float $angularFrequency,
        float $dampingRatio,
    ) {
        $angularFrequency = max(0.0, $angularFrequency);
        $dampingRatio     = max(0.0, $dampingRatio);

        if ($angularFrequency < self::EPSILON) {
            // No restoring force — identity transform.
            $this->posPosCoef = 1.0;
            $this->posVelCoef = 0.0;
            $this->velPosCoef = 0.0;
            $this->velVelCoef = 1.0;
            return;
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
            return;
        }

        if ($dampingRatio < 1.0 - self::EPSILON) {
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
            return;
        }

        // Critically damped (|ζ - 1| ≤ ε).
        $expTerm     = exp(-$angularFrequency * $deltaTime);
        $timeExp     = $deltaTime * $expTerm;
        $timeExpFreq = $timeExp * $angularFrequency;

        $this->posPosCoef = $timeExpFreq + $expTerm;
        $this->posVelCoef = $timeExp;
        $this->velPosCoef = -$angularFrequency * $timeExpFreq;
        $this->velVelCoef = -$timeExpFreq + $expTerm;
    }

    /**
     * Advance one step toward $target, returning the new position and velocity.
     *
     * @return array{0:float,1:float}
     */
    public function update(float $pos, float $vel, float $target): array
    {
        $oldPos = $pos - $target;
        $newPos = $oldPos * $this->posPosCoef + $vel * $this->posVelCoef + $target;
        $newVel = $oldPos * $this->velPosCoef + $vel * $this->velVelCoef;
        return [$newPos, $newVel];
    }

    /** Frame-time (seconds) for a given frame rate. `Spring::fps(60)` → 1/60. */
    public static function fps(int $n): float
    {
        if ($n <= 0) {
            throw new \InvalidArgumentException("fps must be > 0; got $n");
        }
        return 1.0 / $n;
    }
}
