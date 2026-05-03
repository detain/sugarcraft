<?php

declare(strict_types=1);

namespace CandyCore\Flap;

use CandyCore\Bounce\Point;
use CandyCore\Bounce\Projectile;
use CandyCore\Bounce\Spring;
use CandyCore\Bounce\Vector;

/**
 * The bird's vertical motion is a HoneyBounce {@see Projectile} with
 * gravity pulling down at a constant rate. Tapping `flap` resets the
 * vertical velocity to a fixed upward kick — same trick the original
 * flapioca uses, just expressed as projectile state instead of an
 * ad-hoc accumulator.
 *
 * `x` is the bird's column (constant — the world scrolls past it),
 * `y` is its row in the playfield (0 = top).
 */
final class Bird
{
    public const FLAP_KICK     = -22.0; // cells/sec upward
    public const GRAVITY       = 70.0;  // cells/sec²
    public const TICKS_PER_SEC = 30;

    public function __construct(
        public readonly int $x,
        public readonly Projectile $body,
    ) {}

    public static function spawn(int $x, float $y): self
    {
        return new self(
            x: $x,
            body: Projectile::new(
                deltaTime:    Spring::fps(self::TICKS_PER_SEC),
                position:     new Point($x, $y),
                velocity:     Vector::zero(),
                acceleration: new Vector(0.0, self::GRAVITY),
            ),
        );
    }

    public function tick(): self
    {
        return new self($this->x, $this->body->update());
    }

    public function flap(): self
    {
        // Carry the current position, reset vertical velocity to the
        // upward kick. (HoneyBounce projectile has no setter; rebuild
        // a fresh one off the current position.)
        return new self(
            $this->x,
            Projectile::new(
                deltaTime:    Spring::fps(self::TICKS_PER_SEC),
                position:     $this->body->position,
                velocity:     new Vector(0.0, self::FLAP_KICK),
                acceleration: new Vector(0.0, self::GRAVITY),
            ),
        );
    }

    public function row(): int
    {
        return (int) round($this->body->position->y);
    }
}
