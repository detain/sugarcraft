# Step 07.05 — sugar-vterm SGR underline styles 4:1–4:5 (P1.2)

**Source:** `leftover_updates_later.md` sugar-vterm P1.2
**Branch:** `ai/vt-sgr-underlines`

## Deliverable

SGR `4:1` (single), `4:2` (double), `4:3` (curly), `4:4` (dotted),
`4:5` (dashed), `4:0` (none). Today only plain single underline is
handled.

## Files

**Modify:**

- `sugar-vterm/src/SgrState.php` (in candy-core, actually — check;
  sugar-vterm likely has its own copy) — add `UnderlineStyle` enum
  field. SgrState becomes immutable+fluent for it.
- `sugar-vterm/src/Handler/SgrHandler.php` — recognize `4:N` forms.
- `sugar-vterm/src/Output/Render.php` — emit the corresponding ANSI
  sequences when rendering.

## Tests

- `sugar-vterm/tests/SgrUnderlineStylesTest.php` — feed each form, assert
  state captures correctly; round-trip render.

## Acceptance

- `cd sugar-vterm && vendor/bin/phpunit --filter Underline` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
