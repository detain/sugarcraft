<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

/**
 * Package-level gravity {@see Vector} constants.
 *
 * Mirrors upstream harmonica's package-level
 * `Gravity = Vector{0, -9.81, 0}` and `TerminalGravity = Vector{0, -53, 0}`.
 * PHP `const` expressions DO permit `new` initializers (that landed with
 * new-in-initializers in PHP 8.1, not 8.4 as an earlier note here claimed),
 * but a shared const Vector would be one instance handed to every caller.
 * These are exposed as static accessors returning a fresh {@see Vector}
 * per call — drop-in idioms for callers translating Go reference code,
 * with the fresh-instance contract made explicit.
 *
 * ```php
 * use SugarCraft\Spring\Gravity;
 *
 * $proj = Projectile::new(
 *     deltaTime:    Spring::fps(60),
 *     position:     Point::zero(),
 *     velocity:     new Vector(5.0, 10.0),
 *     acceleration: Gravity::standard(),     // Y-up: (0, -9.81, 0)
 * );
 * ```
 *
 * Each accessor returns a fresh {@see Vector} per call so callers
 * can't accidentally mutate a shared instance — Vector is itself
 * immutable but the contract is clearer this way.
 */
final class Gravity
{
    private function __construct() {}

    /**
     * Standard gravity vector (Y-up): `(0, -9.81, 0)`. Equivalent to
     * harmonica's package-level `Gravity`. Alias of
     * {@see Projectile::gravity()}.
     */
    public static function standard(): Vector
    {
        return Projectile::gravity();
    }

    /**
     * Terminal-velocity gravity (Y-up): `(0, -53, 0)`. Equivalent to
     * harmonica's package-level `TerminalGravity`. Alias of
     * {@see Projectile::terminalGravity()}.
     */
    public static function terminal(): Vector
    {
        return Projectile::terminalGravity();
    }

    /**
     * Y-down standard gravity: `(0, +9.81, 0)`. Use when your model's
     * coordinate system grows downward (the default for terminal
     * grids). Alias of {@see Projectile::gravityYDown()}.
     */
    public static function standardYDown(): Vector
    {
        return Projectile::gravityYDown();
    }

    /**
     * Y-down terminal-velocity gravity: `(0, +53, 0)`. Alias of
     * {@see Projectile::terminalGravityYDown()}.
     */
    public static function terminalYDown(): Vector
    {
        return Projectile::terminalGravityYDown();
    }
}
