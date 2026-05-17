# Step 10.36 — sugar-veil backdrop dimming + animation system

**Source:** `leftover_updates_later.md` sugar-veil §4.1-2 High
**Branch:** `ai/veil-backdrop-animation`

## Deliverable

- Backdrop dimming effect (semi-transparent overlay).
- Animation system — slide / fade / scale (consume honey-bounce
  CubicBezier from step 09.17).

## Files

**Modify:** `sugar-veil/src/Veil.php` — `withBackdrop(int $opacity)`,
`withAnimation(AnimationKind)`.

**Create:** `sugar-veil/src/Animation/{Slide,Fade,Scale}.php`.

## Tests

- One per feature.

## Acceptance

- `cd sugar-veil && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
