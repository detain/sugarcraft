<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Module;

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
