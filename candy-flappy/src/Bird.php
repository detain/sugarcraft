<?php

declare(strict_types=1);

namespace SugarCraft\Flappy;

use SugarCraft\Spring\Point;
use SugarCraft\Spring\Projectile;
use SugarCraft\Spring\Spring;
use SugarCraft\Spring\Vector;

/**
 * The bird's vertical motion is a SugarSpring {@see Projectile} with
 * gravity pulling down at a constant rate. Tapping `flap` resets the
 * vertical velocity to a fixed upward kick — same trick the original
 * flapioca uses, just expressed as projectile state instead of an
 * ad-hoc accumulator. Mirrors kbrgl/flapioca `Model.Cursor`
 * (internal/model.go).
 *
 * `x` is the bird's column (constant — the world scrolls past it),
 * `y` is its row in the playfield (0 = top).
 */
final class Bird
{
    // Tuned for an 18-row playfield at 30 ticks/sec: a free fall from the
    // mid-screen spawn takes ~1.0s (t = sqrt(2·9/18); not the old ~0.5s
    // face-plant), and a single flap lifts ~3 cells over ~0.55s, so the bird
    // floats and is actually controllable instead of dropping like a stone.
    public const FLAP_KICK     = -10.0; // cells/sec upward
    public const GRAVITY       = 18.0;  // cells/sec²
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
        $advanced = $this->body->update();
        // Clamp the fall speed to terminal velocity — defensive, never
        // ordinary play (M27 comment-truth precedent, flappy-44): the
        // Y-down world tops out at sqrt(10^2 + 2*18*18) ~ 27.4 cells/sec
        // (flap-kick speed at the ceiling, then a free fall across all 18
        // rows) — under one row per tick — so an honest game can never
        // reach 53, let alone teleport through a pipe's gap un-checked.
        // The guard is insurance against a pathological velocity state,
        // mirroring upstream harmonica's terminal clamp.
        if ($advanced->velocity->y > Projectile::TERMINAL_GRAVITY) {
            $advanced = new Projectile(
                deltaTime:    $advanced->deltaTime,
                position:     $advanced->position,
                velocity:     new Vector($advanced->velocity->x, Projectile::TERMINAL_GRAVITY, $advanced->velocity->z),
                acceleration: $advanced->acceleration,
            );
        }
        return new self($this->x, $advanced);
    }

    public function flap(): self
    {
        // Carry the current position, reset vertical velocity to the
        // upward kick. (SugarSpring projectile has no setter; rebuild
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
