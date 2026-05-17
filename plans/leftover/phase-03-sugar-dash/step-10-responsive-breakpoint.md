# Step 03.10 — sugar-dash Responsive breakpoint helper

**Source:** `leftover_updates_later.md` Dash-08
**Branch:** `ai/dash-breakpoint`

## Deliverable

Add `Layout/Breakpoint.php` — `Breakpoint::narrow(int $width, int
$threshold = 90): bool`. Wire into `StackedGrid` so multi-column
layouts collapse to single-column under width 90 (Homedash convention).

## Files

**Create:**
- `sugar-dash/src/Layout/Breakpoint.php` — static helper with:
  - `narrow(int $width, int $threshold = 90): bool` — true if width
    below threshold.
  - `medium(int $width, int $narrow = 90, int $wide = 140): bool`.
  - `wide(int $width, int $threshold = 140): bool`.
  - `pick(int $width, array $thresholds): string` — generic.

**Modify:**
- `sugar-dash/src/Layout/Grid/StackedGrid.php`:
  - `render()` checks `Breakpoint::narrow($this->width)`. When narrow,
    flatten to a single column (concatenate column contents vertically).
- `sugar-dash/examples/dashboard-live.php` — confirm behaviour at
  `COLUMNS=80` (narrow) and `COLUMNS=120` (wide).

## Tests

- `sugar-dash/tests/Layout/BreakpointTest.php` — assert thresholds.
- `sugar-dash/tests/Layout/Grid/StackedGridResponsiveTest.php`:
  - Snapshot at `width=80` (narrow, single-col).
  - Snapshot at `width=120` (wide, multi-col).
  - Boundary case `width=90`.

## Acceptance

- `cd sugar-dash && vendor/bin/phpunit --filter "Breakpoint|Responsive"` green.
- Visual diff between 80-wide and 120-wide dashboard renders shows
  expected column collapse.

## Notes

- Document the 90 / 140 default thresholds in the docblock. They're
  the Homedash values and a good baseline.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
