# Step 03.15 — sugar-dash VHS demos + golden snapshots (part 1 of 2: bulk goldens)

**Source:** `leftover_updates_later.md` Dash-13 (part 1)
**Branch:** `ai/dash-vhs-goldens-1`

## Deliverable

Plan's Phase 7 calls for snapshots at 80×24 AND 120×40 for every
widget. Today coverage is sparse. Part 1 generates the bulk goldens
via a helper script that walks `examples/`.

## Files

**Create:**
- `sugar-dash/tools/generate-goldens.php` — walks every
  `examples/*.php`, sets `COLUMNS=80 LINES=24`, runs the example
  capturing stdout, writes `tests/golden/80x24/<name>.golden`.
  Same again for 120×40.
- `sugar-dash/tests/GoldenSnapshotTest.php` — for every
  `examples/*.php`, asserts current output matches the corresponding
  golden file. Skip examples that take user input (interactive
  dashboards — those run by VHS, not snapshot).

**Generate (committed):**
- `sugar-dash/tests/golden/80x24/<example>.golden` — one file per
  example.
- `sugar-dash/tests/golden/120x40/<example>.golden` — same.

## Acceptance

- `php sugar-dash/tools/generate-goldens.php` regenerates goldens
  idempotently.
- `cd sugar-dash && vendor/bin/phpunit --filter GoldenSnapshot` green.
- Bulk golden files committed.

## Notes

- Interactive examples (`dashboard-live.php`) cannot be snapshot — they
  block on input. Skip those in this step; they get VHS recordings in
  part 2.
- Goldens may shift slightly across PHP version due to terminal width
  edge cases — document `php -v` requirement.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
