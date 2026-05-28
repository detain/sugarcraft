# Step 01.02 — Drop consumer composer.lock files

**Source:** `leftover_updates.md` CC-LO-02
**Branch:** `ai/drop-consumer-locks`
**Bundle hint:** standalone, touches many composer.lock files

## Deliverable

Delete `composer.lock` from every `sugarcraft/*` sibling lib **except
the root**. Reasoning: these libs are path-repo siblings, all pinned
`"sugarcraft/*": "@dev"`, and consumer locks add the well-known
"7-cell red on every candy-pty PR" pattern documented in
`plans/sugarcraft-pty-status.md`.

The root `composer.json` plus `scripts/affected-libs.php` matrix
already gives per-PR reproducibility.

## Files

**Delete:** every `composer.lock` under `<lib>/` for every lib in the
monorepo except `/home/sites/sugarcraft/composer.lock` (root keeps its
own).

Add to each lib's `.gitignore` (if missing): `composer.lock`.

## Acceptance

- `find . -maxdepth 2 -name composer.lock -not -path './composer.lock'`
  returns nothing.
- `cd <each-lib> && composer install --quiet && vendor/bin/phpunit`
  green for: candy-pty, candy-core, candy-shell, candy-wish, candy-vcr,
  sugar-dash, sugar-bits, sugar-charts, candy-sprinkles, candy-vt,
  candy-mosaic.
- `php tools/check-path-repos.php` still green.
- `git grep -l "composer.lock" .github/workflows/` — confirm any cache
  keys that key on `composer.lock` hashes are switched to keying on
  `composer.json` hashes instead.

## Notes

- The root keeps its lock. Only consumer/sibling libs lose theirs.
- This step will probably break CI cache keys; update them in the
  same PR.
- The "7-cell red" failure pattern on candy-pty PRs should disappear
  after this lands. Verify on the next candy-pty PR.
- If a CI workflow assumes a lock file exists for any consumer lib,
  rewrite it to run `composer install` against `composer.json`.

---

## Process reminders

- `unset GITHUB_TOKEN` before every `gh` invocation. Always.
- End on `master` with clean working tree (commit → push → `gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`). See `_templates/process_reminders.md`.
