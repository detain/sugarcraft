# Step 08.13 — candy-freeze per-segment bg + ligature + language detection

**Source:** `leftover_updates_later.md` candy-freeze §4.1.1 + §4.1.2 + §4.2.2
**Branch:** `ai/freeze-segment-ligature-langdetect`

## Deliverable

- Per-segment background color (`Segment.php:13` TODO — already
  flagged).
- Ligature flag in SVG output `font-variant-ligatures`.
- Language detection (no `LanguageDetector` class today) — heuristic
  based on shebang / extension / content sniffing.

## Files

**Modify:**
- `candy-freeze/src/Segment.php` — `$bg` field, applied in render.

**Create:**
- `candy-freeze/src/LanguageDetector.php`.

**Modify:**
- `candy-freeze/src/Renderer/SvgRenderer.php` — `--ligatures` flag
  emits `font-variant-ligatures: normal`.

## Tests

- One test per feature.

## Acceptance

- `cd candy-freeze && vendor/bin/phpunit --filter "Segment|Ligature|LanguageDetector"` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
