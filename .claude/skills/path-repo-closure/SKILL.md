---
name: path-repo-closure
description: Propagates a new sugarcraft/<dep> dependency across every consuming composer.json — adds the require entry plus a {type:path, url:"../<dep>", options:{symlink:true}} repository for the FULL transitive closure (mirrors sugar-charts/composer.json), then verifies with tools/check-path-repos.php. Use when the user says 'add dep on <slug>', 'wire up <slug>', 'new transitive dep', or edits a require["sugarcraft/..."] line. Do NOT use for non-sugarcraft Packagist deps (those need only a require bump, no path-repo) or for scaffolding a whole new library (use scaffold-library / add-library-checklist).
paths:
  - '**/composer.json'
  - tools/check-path-repos.php
---
# Path-repo closure

When a SugarCraft lib gains a `sugarcraft/<dep>` require, Composer resolves the sibling through a local **path repository** (symlinked from `../<dep>`), not Packagist. Pre-1.0 libs are unpublished, so a missing path-repo makes a fresh `composer install` fall back to a non-existent VCS remote and fail. The path-repo set must cover the **full transitive closure**, not just the direct require.

## Critical

- A sibling require has TWO halves that must BOTH be present in the consuming `composer.json`:
  1. `require["sugarcraft/<dep>"]` pinned to a dev constraint (`"@dev"` or `"dev-master"` — both are recognized; existing libs like `sugar-charts` use `"dev-master"`).
  2. A `repositories[]` entry: `{ "type": "path", "url": "../<dep>", "options": { "symlink": true } }`.
- The closure is **transitive**. If you add `sugarcraft/sugar-bits` and `sugar-bits` itself requires `sugarcraft/candy-forms`, then the lib you edited ALSO needs path-repos for `candy-forms` (and anything `candy-forms` pulls in). Walk the chain until no new siblings appear.
- This applies ONLY to `sugarcraft/*` deps. Real Packagist packages (`react/event-loop`, `phpunit/phpunit`, etc.) get a plain `require` bump and NO path-repo.
- The canonical shape to mirror is `sugar-charts/composer.json` (`require` + `repositories[]`).
- NEVER run `composer update`/`composer require` to add a sibling — it rewrites the lockfile against Packagist and breaks the symlink setup. Edit `composer.json` by hand, then let `tools/check-path-repos.php --fix` complete the closure.

## Instructions

1. **Identify the consuming lib and the new dep.** From the user request ("add dep on <slug>" / "wire up <slug>"), determine the lib being edited (`<consumer>/composer.json`) and the new sibling slug `<dep>`. Verify `<dep>` is a real sibling: `ls -d ../<dep>` from the consumer dir, or confirm `<dep>/composer.json` exists at repo root. If `<dep>` is NOT a `sugarcraft/*` sibling, STOP — this skill does not apply; just bump `require`.

2. **Add the direct require.** In `<consumer>/composer.json`, add to the `require` block (after `"php": "^8.3"`, alphabetical-ish, matching neighbors):
   ```json
   "sugarcraft/<dep>": "dev-master"
   ```
   Match the constraint form already used by sibling requires in that file (most use `"dev-master"`). Verify the `require` block still parses as valid JSON before proceeding.

3. **Add the direct path-repo.** In the same file's `repositories[]` array, append:
   ```json
   { "type": "path", "url": "../<dep>", "options": { "symlink": true } }
   ```
   If `repositories` does not exist yet, create it as a top-level array. Verify the array is well-formed JSON.

4. **Resolve the transitive closure (do not hand-walk if avoidable).** Run the checker in fix mode from the repo root — it reads `<dep>/composer.json`, follows each sibling's own `require`, and inserts every missing path-repo into every affected lib:
   ```sh
   php tools/check-path-repos.php --fix
   ```
   Expected on success: `check-path-repos: all N issues fixed` (or `closure clean` if nothing was missing). This step depends on Steps 2–3 already being saved to disk.

5. **Verify closure is clean (read-only re-run).** Run without `--fix`:
   ```sh
   php tools/check-path-repos.php
   ```
   Must print `check-path-repos: closure clean` and exit 0. If it lists `missing path-repo for X (required transitively via A -> B -> X)`, the `--fix` pass missed something — re-run Step 4, then re-verify. Do not proceed until this is clean.

6. **If the dep crosses into a NEW sibling not previously used anywhere, wire the root manifest too.** Add the `require` + `repositories[]` entry to the root `/composer.json` as well (per `.claude/rules/composer-conventions.md`). The checker only covers per-lib manifests, not the root.

7. **Validate the manifest and run tests.** From the consumer dir:
   ```sh
   composer validate            # drop --strict: @dev/dev-master always warns, EXPECTED
   composer install --quiet && vendor/bin/phpunit
   ```
   `composer install` must create the `vendor/sugarcraft/<dep>` symlink without hitting the network. A green phpunit run confirms the symlinked source resolves. If install was already done, `composer update sugarcraft/<dep> --no-scripts` refreshes just the symlink.

## Examples

**User says:** "wire up a dependency on sugar-dash in sugar-charts"

**Actions taken:**
1. Confirm `../sugar-dash/composer.json` exists.
2. Add `"sugarcraft/sugar-dash": "dev-master"` to `sugar-charts/composer.json` `require`.
3. Append `{ "type": "path", "url": "../sugar-dash", "options": { "symlink": true } }` to its `repositories[]`.
4. Read `sugar-dash/composer.json` → it requires `candy-core` + `candy-sprinkles`. Run `php tools/check-path-repos.php --fix` → inserts path-repos for `candy-core` and `candy-sprinkles` into `sugar-charts` (the transitive closure).
5. `php tools/check-path-repos.php` → `closure clean`.
6. `cd sugar-charts && composer validate && composer install --quiet && vendor/bin/phpunit` → green.

**Result:** `sugar-charts/composer.json` ends with three path-repos (`../sugar-dash`, `../candy-core`, `../candy-sprinkles`) — exactly the shape that already lives in that file.

## Common Issues

- **`Could not find package sugarcraft/<dep>` / falls back to https://repo.packagist.org during `composer install`:** the path-repo entry is missing or its `url` is wrong. Confirm `repositories[]` has `"url": "../<dep>"` (relative, `../` prefix, no trailing slash) and `"type": "path"`. Re-run `php tools/check-path-repos.php --fix`.
- **`missing path-repo for X (required transitively via A -> B -> X)`:** a deeper sibling in the chain has no path-repo in this lib. This is the whole point of the closure — run `php tools/check-path-repos.php --fix`, then re-verify. Never resolve it by deleting the require.
- **`composer validate` warns `sugarcraft/<dep> is invalid` / `unbound version constraint`:** EXPECTED for `@dev`/`dev-master` pre-1.0. Drop `--strict`; the bare `composer validate` warning is non-fatal and intentional.
- **Symlink points at a stale copy / tests pick up old source:** delete `vendor/sugarcraft/<dep>` and re-run `composer install`. With `"symlink": true` the vendor entry is a live link to `../<dep>/src`, so edits there reflect immediately — a real directory copy instead of a symlink means the path-repo lost its `options`.
- **Checker exits 2 `cannot resolve monorepo root`:** you ran it from outside the repo. Run from `/home/sites/sugarcraft` (repo root) or set `SUGARCRAFT_CHECK_PATH_REPOS_ROOT`.
- **Cycle warnings (e.g. candy-core ⇄ candy-pty):** handled by the checker's visited-set BFS — a self/back edge never needs a path-repo to itself, so this is not an error.
