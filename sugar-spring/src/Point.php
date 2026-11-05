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

    /** Add a {@see Vector} delta to this point, returning a new Point. */
    public function add(Vector $v): self
    {
        return new self($this->x + $v->x, $this->y + $v->y, $this->z + $v->z);
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
     * @return array{0: float, 1: float, 2: float}
     */
    public function jsonSerialize(): array
    {
        return [$this->x, $this->y, $this->z];
    }
}
