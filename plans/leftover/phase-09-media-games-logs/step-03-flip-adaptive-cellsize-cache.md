# Step 09.03 — candy-flip adaptive cell size (via candy-pty SizeIoctl) + frame cache

**Source:** `leftover_updates_later.md` candy-flip P7 + P12
**Branch:** `ai/flip-adaptive-cache`

## Deliverable

- Adaptive cell size from current terminal size — call
  `\SugarCraft\Pty\SizeIoctl::query(STDOUT)` to pick output dimensions.
  **Do not shell out to `tput`.**
- Frame cache — memoize decoded frames so repeat renders skip the
  decode step.

## Files

**Modify:**
- `candy-flip/src/Renderer.php` — `withAdaptiveSize()` reads tty.
- `candy-flip/src/Cache/FrameCache.php` (new).
- `candy-flip/composer.json` — add `sugarcraft/candy-pty` if not
  transitive.

## Tests

- `candy-flip/tests/AdaptiveSizeTest.php`.
- `candy-flip/tests/FrameCacheTest.php`.

## Acceptance

- `cd candy-flip && vendor/bin/phpunit --filter "Adaptive|FrameCache"` green.
- `grep -n "shell_exec.*tput\|tput cols" candy-flip/src` returns nothing.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
