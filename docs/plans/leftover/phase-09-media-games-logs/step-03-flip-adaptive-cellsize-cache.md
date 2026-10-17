# Step 09.03 — sugar-animation adaptive cell size (via sugar-pty SizeIoctl) + frame cache

**Source:** `leftover_updates_later.md` sugar-animation P7 + P12
**Branch:** `ai/flip-adaptive-cache`

## Deliverable

- Adaptive cell size from current terminal size — call
  `\SugarCraft\Pty\SizeIoctl::query(STDOUT)` to pick output dimensions.
  **Do not shell out to `tput`.**
- Frame cache — memoize decoded frames so repeat renders skip the
  decode step.

## Files

**Modify:**

- `sugar-animation/src/Renderer.php` — `withAdaptiveSize()` reads tty.
- `sugar-animation/src/Cache/FrameCache.php` (new).
- `sugar-animation/composer.json` — add `sugarcraft/sugar-pty` if not
  transitive.

## Tests

- `sugar-animation/tests/AdaptiveSizeTest.php`.
- `sugar-animation/tests/FrameCacheTest.php`.

## Acceptance

- `cd sugar-animation && vendor/bin/phpunit --filter "Adaptive|FrameCache"` green.
- `grep -n "shell_exec.*tput\|tput cols" sugar-animation/src` returns nothing.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
