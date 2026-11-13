<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Module;

use SugarCraft\Core\Msg;
use SugarCraft\Core\Model;

/**
 * Dashboard module contract aligned with \SugarCraft\Core\Model.
 *
 * The Elm-architecture pattern:
 *   - {@see init()} is invoked once on startup and may return an initial Cmd.
 *   - {@see update()} receives every Msg, returning the next module and an
 *     optional Cmd to run asynchronously.
 *   - {@see view()} renders the current module to a string.
 *
 * Modules additionally carry:
 *   - {@see name()} — unique identifier for registry lookup
 *   - {@see minSize()} — minimum terminal dimensions required
 *
 * Update return type is the tuple `[Module, ?Cmd]` where `Cmd` is a
 * `Closure(): ?Msg`. PHP lacks tuples; destructure with
 * `[$module, $cmd] = $module->update($msg)`.
 *
 * Per-event law: `update()` runs for EVERY message the host routes to the
 * module — key presses, focus moves, forwarded child messages alike. Any
 * sampling work (system file I/O, wall-clock/timezone rebuilds, subprocess
 * probes) belongs behind that message's own trigger (typically the tick
 * marker), never on the unconditional path: an unguarded per-event read
 * multiplies a periodic cost by every UI interaction.
 *
 * @extends Model
 */
interface Module extends Model
{
    /**
     * Get the module's unique name.
     */
    public function name(): string;

    /**
     * Get the minimum terminal size required by this module.
     *
     * @return array{0: int, 1: int} [width, height]
     */
    public function minSize(): array;
}
