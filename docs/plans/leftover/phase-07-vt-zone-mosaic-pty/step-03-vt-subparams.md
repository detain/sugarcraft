# Step 07.03 — candy-vt subparameter parsing CSI 38:2:… (P0.3)

**Source:** `leftover_updates_later.md` candy-vt P0.3
**Branch:** `ai/vt-subparams`

## Deliverable

CSI sub-parameter syntax with colons: `CSI 38:2:R:G:B m` for RGB foreground,
and the corresponding 48:2 / 38:5 / 48:5 / 4:N forms. The parser needs
to recognize `:` as a sub-parameter separator distinct from `;`.

## Files

**Modify:**
- `candy-vt/src/Parser/Parser.php` — sub-parameter aware tokenization.
- `candy-vt/src/Handler/SgrHandler.php` — handle the colon forms.

## Tests

- `candy-vt/tests/Parser/SubparamTest.php` — feed `\x1b[38:2:255:100:50m`,
  assert SGR yields FG RGB 255,100,50.
- Also `\x1b[38;2;255;100;50m` (semicolon form) still parses identically.

## Acceptance

- `cd candy-vt && vendor/bin/phpunit --filter Subparam` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
