# Step 09.18 — honey-bounce SpringChain + reduced-motion

**Source:** `leftover_updates_later.md` honey-bounce §4.3 P2 + §1.2
**Branch:** `ai/bounce-springchain-reducedmotion`

## Deliverable

- `SpringChain` — sequence multiple springs in order; one's settle
  triggers the next.
- Reduced-motion support — consume
  `\SugarCraft\Palette\Probe::reducedMotion()` from step 02.03; if
  enabled, skip all animation (snap to final value instantly).

## Files

**Create:**
- `honey-bounce/src/SpringChain.php`.

**Modify:**
- `honey-bounce/src/Spring.php` — respect Probe::reducedMotion().
- `honey-bounce/composer.json` — add `sugarcraft/candy-palette`.

## Tests

- `honey-bounce/tests/SpringChainTest.php`.
- `honey-bounce/tests/ReducedMotionTest.php`.

## Acceptance

- `cd honey-bounce && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
