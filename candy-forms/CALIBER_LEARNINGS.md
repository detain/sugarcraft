# Caliber Learnings — candy-forms

> Accumulated patterns and gotchas discovered during development.
> This file grows over time — do not delete entries from previous sessions.

<!-- Add learnings below as they are discovered -->

## Spinner absorbed from sugar-bits

`Spinner`, `Spinner\Style`, and `Spinner\TickMsg` were moved here from
`sugar-bits/src/Spinner/` (namespace `SugarCraft\Bits\Spinner` →
`SugarCraft\Forms\Spinner`). The sugar-bits files are now thin
`class_alias` shims — same pattern as the other extracted primitives.

## candy-forms owns its tests; leaf libs keep alias-integration tests

candy-forms now carries its own direct test suite under `tests/`
(namespace `SugarCraft\Forms\Tests\*`) exercising the real
`SugarCraft\Forms\*` classes. The original tests in `sugar-bits/tests/`
and `sugar-prompt/tests/` were COPIED (not moved) and stay in place — they
exercise the same classes through the `class_alias` shims, giving us
alias-integration coverage. Do not delete the leaf-lib copies.

## class_alias shim pattern (canonical)

A re-exporting leaf class is a 6-line file: `declare(strict_types=1);`,
the leaf namespace, a `// @deprecated Use SugarCraft\Forms\...` comment, and
a single `class_alias('SugarCraft\Forms\X\Y', 'SugarCraft\Bits\X\Y');`. The
alias only fires when the autoloader loads the shim (triggered by a `use` of
the leaf FQN), so every consuming lib must `require sugarcraft/candy-forms`
for the alias target to resolve.

## Watch for stray leaf-namespace refs after extraction

`Scrollbar\ScrollbarState` still imported `SugarCraft\Bits\Lang` after the
extraction — invisible inside sugar-bits (where that class autoloads) but a
fatal `Class not found` when candy-forms runs standalone. A direct test suite
catches these; grep `src/` for `SugarCraft\\Bits` / `SugarCraft\\Prompt` after
any extraction. Also keep the lang keys (`spinner.*`, `scrollbar.*`) in
`candy-forms/lang/en.php` in sync with what `src/` references.
