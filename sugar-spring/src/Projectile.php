<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

/**
 * Newtonian-physics projectile. Mirrors charmbracelet/harmonica `NewProjectile`.
 *
 * Each call to {@see update()} advances position by velocity and
 * velocity by acceleration; acceleration usually carries the gravity
 * vector. Use this for arcs / bouncing balls / particle-like animation.
 *
 * `$deltaTime` is the simulation step bake-in matching {@see Spring}'s
 * convention — pass the same value for every update().
 *
 * **Axis convention.** `gravity()` / `terminalGravity()` return **Y-up**
 * vectors (`{0, -9.81, 0}` / `{0, -53, 0}`) — the same values the upstream
 * Charmbracelet harmonica API ships, so an example copied from the harmonica
 * docs behaves here as it does there: gravity pulls toward negative Y, the
 * "up is up" reading those docs assume. The Y-down convention stays reachable
 * as `gravityYDown()` / `terminalGravityYDown()` for code that already maps
 * its own coordinates downward.
 *
 * **Reduced motion.** Deliberately ignores REDUCE_MOTION: this class is NOT
 * spring-based — it is a pure function of time with no target to snap *to*,
 * so the reduced-motion gate belongs in the application (mirror of the note
 * on {@see Spring::update()}).
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
        // Fail-fast, by mechanism: with explicit Euler an INF dt is an INF
        // displacement, a NaN dt poisons every frame after the first, and a
        // negative dt integrates time backwards; a non-finite STATE
        // component never returns to the finite domain. Refuse the poison at
        // construction rather than propagate it.
        //
        // dt = 0 is deliberately ACCEPTED (finding 049): every Euler term
        // multiplies by it, so the step degenerates to a freeze — a useful
        // pause idiom. The opposite ruling in Spring (dt <= 0 refused) is
        // deliberate too — see Spring::__construct's dt guard.
        if (!is_finite($deltaTime)) {
            throw new \InvalidArgumentException(Lang::t('projectile.dt_finite', ['deltaTime' => $deltaTime]));
        }
        if ($deltaTime < 0.0) {
            throw new \InvalidArgumentException(Lang::t('projectile.negative_dt', ['deltaTime' => $deltaTime]));
        }
        foreach (['position' => $position, 'velocity' => $velocity, 'acceleration' => $acceleration] as $name => $vector) {
            if (!is_finite($vector->x) || !is_finite($vector->y) || !is_finite($vector->z)) {
                throw new \InvalidArgumentException(Lang::t('projectile.components_finite', ['name' => $name]));
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
     *
     * Each step builds fresh {@see Point}/{@see Vector} instances, so a
     * state component that overflows to INF mid-simulation is refused by
     * their constructors with an {@see \InvalidArgumentException} naming
     * the component — callers should bound acceleration and velocity
     * rather than discover the throw live. Concretely that is about five
     * allocations per step (two `scale()` products, two `add()` results
     * and the returned Projectile): noise for a bouncing ball, worth
     * counting before driving a 500-particle swarm at frame cadence (the
     * examples/particle.php shape). A deltaTime of 0 is accepted
     * and freezes the integrator: every Euler term is multiplied by it,
     * so the step hands back an identical state (the constructor refuses
     * only non-finite and negative frame times; Spring's opposite dt = 0
     * ruling is documented at its guard).
     *
     * **Mutability note.** Upstream `Projectile.Update()` returns a
     * `Point` (the new position only) and mutates the projectile in
     * place. PHP `update()` instead returns a new `Projectile` instance
     * and leaves the receiver untouched — matching the rest of the
     * SugarCraft stack's immutable-with pattern and avoiding spooky
     * action at a distance: keep a reference to an earlier frame for
     * replay or debugging and it stays exactly that frame.
     * The receiver keeps its old state; read the new
     * position off the RESULT and reassign: `$p = $p->update();`.
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
     * Legacy Y-down gravity. Earlier versions of SugarSpring used
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
