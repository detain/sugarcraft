<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

/**
 * DEPRECATED package-level gravity {@see Vector} aliases.
 *
 * SPR-21 ruling (findings 050/080, shipped default — flip deferred to
 * Phase 5): the canonical home of the gravity presets is the
 * {@see Projectile} statics — `Projectile::gravity()`,
 * `Projectile::terminalGravity()`, `Projectile::gravityYDown()`,
 * `Projectile::terminalGravityYDown()` — because that is where the
 * GRAVITY/TERMINAL_GRAVITY constants live and where the harmonica port
 * actually computes. This façade has always been four pure forwarders;
 * keeping two spellings of one value invites the "which is canonical"
 * drift this file's history proves (fresh-per-call vs memoized, Y-up vs
 * Y-down wording each had to be reconciled TWICE, once per home). Every
 * accessor here emits `E_USER_DEPRECATED` and will be removed in 2.0.0;
 * both stay callable until then (pre-2.0 BC ruling: either direction of
 * retirement breaks somebody, so the choice is loud and dated).
 *
 * The vectors returned through either spelling are SHARED immutable
 * instances: {@see Vector} is readonly, so "a const Vector would be one
 * instance handed to every callers'" worry this docblock once answered
 * is void — callers cannot mutate what they did not build (finding 080
 * memoizes the allocation; the old fresh-per-call contract was
 * inverted here, deliberately and disclosed).
 *
 * Deprecation strings are developer-facing diagnostics, not user-facing
 * messages, so they are not Lang-wrapped (fleet ruling 055, same
 * rationale as SpringCollection's shim emitter).
 *
 * ```php
 * // canonical (stays):
 * $acceleration = Projectile::gravity();   // Y-up: (0, -9.81, 0)
 * ```
 */
final class Gravity
{
    private function __construct() {}

    /**
     * Standard gravity vector (Y-up): `(0, -9.81, 0)`. Mirrors
     * charmbracelet/harmonica.Gravity (the package-level vector).
     *
     * @deprecated Use {@see Projectile::gravity()}. REMOVED-IN: 2.0.0.
     */
    public static function standard(): Vector
    {
        trigger_error(
            'Gravity::standard() is deprecated; use Projectile::gravity() instead. REMOVED-IN: 2.0.0.',
            E_USER_DEPRECATED,
        );

        return Projectile::gravity();
    }

    /**
     * Terminal-velocity gravity (Y-up): `(0, -53, 0)`. Mirrors
     * charmbracelet/harmonica.TerminalGravity (the package-level vector).
     *
     * @deprecated Use {@see Projectile::terminalGravity()}. REMOVED-IN: 2.0.0.
     */
    public static function terminal(): Vector
    {
        trigger_error(
            'Gravity::terminal() is deprecated; use Projectile::terminalGravity() instead. REMOVED-IN: 2.0.0.',
            E_USER_DEPRECATED,
        );

        return Projectile::terminalGravity();
    }

    /**
     * Y-down standard gravity: `(0, +9.81, 0)`. Use when your model's
     * coordinate system grows downward (the default for terminal grids).
     *
     * @deprecated Use {@see Projectile::gravityYDown()}. REMOVED-IN: 2.0.0.
     */
    public static function standardYDown(): Vector
    {
        trigger_error(
            'Gravity::standardYDown() is deprecated; use Projectile::gravityYDown() instead. REMOVED-IN: 2.0.0.',
            E_USER_DEPRECATED,
        );

        return Projectile::gravityYDown();
    }

    /**
     * Y-down terminal-velocity gravity: `(0, +53, 0)`.
     *
     * @deprecated Use {@see Projectile::terminalGravityYDown()}. REMOVED-IN: 2.0.0.
     */
    public static function terminalYDown(): Vector
    {
        trigger_error(
            'Gravity::terminalYDown() is deprecated; use Projectile::terminalGravityYDown() instead. REMOVED-IN: 2.0.0.',
            E_USER_DEPRECATED,
        );

        return Projectile::terminalGravityYDown();
    }
}
