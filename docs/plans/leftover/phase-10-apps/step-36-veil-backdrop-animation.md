# Step 10.36 — sugar-overlay backdrop dimming + animation system

**Source:** `leftover_updates_later.md` sugar-overlay §4.1-2 High
**Branch:** `ai/veil-backdrop-animation`

## Deliverable

- Backdrop dimming effect (semi-transparent overlay).
- Animation system — slide / fade / scale (consume sugar-spring
  CubicBezier from step 09.17).

## Files

**Modify:** `sugar-overlay/src/Veil.php` — `withBackdrop(int $opacity)`,
`withAnimation(AnimationKind)`.

**Create:** `sugar-overlay/src/Animation/{Slide,Fade,Scale}.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-overlay && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
