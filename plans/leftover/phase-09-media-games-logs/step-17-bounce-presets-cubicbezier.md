# Step 09.17 — honey-bounce SpringPreset + SpringConfig + CubicBezier

**Source:** `leftover_updates_later.md` honey-bounce §4.1-2 P1
**Branch:** `ai/bounce-presets-cubicbezier`

## Deliverable

- `SpringPreset` enum: Gentle / Wobbly / Stiff / Slow / Molasses.
- `SpringConfig` with `tension` / `friction` / `mass` translating to
  `angularFreq` / `dampingRatio`.
- `CubicBezier` easing class with CSS-standard easings (ease-in,
  ease-out, ease-in-out, etc.).

## Files

**Create:**
- `honey-bounce/src/SpringPreset.php` (enum).
- `honey-bounce/src/SpringConfig.php`.
- `honey-bounce/src/Easing/CubicBezier.php`.

**Modify:**
- `honey-bounce/src/Spring.php` — `fromPreset(SpringPreset)` factory.

## Tests

- One per feature.

## Acceptance

- `cd honey-bounce && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
