# Step 21 — sugar-readline onto candy-input

**Branch:** `ai/sugar-readline-input`
**Depends on:** step-06 (candy-input)
**Blocks:** step-24 (vim mode consolidation includes sugar-readline)

## Goal

Migrate `sugar-readline` from its current symbolic-only key handling to a real input driver via `candy-input`. Today sugar-readline cannot read actual terminal keypresses — it only handles pre-decoded symbolic key names. This is the #1 production-blocking issue cited in strategic recommendations.

Reference: §327.7 (candy-input framework), §463 (close input driver gap first).

## Files expected to be modified

- `sugar-readline/composer.json` — add `sugarcraft/candy-input` via `path-repo-closure`.
- `sugar-readline/src/Readline.php` (main runtime) — accept an `InputDriver` parameter; default to `StreamInputDriver::fromStdin()`.
- Existing public API (the symbolic key handler hooks) preserved as the high-level layer; the new layer is `decode bytes → KeyEvent → existing symbolic handler`.

## Acceptance criteria

- [ ] sugar-readline can be constructed with an InputDriver and reads actual TTY keypresses.
- [ ] Symbolic key handlers (e.g., `onKey('Ctrl+C', fn() => ...)`) continue to work.
- [ ] An example or demo at `sugar-readline/examples/interactive.php` actually runs and accepts arrow keys, Ctrl+chars, Esc, F-keys.
- [ ] Tests use candy-input's `StreamInputDriver` with a fake stream for deterministic byte-fed test cases.
- [ ] All existing tests pass.
- [ ] ≥95 % coverage maintained.
- [ ] `git status` clean on master.

## Coder brief

1. **path-repo closure**: candy-input.
2. **Wire InputDriver injection**: `Readline::__construct(?InputDriver $input = null)`. Default-construct `StreamInputDriver::fromStdin()` when null.
3. **Decode loop**: pull events from InputDriver, route KeyEvents to the existing symbolic key handler map.
4. **Mouse / focus / paste**: route Mouse/Focus/Paste events to optional handler hooks (`onMouse(callable)`, `onFocus(callable)`, `onPaste(callable)`) — if no handler is registered, ignore.
5. **Demo**: add `sugar-readline/examples/interactive.php` showing a live readline session with arrow keys + Ctrl+C handling.
6. Run phpunit + check-path-repos.

## Tester brief

- Drive sugar-readline with a `StreamInputDriver` wrapping a fixture-byte stream; assert symbolic handlers fire on correct events.
- Test sequence: `"hello\r"` → handler `onSubmit('hello')` invoked.
- Test sequence: `"\x1b[A"` → handler `onKey('ArrowUp', ...)` invoked.
- Test sequence: `"\x03"` → handler `onKey('Ctrl+C', ...)` invoked.
- Test bracketed paste: `"\x1b[200~pasted text\x1b[201~"` → handler `onPaste('pasted text')` invoked (if registered) else ignored.
- Existing symbolic-handler tests unchanged.

## Scribe brief

- `sugar-readline/README.md`: rewrite the Quickstart to show the new InputDriver flow; mention default StreamInputDriver::fromStdin() makes it just work.
- `sugar-readline/CALIBER_LEARNINGS.md`: "InputDriver is injectable for tests; production defaults to STDIN. Don't reach for STDIN directly."

## Ship brief

- **PR title**: `sugar-readline: production input via candy-input`
- **PR body**:
  ```
  ## Summary
  - sugar-readline now reads real TTY keypresses via candy-input's StreamInputDriver.
  - Symbolic key handlers preserved as the high-level API.
  - Mouse / focus / paste handlers exposed as optional callbacks.
  - examples/interactive.php demonstrates a live session.
  - Unblocks the #1 production-blocker called out in repo_map_update strategic recs.

  ## Test plan
  - [x] vendor/bin/phpunit in sugar-readline (≥95% coverage, fixture-driven byte tests)
  - [x] Demo runs interactively (manual smoke — note in PR if not feasible in CI)
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_21.md, docs/repo_map_update.md §327.7, §463
  ```
- Commit subject: `sugar-readline: production input via candy-input`.
