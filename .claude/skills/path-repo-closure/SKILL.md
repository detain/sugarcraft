---
name: path-repo-closure
description: Propagates a new sugarcraft/<dep> dependency across every consuming composer.json — adds the require entry plus a {type:path, url:"../<dep>", options:{symlink:true}} repository for the FULL transitive closure (mirrors sugar-charts/composer.json), then verifies with tools/check-path-repos.php. Use when the user says 'add dep on <slug>', 'wire up <slug>', 'new transitive dep', or edits a require["sugarcraft/..."] line. Do NOT use for non-sugarcraft Packagist deps (those need only a require bump, no path-repo) or for scaffolding a whole new library (use scaffold-library / add-library-checklist).
paths:
  - */composer.json
  - tools/check-path-repos.php
---
# Path-repo closure

Wiring a new `sugarcraft/<dep>` require into a lib means updating BOTH the `require` block AND `repositories[]` — for the dep **and its full transitive `sugarcraft/*` closure**. A missing transitive path-repo makes a fresh `composer install` fall back to the (unpublished) VCS remote and fail. Every sibling is a local path-repo; there is no version solving, just name collection.

## Critical

- **Two edits per new dep, never one.** A `require` line WITHOUT a matching `repositories[]` path entry is broken. Reference shape: `sugar-charts/composer.json` (require block lines 35-41, repositories lines 42-106).
- **Closure, not just the direct dep.** If you add `sugarcraft/sugar-dash`, you must also add path-repos for everything `sugar-dash` transitively pulls in (`candy-core`, `candy-buffer`, `candy-sprinkles`, `candy-layout`, `candy-ansi`, `candy-input`, `candy-pty`, …). Let `tools/check-path-repos.php --fix` compute and insert these — do not hand-enumerate.
- **Only sugarcraft/* deps get path-repos.** A plain Packagist dep (`react/event-loop`, `phpstan/phpstan`) is a `require`/`require-dev` bump ONLY — no `repositories[]` entry. If the user is adding one of those, this skill does not apply.
- **Constraint form:** use `"dev-master"` (most common in this repo — e.g. `candy-buffer`, `candy-kit`) or `"@dev"` (e.g. `sugar-dash`, `candy-testing`). Both are recognized as dev-pinned and both require the path-repo. Match the sibling deps already in the same file.
- **`composer validate --strict` flags every `"@dev"`** — EXPECTED. Drop `--strict`.

## Instructions

1. **Identify the consumer(s) and the new dep slug.** The consumer is the lib whose `<slug>/src/` now references the dep's classes (namespace `SugarCraft\<Sub>\`). If the dep is a NEW transitive dep introduced deep in the graph (e.g. `candy-forms` newly required by `sugar-bits`), the consumers are ALL libs that transitively require `sugar-bits`, not just `sugar-bits`. Verify: `grep -rl '"sugarcraft/<consumer>"' */composer.json` lists every lib that will need the closure update. Confirm the dep dir exists (`ls <dep>/composer.json`) before proceeding.

2. **Add the `require` entry to the direct consumer's `<slug>/composer.json`.** Insert into the `"require": { ... }` block, keeping keys grouped with the other `sugarcraft/*` lines (they are conventionally listed after `"php": "^8.3"`). Use the same constraint form the sibling deps in that file already use (`"dev-master"` or `"@dev"`):
   ```json
   "require": {
       "php": "^8.3",
       "sugarcraft/candy-core": "dev-master",
       "sugarcraft/<dep>": "dev-master"
   },
   ```
   If the dep is a test-only harness (`candy-testing`), put it in `require-dev` instead — it still needs a path-repo. Verify the JSON still parses: `php -r 'json_decode(file_get_contents("<slug>/composer.json"),true,512,JSON_THROW_ON_ERROR);'` (uses the file from this step).

3. **Run the closure fixer to insert ALL missing path-repos.** From the monorepo root:
   ```sh
   php tools/check-path-repos.php --fix
   ```
   This walks the full transitive `sugarcraft/*` require graph for every lib and appends any missing `{ "type": "path", "url": "../<dep>", "options": { "symlink": true } }` entry to each affected `repositories[]`. It re-encodes with `JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES`. It prints `check-path-repos: all N issues fixed` on success. This step depends on Step 2's require edit — the fixer only inserts repos for deps that are actually required.

4. **Verify closure is clean (read-only pass).** Re-run WITHOUT `--fix`:
   ```sh
   php tools/check-path-repos.php
   ```
   Must print `check-path-repos: closure clean` and exit 0. Any `missing path-repo for <x> (required transitively via <path>)` line means the graph is still broken — re-run Step 3. Do not proceed until this is clean.

5. **Install and test the affected lib(s) to prove the symlinks resolve.** `composer.lock`/`vendor/` go stale per-lib, so update before trusting a failure:
   ```sh
   cd <slug> && composer update --quiet && vendor/bin/phpunit
   ```
   Green tests confirm the path-repo symlinks resolved the new dep. If a downstream consumer (from Step 1's grep) also gained transitive entries, repeat this install+test for each.

6. **Update the root `composer.json` only if you added a brand-new lib** (not for wiring an existing dep into an existing consumer). Existing-dep wiring touches only the consumer manifests. Skip this step otherwise.

## Examples

**User says:** "Wire up a dependency on sugar-dash in sugar-charts."

**Actions taken:**
1. Confirm `sugar-dash/composer.json` exists; grep shows `sugar-charts` is the only direct consumer to edit.
2. Add `"sugarcraft/sugar-dash": "dev-master"` to `sugar-charts/composer.json` `require`.
3. `php tools/check-path-repos.php --fix` → inserts path-repos for `sugar-dash` AND its transitive closure (`candy-layout`, `candy-input`, `candy-pty`, …) that weren't already present — bringing `sugar-charts/composer.json` to its current 9 `"type": "path"` entries.
4. `php tools/check-path-repos.php` → `closure clean`.
5. `cd sugar-charts && composer update --quiet && vendor/bin/phpunit` → green.

**Result:** `sugar-charts/composer.json` has the `require` line plus a matching `{type:path,url:"../sugar-dash",options:{symlink:true}}` repo and every transitive path-repo, identical in shape to the canonical file. Fresh `composer install` resolves all symlinks offline.

## Common Issues

- **`missing path-repo for <dep> (required transitively via A -> B -> <dep>)`** — a transitive sibling has no `repositories[]` entry in the reported lib. Fix: `php tools/check-path-repos.php --fix`, then re-verify. Never hand-add just the one named — the fixer inserts the whole missing set.
- **`composer install` fails with `Could not find a matching version of package sugarcraft/<dep>`** — the dep is unpublished on Packagist and has no local path-repo. It means Step 3 was skipped or the require was added to a lib whose `repositories[]` the fixer didn't reach. Run `php tools/check-path-repos.php` to see which lib is missing the entry, then `--fix`.
- **Local `vendor/bin/phpunit` fails but CI is green** — stale per-lib `composer.lock`/`vendor/` (both gitignored). Run `composer update` in that lib before trusting the failure.
- **`check-path-repos: closure clean` but a Packagist dep still 'missing'** — default mode treats Packagist-published deps as resolvable and skips them. To demand a local path-repo for the FULL closure regardless (pre-1.0 ideal), add `--strict-closure`. Use `--no-network` to skip the Packagist HEAD probes offline.
- **`--unused` reports `PRUNE_REQUIRE_*` for a dep you just added** — that means `<slug>/src/` doesn't reference the dep's PSR-4 namespace yet. Either the dep isn't actually used (drop the require) or it's referenced only by a string class-name/composer script (a false positive — confirm by hand). `--unused` is read-only and never auto-prunes.
- **`composer validate --strict` errors on every `"@dev"`** — expected, not a real problem. Run `composer validate` without `--strict`.
