# Step 09.18 — sugar-spring SpringChain + reduced-motion

**Source:** `leftover_updates_later.md` sugar-spring §4.3 P2 + §1.2
**Branch:** `ai/bounce-springchain-reducedmotion`

## Deliverable

- `SpringChain` — sequence multiple springs in order; one's settle
  triggers the next.
- Reduced-motion support — consume
  `\SugarCraft\ColorProfile\Probe::reducedMotion()` from step 02.03; if
  enabled, skip all animation (snap to final value instantly).

## Files

**Create:**

- `sugar-spring/src/SpringChain.php`.

**Modify:**

- `sugar-spring/src/Spring.php` — respect Probe::reducedMotion().
- `sugar-spring/composer.json` — add `sugarcraft/sugar-colorprofile`.

## Tests

- `sugar-spring/tests/SpringChainTest.php`.
- `sugar-spring/tests/ReducedMotionTest.php`.

## Acceptance

- `cd sugar-spring && vendor/bin/phpunit` green.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
