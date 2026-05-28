# Step 03.11 — sugar-dash Plot::draw(Buffer) writes cells directly

**Source:** `leftover_updates_later.md` Dash-09 + sugar-dash agent audit
**Branch:** `ai/dash-plot-cells`

## Deliverable

`sugar-dash/src/Plot/Plot.php::draw(Buffer)` currently falls back to
`render()` + `setString()` — slow and defeats the Drawable contract.
Rewrite to write braille cells (`mb_chr(0x2800 + $bits)`) directly
into the Buffer via `setCell()`.

## Files

**Modify:**
- `sugar-dash/src/Plot/Plot.php`:
  - `draw(Buffer $buffer): void` — compute each braille glyph from
    the data series + `MarkerBraille`/`MarkerDot` mode, write each
    glyph via `$buffer->setCell($x, $y, $cell)`.
  - Re-use existing `BrailleCanvas` / `BrailleMatrix` math.
- `sugar-dash/src/Plot/Braille/BrailleCanvas.php` — verify it has a
  `cells(): iterable` method (rather than only a `toString()`); add
  if missing.

## Tests

- `sugar-dash/tests/Plot/PlotDrawIntoBufferTest.php` — known geometry:
  - Line from (0,0) to (7,15) on a 4×4-cell area.
  - Assert exact braille bytes at the expected cells.
  - Assert remaining cells are empty (default Cell).
- Verify `setString` fallback was removed.

## Acceptance

- `grep -n "setString\|render()" sugar-dash/src/Plot/Plot.php` shows no
  Drawable fallback inside `draw()`.
- Byte-level snapshot test passes.
- `cd sugar-dash && vendor/bin/phpunit --filter Plot` green.

## Notes

- The braille math is well-tested in BrailleCanvas; just re-use it.
- Buffer comes from candy-vt (post-SSOT cleanup). Use its `setCell`
  API consistently.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
