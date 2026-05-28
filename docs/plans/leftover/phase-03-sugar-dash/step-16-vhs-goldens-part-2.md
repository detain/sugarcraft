# Step 03.16 — sugar-dash VHS demos + golden snapshots (part 2 of 2: headline tapes)

**Source:** `leftover_updates_later.md` Dash-13 (part 2)
**Branch:** `ai/dash-vhs-goldens-2`

## Deliverable

VHS tapes for the headline interactive demos. Hand-curated, not bulk.

## Files

**Create:**
- `sugar-dash/.vhs/dashboard-live.tape` — drives `dashboard-live.php`
  through quit-from-rotation, focus tab, panel collapse.
- `sugar-dash/.vhs/plot-braille.tape` — same data with `MarkerDot`
  then `MarkerBraille`, side-by-side comparison.
- `sugar-dash/.vhs/gridtable.tape` — sort + filter + page cycle.
- `sugar-dash/.vhs/boxer.tape` — split panel with three named leaves,
  focus rotation.

**Modify:**
- `.github/workflows/vhs.yml` — add the four new tape names to the
  hand-maintained `all=(...)` matrix.
- `sugar-dash/README.md` — embed the rendered `.gif`s.

## Acceptance

- VHS locally produces clean `.gif` files for each tape.
- All four entries appear in `.github/workflows/vhs.yml` matrix.
- README image links resolve.

## Notes

- Tape preamble: `Set Theme "TokyoNight"`, terminal `80x24` for sample
  size; `Type "..."`, `Enter`, `Sleep 2s`, optional keystrokes.
- Don't try to record every example — just the visually-compelling
  ones. Other examples are covered by golden snapshots in part 1.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
