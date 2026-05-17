# Step 04.01 — sugar-boxer composes candy-sprinkles Border/Style

**Source:** `leftover_updates_later.md` SSOT-02 + sugar-boxer research §3.1
**Branch:** `ai/boxer-composes-sprinkles`

## Deliverable

`sugar-boxer` plans `BoxStyle` enum (Single/Double/Round/Bold/Classic/
Hidden/Block), text alignment, box titles, colors, margin — all already
canonical in candy-sprinkles. Refactor sugar-boxer to compose
`\SugarCraft\Sprinkles\Style` + `\SugarCraft\Sprinkles\Border\Border`
instead of rolling its own.

## Files

**Modify:**
- `sugar-boxer/src/Boxer.php` — `withBorder(\SugarCraft\Sprinkles\Border\Border)`,
  `withStyle(\SugarCraft\Sprinkles\Style)`, `withTitle(string)`. Delete
  any internal `Border*` / `BorderChars` / `BorderStyle` enums sugar-boxer
  may have ported.
- `sugar-boxer/composer.json` — add `"sugarcraft/candy-sprinkles": "@dev"`
  + path-repo.

**Add (sugar-boxer-specific):**
- `withMargin(int $top, int $right = 0, int $bottom = 0, int $left = 0)`
  — outer spacing (vs padding which is inner). This stays in sugar-boxer
  because candy-sprinkles doesn't ship margin as a first-class concept.
- `withAlign(HAlign|VAlign)` — text alignment.

## Acceptance

- `grep -rn "class BoxStyle\|class BorderChars" sugar-boxer/src` returns
  nothing.
- `cd sugar-boxer && vendor/bin/phpunit` green.
- `php tools/check-path-repos.php` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
