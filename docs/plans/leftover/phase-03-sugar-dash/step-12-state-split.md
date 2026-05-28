# Step 03.12 — sugar-dash State/State.php split — Tree widget vs Persistence

**Source:** `leftover_updates_later.md` Dash-10 + sugar-dash agent audit
**Branch:** `ai/dash-state-split`

## Deliverable

`sugar-dash/src/State/State.php` has two problems:

1. PSR-4 violation — declares `enum TransitionType`, `class StateNode`,
   `class StateTransition`, AND `class State` in one file.
2. Conceptually misplaced — it's a **UML state-machine diagram widget**
   but the plan intended `src/State/` for **dashboard persistence**.

Split:

- Move the UML widget into `src/Components/Tree/StateMachine.php`,
  splitting each class into its own file.
- Replace `src/State/State.php` with `src/State/Persistence.php`
  implementing Homedash atomic-save: `file_put_contents($tmp); rename($tmp, $path)`.
- Wire `Boxer` / `FocusManager` / `StackedGrid` to optionally save/load
  collapsed panel state and active tab via the persistence layer.

## Files

**Create:**
- `sugar-dash/src/Components/Tree/StateMachine.php` (the diagram
  widget — renamed from State).
- `sugar-dash/src/Components/Tree/StateNode.php`.
- `sugar-dash/src/Components/Tree/StateTransition.php`.
- `sugar-dash/src/Components/Tree/TransitionType.php` (the enum).
- `sugar-dash/src/State/Persistence.php` — `save(string $path, array $data): void`
  and `load(string $path): ?array`. Atomic via tmp+rename.

**Delete:**
- `sugar-dash/src/State/State.php` (the original).

**Modify** consumers:
- Anywhere that used `\SugarCraft\Dash\State\State` for the UML widget
  → `\SugarCraft\Dash\Components\Tree\StateMachine`.
- Wire `Boxer` / `FocusManager` / `StackedGrid` to expose a
  `persistState()` / `restoreState(Persistence $p)` method (optional;
  callers opt in).

## Tests

- `sugar-dash/tests/Components/Tree/StateMachineTest.php` — existing
  state-diagram snapshot test, relocated.
- `sugar-dash/tests/State/PersistenceTest.php` — round-trip across
  process restart (write, read in a separate `phpunit` subprocess if
  feasible; or just save/load in one test as a smoke).

## Acceptance

- `grep -E "^(final |abstract )?(class|enum) " sugar-dash/src/State/*.php sugar-dash/src/Components/Tree/State*.php`
  shows exactly one definition per file (PSR-4 clean).
- `cd sugar-dash && vendor/bin/phpunit --filter "StateMachine|Persistence"` green.

## Notes

- Persistence file format: JSON with versioned schema (`{"version":1, "data":{...}}`).
- Atomic save matters — without it, a crash mid-write corrupts the
  saved state.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
