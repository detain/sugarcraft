---
name: path-repo-closure
description: Wires a new sugarcraft/<dep> dependency into a consuming lib — a `require` bump ONLY, with NO repositories[] entry in the committed manifest, because each lib is published standalone and a sibling path-repo is a hard fatal for anyone who clones the split repo. CI re-injects the path-repos before every composer install. Verifies with tools/check-path-repos.php --no-lib-path-repos. Use when the user says 'add dep on <slug>', 'wire up <slug>', 'new transitive dep', or edits a require["sugarcraft/..."] line. Do NOT use for non-sugarcraft Packagist deps (a plain require bump, nothing else) or for scaffolding a whole new library (use scaffold-library / add-library-checklist).
paths:
  - "*/composer.json"
  - tools/check-path-repos.php
---
# Path-repo policy

The policy is **inverted between the committed tree and the build**, and getting the direction wrong breaks real users:

| where | sibling path-repos | why |
|---|---|---|
| `<lib>/composer.json` in git | **absent** | each lib is published standalone as `sugarcraft/<lib>`; `../candy-buffer` does not exist in a split-repo clone |
| CI / local build | **injected** | so a PR's own sibling code is what gets tested, not whatever is on Packagist |
| root `composer.json` in git | **present, complete** | the monorepo root IS the directory those `../` urls resolve against |

So wiring a new `sugarcraft/<dep>` is **one edit**: the `require` line. Do not add a `repositories[]` entry to a lib manifest, and do not commit one that a `--fix` run left behind.

## Critical

- **One edit per new dep, not two.** A `require` line is complete on its own — the dep resolves from Packagist, where all 58 libs are published (verified: every `sugarcraft/*` name returns 200 from `repo.packagist.org/p2/<name>.json`).
- **Never commit a sibling path-repo into `<lib>/composer.json`.** `Composer\Repository\PathRepository` **hard fails** — not warns — on a url that does not exist: `The `url` supplied for the path (../candy-buffer) repository does not exist`, exit 1. In a clone of `sugarcraft/candy-files` the sibling directory is absent, so the entry breaks `composer install` outright.
- **It is invisible to consumers, which is why this went unnoticed for so long.** Composer honours `repositories` **only on the root package** and ignores a dependency's entirely (measured both directions). Anyone who merely `require`s the package was never affected; only cloners were.
- **`--fix` is now a BUILD step, not an authoring step.** CI runs `php tools/check-path-repos.php --fix --strict-closure` before every `composer install` (9 sites across `ci.yml`, `pty-matrix.yml`, `vhs.yml`). If you run it locally, treat the result as scratch and do not commit it.
- **Only `sugarcraft/*` deps are in scope at all.** A plain Packagist dep (`react/event-loop`, `phpstan/phpstan`) is a `require`/`require-dev` bump — this skill adds nothing.
- **Constraint form:** `"dev-master"` (most common) or `"@dev"`. Match the sibling deps already in the same file.
- **Never commit a per-lib `composer.lock`.** `/*/composer.lock` is gitignored. A committed lock makes `composer install` resolve **from the lock and ignore the injected path-repos** — it warns and exits 0, so the injection silently does nothing. The root keeps its own lock.
- **`composer validate --strict` flags every `"@dev"`** — EXPECTED. Drop `--strict`.

## Instructions

1. **Identify the consumer and the dep slug.** The consumer is the lib whose `<slug>/src/` references the dep's `SugarCraft\<Sub>\` classes. Confirm the dep exists: `ls <dep>/composer.json`. Unlike the old closure regime, you do **not** need to touch every downstream lib — transitive resolution is Packagist's job now.

2. **Add the `require` entry to the direct consumer only.** Keep it grouped with the other `sugarcraft/*` lines (conventionally after `"php": "^8.3"`):
   ```json
   "require": {
       "php": "^8.3",
       "sugarcraft/candy-core": "dev-master",
       "sugarcraft/<dep>": "dev-master"
   },
   ```
   A test-only harness (`candy-testing`) goes in `require-dev`. Verify the JSON still parses:
   ```sh
   php -r 'json_decode(file_get_contents("<slug>/composer.json"),true,512,JSON_THROW_ON_ERROR);'
   ```

3. **Resolve it.** From the lib:
   ```sh
   cd <slug> && composer update sugarcraft/<dep> && vendor/bin/phpunit
   ```
   This pulls the dep from Packagist. If you want to test against your **local** sibling instead of the published one, inject first and revert after:
   ```sh
   php tools/check-path-repos.php --fix --strict-closure   # scratch, do not commit
   cd <slug> && composer update sugarcraft/<dep> && vendor/bin/phpunit
   cd .. && git checkout -- '*/composer.json'
   ```

4. **Verify the committed tree carries no sibling path-repos.** This is the check CI runs:
   ```sh
   php tools/check-path-repos.php --no-lib-path-repos
   ```
   Must print `no sibling path-repos in per-lib manifests` and exit 0. If it names your lib, a `--fix` run leaked into the commit — remove those entries.

5. **Update the root `composer.json` only for a brand-new lib** — `require` entry plus a `{type:path,url:"<slug>",options:{symlink:true}}` repo, since the root keeps its full closure. Verify with `php tools/check-path-repos.php` (`closure clean`). Skip for wiring an existing dep.

## Examples

**User says:** "Wire up a dependency on candy-forms in sugar-crush."

**Actions taken:**
1. Confirm `candy-forms/composer.json` exists.
2. Add `"sugarcraft/candy-forms": "dev-master"` to `sugar-crush/composer.json` `require`. **No `repositories[]` edit.**
3. `cd sugar-crush && composer update sugarcraft/candy-forms` → resolves from Packagist, pulling `candy-async` with it.
4. `vendor/bin/phpunit` → green.
5. `php tools/check-path-repos.php --no-lib-path-repos` → clean.

**Result:** one added line. A fresh clone of `sugarcraft/sugar-crush` installs successfully, and CI still tests against the monorepo's own `candy-forms` because it injects the path-repos before installing.

## Common Issues

- **`composer install` dies with ``The `url` supplied for the path (../<dep>) repository does not exist``** — a sibling path-repo was committed into a lib manifest, or you are running in a split-repo clone. Remove the entry; `--no-lib-path-repos` finds them all.
- **CI green but the sibling change was never exercised** — the lib has a committed `composer.lock`, so `composer install` resolved from the lock and ignored the injection (it only *warns*: "The lock file is not up to date"). Delete the lock; `/*/composer.lock` is gitignored for exactly this reason.
- **`Could not find a matching version of package sugarcraft/<dep>`** — the dep is not published on Packagist (a freshly-scaffolded lib). Either publish it or add a path-repo to the **root** manifest and consume it from there until it is.
- **Local `vendor/bin/phpunit` fails but CI is green** — stale per-lib `vendor/`. Run `composer update` in that lib before trusting the failure.
- **`--strict-closure` reports gaps on the committed tree** — that is now the *expected* state, not drift. `--strict-closure` describes the post-injection ideal; the committed-tree check is `--no-lib-path-repos`.
- **`--unused` reports `PRUNE_REQUIRE_*` for a dep you just added** — `<slug>/src/` does not reference the dep's PSR-4 namespace yet. Either it is genuinely unused, or it is referenced only by a string class-name (a false positive). `--unused` is read-only.
- **`composer validate --strict` errors on every `"@dev"`** — expected. Drop `--strict`.
