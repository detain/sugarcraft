# Step 02.02 — Move StyleParser to candy-sprinkles

**Source:** `leftover_updates_later.md` SSOT-01g
**Branch:** `ai/sprinkles-styleparser`
**Bundle hint:** standalone

## Deliverable

Move `sugar-dash/src/Foundation/StyleParser.php` (251 LOC, state-machine
parser for the inline `[text](fg:red,bg:blue)` syntax) into
`candy-sprinkles/src/StyleParser.php`. After this, every lib gets the
inline-styling syntax via candy-sprinkles. sugar-dash will retire its
copy in step 03.05.

## Files

**Create:**
- `candy-sprinkles/src/StyleParser.php` — verbatim port of the
  sugar-dash version, except:
  - Namespace becomes `SugarCraft\Sprinkles`.
  - Type-hint return uses `\SugarCraft\Sprinkles\Style` and
    `\SugarCraft\Sprinkles\Cell` (which already live there).
  - Color values via `\SugarCraft\Core\Util\Color`.

**Tests:**
- `candy-sprinkles/tests/StyleParserTest.php` — ported from
  `sugar-dash/tests/Foundation/StyleParserTest.php`. Cases:
  - Nested `[[x]]` parsing.
  - Malformed `[(` returns default-styled cells (no crash).
  - Empty input.
  - All three tokens (fg/bg/mod).
  - Unknown color name → silent ignore (use default).
  - Multi-byte unicode body.
  - Long input (~1MB) — performance smoke.

**Note:**
- Do NOT delete `sugar-dash/src/Foundation/StyleParser.php` in this
  step. That deletion is step 03.05's job. Two parallel parsers ship
  briefly; they are byte-for-byte identical so consumer code is
  unaffected.

## Acceptance

- `cd candy-sprinkles && vendor/bin/phpunit --filter StyleParser` green.
- Doc-comment on `StyleParser` cites the originating sugar-dash file
  and the termui state-machine description from the dash plan.

## Notes

- The dash plan's "clean-room rewrite" caveat (termui is GPLv3, sugar-dash
  is MIT) applies — verify the existing sugar-dash StyleParser.php
  was written from the algorithmic description, not copied from termui
  source. Spot-check the constants and comments.
- If you discover the existing parser quoted GPL'd source, that is a
  Blocker — add to `updates.md` and stop.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
