# Step 07.02 — sugar-vterm DECAWM auto-wrap (P0.2)

**Source:** `leftover_updates_later.md` sugar-vterm P0.2
**Branch:** `ai/vt-decawm`

## Deliverable

DEC Auto-Wrap Mode (`CSI ? 7 h` enable, `CSI ? 7 l` disable). When
enabled, writing past the right margin wraps to the next line. When
disabled, characters overwrite the rightmost column.

## Files

**Modify:**

- `sugar-vterm/src/Mode/Mode.php` — add `bool $autoWrap` field with
  `withAutoWrap(bool): self` immutable wither.
- `sugar-vterm/src/Handler/ScreenHandler.php` — character-write path
  respects `mode->autoWrap`.
- `sugar-vterm/src/Parser/ModeParser.php` — recognize `? 7 h` / `? 7 l`.

## Tests

- `sugar-vterm/tests/Mode/AutoWrapTest.php` — feed text past margin with
  autowrap on/off; assert respective behaviour.

## Acceptance

- `cd sugar-vterm && vendor/bin/phpunit --filter AutoWrap` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
