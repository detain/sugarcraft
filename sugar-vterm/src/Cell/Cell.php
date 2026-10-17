<?php

declare(strict_types=1);

namespace SugarCraft\Vterm\Cell;

/*
 * Backwards-compatible shim.
 *
 * The emulator's full Cell class and the renderer's palette Cell class were
 * unified into the single canonical {@see \SugarCraft\Vterm\Cell} (see that file).
 * Every consumer that wrote `use SugarCraft\Vterm\Cell\Cell;` keeps resolving to
 * the same class through this alias — the two names are literally the same
 * FQN after autoload, per AGENTS.md's façade rule (alias smoke-test only; the
 * real behaviour is exercised by the canonical class's tests).
 *
 * The alias target is spelled with its fully-qualified name (not relative to
 * this namespace) because a class called `SugarCraft\Vterm\Cell` and a namespace
 * `SugarCraft\Vterm\Cell\` share a name prefix — class_alias must be handed the
 * absolute target to avoid colliding with the canonical class's own name.
 *
 * Mirrors charmbracelet/x/vt Cell.
 */
class_alias(\SugarCraft\Vterm\Cell::class, 'SugarCraft\\Vterm\\Cell\\Cell');
