<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

/**
 * 3D point. Same shape as {@see Vector}; kept as a distinct type for
 * the same reason harmonica does — semantically a position vs. a delta.
 *
 * The `$z` axis defaults to `0.0` so existing 2D call sites keep working
 * unchanged.
 */
final class Point implements \JsonSerializable
{
    /**
     * @throws \InvalidArgumentException on a non-finite component (see
     *         {@see Vector} — the same trust boundary for the same reason).
     */
    public function __construct(
        public readonly float $x,
        public readonly float $y,
        public readonly float $z = 0.0,
    ) {
        if (!is_finite($x) || !is_finite($y) || !is_finite($z)) {
            throw new \InvalidArgumentException(Lang::t('point.components_finite', [
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

    /**
     * Factory alias of the constructor — the house `::new()` entry shape
     * (finding 052); the same door as `new Point(...)`.
     */
    public static function new(float $x, float $y, float $z = 0.0): self
    {
        return new self($x, $y, $z);
    }

    /** Add a {@see Vector} delta to this point, returning a new Point. */
    public function add(Vector $v): self
    {
        return new self($this->x + $v->x, $this->y + $v->y, $this->z + $v->z);
    }

    /**
     * The motion primitive: the {@see Vector} from this point to `$to`
     * (`$to − $this` — the displacement a spring or projectile must traverse
     * to get HERE there). Feeds Vector-returning callers (normalize for a
     * direction, length for a budget) without hand-writing the subtraction.
     * Coordinates near 1e308 overflow the difference to INF, which the Vector
     * door refuses outright — distance() answers INF for that pair instead
     * (it never builds a Vector); the asymmetry is by construction, not drift.
     */
    public function delta(self $to): Vector
    {
        return new Vector(
            $to->x - $this->x,
            $to->y - $this->y,
            $to->z - $this->z,
        );
    }

    /**
     * Euclidean distance to another point. Returns INF when the squared
     * deltas sum past PHP_FLOAT_MAX (extreme coordinates ~1e154 and up).
     */
    public function distance(self $other): float
    {
        $dx = $this->x - $other->x;
        $dy = $this->y - $other->y;
        $dz = $this->z - $other->z;
        return sqrt($dx * $dx + $dy * $dy + $dz * $dz);
    }

    public function __toString(): string
    {
        return "Point(x: {$this->x}, y: {$this->y}, z: {$this->z})";
    }

    /**
     * Rebuild from the wire format {@see jsonSerialize()} emits: a positional
     * `[x, y, z]` list (Point carries z like Vector — the serializer always
     * emits three entries). **The index order is contractual and frozen**:
     * this method and the serializer are two halves of one wire format. int
     * entries (json_decode of `1` answers int) are widened here; wrong arity
     * or type is refused before the constructor sees a partial tuple, and
     * finiteness stays the constructor's own door.
     *
     * @param array<array-key, mixed> $wire
     * @throws \InvalidArgumentException on wrong arity, a non-numeric entry,
     *         or (via the constructor) a non-finite component.
     */
    public static function fromArray(array $wire): self
    {
        if (array_keys($wire) !== [0, 1, 2]) {
            throw new \InvalidArgumentException(Lang::t('wire.arity', [
                'class' => 'Point',
                'n' => count($wire),
            ]));
        }
        $components = [];
        foreach ($wire as $index => $value) {
            if (!is_int($value) && !is_float($value)) {
                throw new \InvalidArgumentException(Lang::t('wire.member', [
                    'class' => 'Point',
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
     * payload is a breaking change, not a style edit.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    public function jsonSerialize(): array
    {
        return [$this->x, $this->y, $this->z];
    }
}
