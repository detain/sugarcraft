<?php

declare(strict_types=1);

namespace SugarCraft\Bounce;

/**
 * Newtonian-physics projectile. Ports `harmonica.NewProjectile`.
 *
 * Each call to {@see update()} advances position by velocity and
 * velocity by acceleration; acceleration usually carries the gravity
 * vector. Use this for arcs / bouncing balls / particle-like animation.
 *
 * `$deltaTime` is the simulation step bake-in matching {@see Spring}'s
 * convention — pass the same value for every update().
 *
 * **Axis convention.** `gravity()` / `terminalGravity()` now return
 * **Y-up** vectors (`{0, -9.81, 0}` / `{0, -53, 0}`) to match the
 * upstream Charmbracelet harmonica API. If you copied an example from
 * the harmonica docs, it will fly the right direction. The previous
 * Y-down convention is still reachable as
 * `gravityYDown()` / `terminalGravityYDown()` for back-compat.
 *
 * ```php
 * $p = Projectile::new(
 *     deltaTime: Spring::fps(60),
 *     position:  Point::zero(),
 *     velocity:  new Vector(5.0, 10.0),     // upward (Y-up)
 *     acceleration: Projectile::gravity(),   // pulls down (negative Y)
 * );
 * for ($i = 0; $i < 60; $i++) {
 *     $p = $p->update();
 * }
 * ```
 */
final class Projectile
{
    /** Standard gravity magnitude. Mirrors `harmonica.Gravity` value. */
    public const GRAVITY = 9.81;

    /** Approximate skydiver terminal-velocity magnitude. Mirrors `harmonica.TerminalGravity`. */
    public const TERMINAL_GRAVITY = 53.0;

    public function __construct(
        public readonly float  $deltaTime,
        public readonly Point  $position,
        public readonly Vector $velocity,
        public readonly Vector $acceleration,
    ) {
        // A3b fail-fast: explicit Euler with a non-finite or negative step
        // diverges (INF dt ⇒ INF displacement; NaN dt poisons every frame
        // after the first; negative dt integrates time backwards), and a
        // non-finite state component never returns to the finite domain —
        // reject the poison at construction rather than propagating it.
        if (!is_finite($deltaTime)) {
            throw new \InvalidArgumentException("deltaTime must be finite; got {$deltaTime}");
        }
        if ($deltaTime < 0.0) {
            throw new \InvalidArgumentException("deltaTime must be >= 0; got {$deltaTime}");
        }
        foreach (['position' => $position, 'velocity' => $velocity, 'acceleration' => $acceleration] as $name => $vector) {
            if (!is_finite($vector->x) || !is_finite($vector->y) || !is_finite($vector->z)) {
                throw new \InvalidArgumentException("Projectile {$name} components must be finite");
            }
        }
    }

    public static function new(
        float  $deltaTime,
        Point  $position,
        Vector $velocity,
        Vector $acceleration,
    ): self {
        return new self($deltaTime, $position, $velocity, $acceleration);
    }

    /**
     * Advance the simulation by one step. Returns a new Projectile —
     * the original is untouched. Position += velocity·dt; velocity +=
     * acceleration·dt.
     */
    public function update(): self
    {
        $newPos = $this->position->add($this->velocity->scale($this->deltaTime));
        $newVel = $this->velocity->add($this->acceleration->scale($this->deltaTime));
        return new self($this->deltaTime, $newPos, $newVel, $this->acceleration);
    }

    public function position(): Point      { return $this->position; }
    public function velocity(): Vector     { return $this->velocity; }
    public function acceleration(): Vector { return $this->acceleration; }

    /**
     * Gravity vector — Y-up, matching upstream harmonica
     * `Gravity = Vector{0, -9.81, 0}`. Use this when porting examples
     * from the harmonica docs verbatim.
     *
     * **Mutability note.** Upstream `Projectile.Update()` returns a
     * `Point` (the new position only) and mutates the projectile in
     * place. PHP `Projectile::update()` instead returns a new
     * `Projectile` instance and leaves the receiver untouched —
     * matches the rest of the SugarCraft stack's immutable-with-
     * pattern and avoids spooky action when you keep a reference to
     * an earlier frame for replay / debugging. Read the new position
     * from `result->position` after each call.
     */
    public static function gravity(): Vector
    {
        return new Vector(0.0, -self::GRAVITY, 0.0);
    }

    /**
     * Terminal-velocity gravity — Y-up, matching upstream harmonica
     * `TerminalGravity = Vector{0, -53, 0}`.
     */
    public static function terminalGravity(): Vector
    {
        return new Vector(0.0, -self::TERMINAL_GRAVITY, 0.0);
    }

    /**
     * Legacy Y-down gravity. Earlier versions of HoneyBounce used
     * `(0, +9.81, 0)` (Y-down) — kept here so existing PHP code that
     * relied on the old convention can opt back in via a single name
     * change without flipping every Y-coord in the model.
     */
    public static function gravityYDown(): Vector
    {
        return new Vector(0.0, self::GRAVITY, 0.0);
    }

    /** Legacy Y-down terminal-velocity gravity. See {@see gravityYDown()}. */
    public static function terminalGravityYDown(): Vector
    {
        return new Vector(0.0, self::TERMINAL_GRAVITY, 0.0);
    }
}
