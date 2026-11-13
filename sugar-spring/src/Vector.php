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
 *
 * Two refusal families, two classes, by taxonomy: the CONSTRUCTOR guards
 * the data plane — a non-finite component is bad input, answered with
 * `InvalidArgumentException`; `normalize()` guards the operation — a
 * degenerate length (zero-band or overflowed) is a computation with no
 * honest answer, answered with `RuntimeException`. Pattern-match what you
 * mean: catching only `InvalidArgumentException` around `normalize()` lets
 * a zero vector crash a direction-from-input path.
 */
final class Vector implements \JsonSerializable
{
    private const EPSILON = 1e-9;

    /**
     * @throws \InvalidArgumentException on a non-finite component — NaN/INF
     *         would propagate through every add/scale/length downstream, and
     *         the wire format has no vocabulary for them: json_encode of a
     *         non-finite vector collapses to a bare `false` with no cause
     *         attached — nothing a caller downstream can act on.
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
     * Guard band, stated honestly: the refusal is `length < EPSILON`
     * (1e-9), not `length == 0` — a vector as small as (1e-10, 0, 0) is
     * answered with the zero-length message, and that is the documented
     * behaviour, not a misreport. The rejected alternative (divide by the
     * true length whatever it is) was dropped on purpose: below the band a
     * "direction" is float noise amplified to unit scale, and the NaN case
     * slips through ANY length comparison — the band is the door that holds.
     *
     * @throws \RuntimeException if called on a zero-OR-SUB-BAND length
     *         (see above), or on one whose length OVERFLOWED to INF (finite
     *         components around 1e154 and up sum their squares past
     *         float_MAX) — dividing by INF would silently answer the zero
     *         vector, the opposite of a unit vector.
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
     * Rebuild from the wire format {@see jsonSerialize()} emits: a positional
     * `[x, y, z]` list. **The index order is contractual and frozen** — this
     * method and the serializer are two halves of one wire format, so no
     * component may ever move or gain a name. int entries (json_decode of
     * `1` answers int) are widened here; everything else about the members —
     * type, arity — is refused before the constructor ever sees a partial
     * tuple, and finiteness stays the constructor's own door (one guard, no
     * duplicated policy).
     *
     * @param array<array-key, mixed> $wire
     * @throws \InvalidArgumentException on wrong arity, a non-numeric entry,
     *         or (via the constructor) a non-finite component — the data
     *         plane, per the class taxonomy.
     */
    public static function fromArray(array $wire): self
    {
        if (array_keys($wire) !== [0, 1, 2]) {
            throw new \InvalidArgumentException(Lang::t('wire.arity', [
                'class' => 'Vector',
                'n' => count($wire),
            ]));
        }
        $components = [];
        foreach ($wire as $index => $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException(Lang::t('wire.member', [
                    'class' => 'Vector',
                    'index' => $index,
                    'type' => get_debug_type($value),
                ]));
            }
            $components[] = (float) $value;
        }
        return new self(...$components);
    }

    /**
     * Wire format: positional `[x, y, z]`. The index order is contractual —
     * {@see fromArray()} decodes this exact shape and a re-ordered or keyed
     * payload is a breaking change to every serialized vector, not a style
     * edit.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function jsonSerialize(): array
    {
        return [$this->x, $this->y, $this->z];
    }
}
