### C-SEC-5.c — TUI trust dialog component
- item: C-SEC-5 (sources: GAP-PER-11 TUI half)
- kind: dormant-wire · effort S
- approach:
  - A self-contained immutable `TrustDialog` model. It takes the `TrustTarget` list from 5.a and the root.
  - It shows one row per target, with what granting enables (hooks run programs, MCP starts servers, commands may run `!` forms, settings may change N keys) and the frozen-roster line "takes effect next launch".
  - Keys: `y` grant this, `a` grant all, `n` skip this, `Esc` dismiss. It returns choices as data and performs no writes.
  - `view(int $cols, int $rows, Theme)` returns the boxed string, so the step needs no Renderer edit.
- files.create: `candy-code/src/Tui/TrustDialog.php`, `candy-code/tests/Tui/TrustDialogTest.php`
- files.modify: `candy-code/lang/en.php` (`tui.trust.*`)
- tests: the new `TrustDialogTest` covers the key arms, the grant-all list, the box width at narrow cols (no over-wide lines, per the TUI render invariant), and the snapshot bytes.
- docs/guards: none. The dialog keys are local to the modal and do not join `KeyBindingRegistry`; if the lane registers them, `KeyBindingDriftTest` and its doc move in-step.
- serialized: [lang:tui.trust.]
- deps: C-SEC-5.a
- phase: normal
- ruling: none
- risks: none; the component is inert until 5.d wires it.

