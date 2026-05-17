# Step 09.11 — candy-log env detection (via palette) + PadLevelText + level values + key styles

**Source:** `leftover_updates_later.md` candy-log §4.1 #1-4
**Branch:** `ai/log-env-padlevel-keys`

## Deliverable

- `NO_COLOR` / `FORCE_COLOR` env detection — consume
  `\SugarCraft\Palette\Probe` (from step 02.03).
- `PadLevelText` — level-label alignment.
- Level numeric values aligned with upstream (-4 / 0 / 4 / 8 / 12).
- Per-field key styles `Styles::Keys[key]`.

## Files

**Modify:**
- `candy-log/src/Logger.php` — Probe-driven color decision.
- `candy-log/src/Level.php` — numeric values.
- `candy-log/src/Styles.php` — Keys map.
- `candy-log/composer.json` — add `sugarcraft/candy-palette`.

## Tests

- One per feature.

## Acceptance

- `cd candy-log && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
