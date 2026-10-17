# Step 08.12 — sugar-style BorderGradientBlend 5-color + Style::patch + rapid blink

**Source:** `leftover_updates_later.md` sugar-style #5, #7, #8
**Branch:** `ai/sprinkles-gradient-patch-blink`

## Deliverable

- BorderGradientBlend 5-color API aligned with lipgloss v2.
- `Style::patch(Style $other): self` — incremental merge.
- Rapid blink variant (SGR 6).

## Files

**Modify:**

- `sugar-style/src/Border/BorderGradientBlend.php` — accept up to
  5 colors; interpolate.
- `sugar-style/src/Style.php` — `patch()` + `withRapidBlink(bool)`.

## Tests

- One test per feature.

## Acceptance

- `cd sugar-style && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
