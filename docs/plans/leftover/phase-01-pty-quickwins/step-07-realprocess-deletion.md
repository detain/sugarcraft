# Step 01.07 — RealProcess deletion + candy-shell Process interface alignment

**Source:** `leftover_updates.md` P3-LO-01 + CC-LO-08 (bundle)
**Branch:** `ai/realprocess-cleanup`
**Bundle hint:** these two go together — same lib, same surface

## Deliverable

1. Determine whether `candy-shell/src/Process/RealProcess.php` has any
   external callers. If zero, delete it; if any, keep the alias.
2. Align `candy-shell/src/Process/Process.php` interface with
   `candy-pty/src/Contract/Process.php`. They should not be parallel
   contracts — `candy-shell` re-exports / `use`s the candy-pty
   contract.

## Pre-step grep

```bash
grep -rn "Process\\\\RealProcess\|use SugarCraft\\\\Shell\\\\Process\\\\RealProcess" /home/sites/sugarcraft
```

If zero hits outside `candy-shell/` itself → safe to delete.

## Files

**Modify:**
- `candy-shell/src/Process/Process.php` — either:
  - Delete and `use SugarCraft\\Pty\\Contract\\Process` at every call
    site (preferred), OR
  - Convert to `interface Process extends \SugarCraft\Pty\Contract\Process {}`
    if signatures diverged.

**Delete (if grep clean):**
- `candy-shell/src/Process/RealProcess.php` and update its callers to
  `use SugarCraft\\Pty\\Posix\\PosixProcess` directly.

**Modify (if grep dirty):**
- Keep `RealProcess.php` as `final class RealProcess extends PosixProcess {}`
  with `@deprecated`. Document in `candy-shell/CALIBER_LEARNINGS.md`.

- `candy-shell/composer.json` — confirm `sugarcraft/candy-pty` is in
  `require` (likely transitive via candy-core; verify with
  `tools/check-path-repos.php`).

## Acceptance

- `cd candy-shell && vendor/bin/phpunit` green.
- `php tools/check-path-repos.php` green.
- `grep -rn "class Process\b\|interface Process\b" candy-shell/src` returns
  at most one file, and it implements/extends candy-pty's contract.

## Notes

- If you go the "delete RealProcess" route, the alias removal is
  irreversible without rebasing — confirm grep is truly clean across
  the monorepo (including `examples/`, `tests/`, and sister libs)
  before the merge.
- The CALIBER entry for this should mention "Process interface
  consolidated under candy-pty/Contract" so future maintainers don't
  re-add a parallel contract.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
