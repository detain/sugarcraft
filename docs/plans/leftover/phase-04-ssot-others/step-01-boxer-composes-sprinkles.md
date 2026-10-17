# Step 04.01 — sugar-panels composes sugar-style Border/Style

**Source:** `leftover_updates_later.md` SSOT-02 + sugar-panels research §3.1
**Branch:** `ai/boxer-composes-sprinkles`

## Deliverable

`sugar-panels` plans `BoxStyle` enum (Single/Double/Round/Bold/Classic/
Hidden/Block), text alignment, box titles, colors, margin — all already
canonical in sugar-style. Refactor sugar-panels to compose
`\SugarCraft\Style\Style` + `\SugarCraft\Style\Border\Border`
instead of rolling its own.

## Files

**Modify:**

- `sugar-panels/src/Boxer.php` — `withBorder(\SugarCraft\Style\Border\Border)`,
  `withStyle(\SugarCraft\Style\Style)`, `withTitle(string)`. Delete
  any internal `Border*` / `BorderChars` / `BorderStyle` enums sugar-panels
  may have ported.
- `sugar-panels/composer.json` — add `"sugarcraft/sugar-style": "@dev"`
  + path-repo.

**Add (sugar-panels-specific):**

- `withMargin(int $top, int $right = 0, int $bottom = 0, int $left = 0)`
  — outer spacing (vs padding which is inner). This stays in sugar-panels
  because sugar-style doesn't ship margin as a first-class concept.
- `withAlign(HAlign|VAlign)` — text alignment.

## Acceptance

- `grep -rn "class BoxStyle\|class BorderChars" sugar-panels/src` returns
  nothing.
- `cd sugar-panels && vendor/bin/phpunit` green.
- `php tools/check-path-repos.php` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
