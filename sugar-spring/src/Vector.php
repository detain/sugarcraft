<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

/**
 * 3D vector for {@see Projectile}. Immutable.
 *
 * Mirrors charmbracelet/harmonica.Vector — the same Go value type is used
 * here both for positions
 * (X / Y / Z in arbitrary units) and for velocities / accelerations
 * (units per simulation step).
 *
 * The `$z` axis defaults to `0.0` so existing 2D call sites keep working
 * unchanged. Use the third component for true 3D simulations or to
 * express depth in pseudo-3D scenes.
 */
final class Vector implements \JsonSerializable
{
    private const EPSILON = 1e-9;

    /**
     * @throws \InvalidArgumentException on a non-finite component — NaN/INF
     *         would propagate through every add/scale/length downstream and
     *         jsonSerialize() false on INF, far from the cause.
     */
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $z = 0.0,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($z)) {
            throw new \InvalidArgumentException(Lang::t('vector.components_finite', [
                'x' => $x,
                'y' => $y,
                'z' => $z,
            ]));
        }
    }

    public static function zero(): self
    {
        return new self(0.0, 0.0, 0.0);
    }

    public function add(self $other): self
    {
        return new self(
            $this->x + $other->x,
            $this->y + $other->y,
            $this->z + $other->z,
        );
    }

    public function sub(self $other): self
    {
        return new self(
            $this->x - $other->x,
            $this->y - $other->y,
            $this->z - $other->z,
        );
    }

    /** Scale all three components by `$s`. */
    public function scale(float $s): self
    {
        return new self($this->x * $s, $this->y * $s, $this->z * $s);
    }

    /**
     * Euclidean length in 3D. Returns INF when the squared components sum
     * past PHP_FLOAT_MAX (finite components ~1e154 and up).
     */
    public function length(): float
    {
        return sqrt($this->x * $this->x + $this->y * $this->y + $this->z * $this->z);
    }

    /** Dot product. */
    public function dot(self $other): float
    {
        return $this->x * $other->x + $this->y * $other->y + $this->z * $other->z;
    }

    /** Cross product. Returns a new perpendicular {@see Vector}. */
    public function cross(self $other): self
    {
        return new self(
            $this->y * $other->z - $this->z * $other->y,
            $this->z * $other->x - $this->x * $other->z,
            $this->x * $other->y - $this->y * $other->x,
        );
    }

    /**
     * Squared Euclidean length — avoids sqrt() for comparisons.
     * Returns INF on overflow exactly like {@see length()} (components ~1e154
     * and up).
     */
    public function lengthSquared(): float
    {
        return $this->x * $this->x + $this->y * $this->y + $this->z * $this->z;
    }

    /**
     * Normalized (unit) vector.
     *
     * @throws \RuntimeException if called on a zero-length vector, or on one
     *         whose length OVERFLOWED to INF (finite components around 1e154
     *         and up sum their squares past float_MAX) — dividing by INF would
     *         silently answer the zero vector, the opposite of a unit vector.
     */
    public function normalize(): self
    {
        $len = $this->length();
        if (!is_finite($len)) {
            throw new \RuntimeException(Lang::t('vector.normalize_overflow', ['length' => $len]));
        }
        if ($len < self::EPSILON) {
            throw new \RuntimeException(Lang::t('vector.normalize_zero'));
        }
        return $this->scale(1.0 / $len);
    }

    /**
     * Linear interpolation between this vector and $other.
     *
     * @param self $other  The target vector
     * @param float $t     Interpolation factor (0.0 = this, 1.0 = $other)
     */
    public function lerp(self $other, float $t): self
    {
        return new self(
            $this->x + ($other->x - $this->x) * $t,
            $this->y + ($other->y - $this->y) * $t,
            $this->z + ($other->z - $this->z) * $t,
        );
    }

    public function __toString(): string
    {
        return "Vector(x: {$this->x}, y: {$this->y}, z: {$this->z})";
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function jsonSerialize(): array
    {
        return [$this->x, $this->y, $this->z];
    }
}
