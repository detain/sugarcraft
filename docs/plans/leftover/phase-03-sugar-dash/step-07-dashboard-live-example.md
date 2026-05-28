# Step 03.07 — Build dashboard-live.php — the headline interactive dashboard

**Source:** `leftover_updates_later.md` Dash-05
**Branch:** `ai/dash-live-example`
**Bundle hint:** depends on steps 03.05 + 03.06 (canonical primitives + Model contract)

## Deliverable

Today `examples/dashboard-*.php` are all static mockups. The "dashboard"
headline feature doesn't have a runnable interactive demo. Build the
canonical one: `examples/dashboard-live.php`.

## Files

**Create:**
- `sugar-dash/examples/dashboard-live.php` — wires:
  - `\SugarCraft\Core\Program` running the event loop.
  - `\SugarCraft\Pty\TermiosFactory` + `SignalForwarder` for raw mode
    + SIGWINCH (zero direct `stty`).
  - `\SugarCraft\Dash\Registry\Registry` with Clock/System/Weather
    modules.
  - `\SugarCraft\Dash\Layout\Boxer\Boxer` address-tree + ModelMap.
  - `\SugarCraft\Dash\Layout\Tile\Resolver` 5-phase resolver.
  - `\SugarCraft\Dash\Layout\FocusManager` for tab/arrow rotation.
  - Per-panel `Cmd::tick(...)` for refresh cadence.
  - Keys: `q` / Ctrl-C quit, `tab` / arrow keys focus rotation.
  - 1Hz Clock + System refresh; Weather every 30 minutes.
- `sugar-dash/tests/Examples/DashboardLiveTest.php` — boot the program
  against a candy-vcr-recorded fixture session; replay a scripted
  sequence; assert exit 0.
- `sugar-dash/.vhs/dashboard-live.tape` — VHS tape recording the
  interactive demo.

**Modify:**
- `.github/workflows/vhs.yml` — add `dashboard-live` to the
  hand-maintained `all=(...)` matrix.
- `sugar-dash/README.md` — link to `.vhs/dashboard-live.gif` and the
  example file in the Quickstart section.

## Acceptance

- `php sugar-dash/examples/dashboard-live.php` runs interactively on
  a real terminal.
- `cd sugar-dash && vendor/bin/phpunit --filter DashboardLive` green.
- `.vhs/dashboard-live.tape` renders without error locally.
- `dashboard-live` appears in `.github/workflows/vhs.yml` matrix.

## Notes

- Weather depends on step 03.08; coordinate — either land 03.08 first
  or include a stub Weather module here and replace later. **Preferred:**
  use a stub Weather here (returns "—°C unavailable" until 03.08).
- Document the example heavily — this is what users read first when
  they want to know "how do I build a dashboard?".
- The supervisor must NOT bundle this with adjacent steps; it's a
  cohesive PR on its own.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
