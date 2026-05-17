---
name: path-repo-closure
description: Walks every consuming lib's composer.json to insert a `{type: path, url: "../<dep>", options: {symlink: true}}` entry plus the matching `require["sugarcraft/<dep>"]: "@dev"` whenever a new transitive sugarcraft/* dependency is added. Mirrors the closure shape in sugar-charts/composer.json. Use when user says 'add dep on <slug>', 'wire up <slug>', 'new transitive dep', or edits a `require["sugarcraft/..."]` line. Do NOT use for non-sugarcraft Packagist deps — those need only a `require` bump, no path-repo.
paths:
  - '**/composer.json'
---
# path-repo-closure

Add a `sugarcraft/<dep>` dependency to a SugarCraft lib AND propagate the full transitive closure so every consuming `composer.json` can resolve it via local symlinks.

## Critical

- **Every transitive `sugarcraft/*` dep needs BOTH a `require` entry AND a `repositories[]` path entry — in EVERY lib that pulls it in (directly OR transitively).** Skipping the closure is the #1 cause of `Your requirements could not be resolved to an installable set of packages` on a fresh clone.
- **Drop `--strict`** when validating: `composer validate --strict` flags every `"sugarcraft/*": "@dev"` — EXPECTED pre-1.0. Use plain `composer validate`.
- **Path repos use `../<dep>` (parent-relative), NEVER absolute paths.** Repo lives at `<slug>/` siblings; resolve as `url: "../<dep>"`.
- **`options.symlink: true`** is mandatory — without it Composer copies sources and changes to `<dep>/src/` won't appear in consumers.
- Reference closure shape: `sugar-charts/composer.json` (largest transitive set in the repo — copy from there).
- `minimum-stability: "dev"` MUST already be set on the consuming lib's `composer.json`. If missing, add it before the closure work or `@dev` constraints will not resolve.
- The root `/composer.json` ALSO needs the same `require` + `repositories[]` pair if the new dep is reachable from the root require graph.

## Instructions

### Step 1 — Identify the dep being added and the direct consumer

From the user request, extract:
- `DEP` — the new dep slug (kebab-case dir name, e.g. `candy-palette`).
- `CONSUMER` — the lib whose `composer.json` the user is editing (e.g. `sugar-charts`).

Verify both exist:

```sh
cd /home/sites/sugarcraft && ls -d <DEP> <CONSUMER>
```

Verify before proceeding: both directories print, each contains a `composer.json`. If `<DEP>` does not exist yet, stop — scaffold the new lib first (see `AGENTS.md` "Adding a lib" checklist).

### Step 2 — Compute `<DEP>`'s own transitive closure

Read `<DEP>/composer.json` and collect every key under `require` that starts with `"sugarcraft/"`. Recursively expand: for each, read its `composer.json` and union its sugarcraft requires. The final set `CLOSURE = {<DEP>} ∪ recursive sugarcraft requires of <DEP>`.

```sh
cd /home/sites/sugarcraft && grep -h '"sugarcraft/' <DEP>/composer.json
```

Verify before proceeding: you have a complete list. Cross-check against `sugar-charts/composer.json` `repositories[]` — if `<DEP>` is one of the libs sugar-charts already pulls in, the closure should be a subset of sugar-charts's repositories list.

### Step 3 — Update `<CONSUMER>/composer.json`

For every slug `S` in `CLOSURE`:

1. Add to `require`:

   ```json
   "sugarcraft/<S>": "@dev"
   ```

   Keep entries alphabetized within the `require` block to match `sugar-charts` style.

2. Add to `repositories` (which is a JSON array, not an object):

   ```json
   {
     "type": "path",
     "url": "../<S>",
     "options": {
       "symlink": true
     }
   }
   ```

   Append to the existing array; skip if an entry with the same `url` is already present.

Verify before proceeding:

```sh
cd /home/sites/sugarcraft/<CONSUMER> && composer validate
```

Must print `./composer.json is valid` (warnings about `@dev` constraints are EXPECTED — see Critical). If it fails with `does not match the expected JSON schema`, re-check trailing commas and that `repositories` is an array `[...]` not an object `{...}`.

### Step 4 — Propagate the closure to every other lib that requires `<CONSUMER>`

Find reverse dependents:

```sh
cd /home/sites/sugarcraft && grep -l '"sugarcraft/<CONSUMER>"' */composer.json
```

For each match `M/composer.json` (excluding `<CONSUMER>` itself):
- Repeat Step 3 against `M` using the same `CLOSURE` set, MINUS any entries already present in `M`'s repositories list.
- Each `M` must transitively be able to resolve every member of `CLOSURE`, so the path-repo block must be a superset of its current state.

Also update the root `/composer.json` if it requires `<CONSUMER>` (it usually does — root pulls in every lib for the dev metapackage).

Verify before proceeding: re-run `composer validate` in every touched lib AND in `/`:

```sh
cd /home/sites/sugarcraft && for d in <CONSUMER> <M1> <M2> .; do (cd "$d" && composer validate) || exit 1; done
```

### Step 5 — Refresh the lockfiles and run the test loop

For every touched lib, blow away `vendor/` and `composer.lock` and reinstall so the new symlinks are wired in:

```sh
cd /home/sites/sugarcraft/<CONSUMER> && rm -rf vendor composer.lock && composer install --quiet && vendor/bin/phpunit
```

Verify before proceeding: `phpunit` exits 0 in every touched lib. If `Class "SugarCraft\<Sub>\X" not found`, the symlink is not in place — re-check that `repositories[]` contains the right `url` AND that `composer install` (not `composer update`) was run.

### Step 6 — Commit per ship-as-you-go cadence

Stage every touched `composer.json` (NOT `composer.lock` — those are gitignored at the lib level in this monorepo; check the lib's `.gitignore` first). Title commit: `<consumer>: wire transitive <dep> closure` or bundle with the feature commit that introduced the dep.

## Examples

### Example 1 — Adding `candy-palette` as a direct dep of `sugar-charts`

User says: *"sugar-charts needs candy-palette for its color ramp"*

Actions:
1. Confirmed `candy-palette/` exists and has its own `composer.json`.
2. `candy-palette` itself requires `sugarcraft/candy-core` and `sugarcraft/candy-sprinkles` — so `CLOSURE = {candy-palette, candy-core, candy-sprinkles}`.
3. `sugar-charts/composer.json` already has `candy-core` and `candy-sprinkles` in `require` + `repositories[]`; appended only the `candy-palette` pair.
4. Reverse-deps of `sugar-charts`: `sugar-dash` and root `/composer.json`. Both already had `candy-core` and `candy-sprinkles`; appended `candy-palette` pair to each.
5. `composer validate` clean in all three touched libs. `composer install && vendor/bin/phpunit` green.

Result: 3 `composer.json` files touched (`sugar-charts`, `sugar-dash`, `/`), each gaining one `require` line and one `repositories[]` entry.

### Example 2 — Adding `candy-vt` to `candy-vcr` (deep closure)

User says: *"wire up candy-vt for vcr's replay rendering"*

Actions:
1. `candy-vt` requires `candy-core` and `candy-pty`; `candy-pty` requires `candy-core`. `CLOSURE = {candy-vt, candy-pty, candy-core}`.
2. `candy-vcr/composer.json` already had `candy-core`; appended `candy-vt` and `candy-pty` pairs.
3. Reverse-deps of `candy-vcr`: only `/composer.json`. Appended the same two pairs there.
4. `cd candy-vcr && rm -rf vendor composer.lock && composer install --quiet && vendor/bin/phpunit` → green.

Result: 2 files touched, closure complete, no missing-class errors.

## Common Issues

- **`Your requirements could not be resolved to an installable set of packages` mentioning `sugarcraft/<X>`** → `<X>` is missing from the consuming lib's `repositories[]`. Add the `{type: path, url: "../<X>", options: {symlink: true}}` block. Composer cannot resolve `@dev` without a path repo telling it where the source lives.
- **`Class "SugarCraft\<Sub>\Foo" not found` at test time despite `composer install` succeeding** → `options.symlink` is missing or `false`. Composer copied a snapshot into `vendor/` instead of symlinking; edits in `<dep>/src/` are invisible. Add `"options": {"symlink": true}` and re-run `rm -rf vendor composer.lock && composer install`.
- **`./composer.json does not match the expected JSON schema` after editing** → `repositories` was written as an object `{...}` instead of an array `[...]`, OR a trailing comma sneaked in after the last array element. Re-format the block — `repositories` is ALWAYS a JSON array of repo objects in this project.
- **`Root composer.json requires sugarcraft/<dep> @dev, found sugarcraft/<dep>[dev-master]` BUT INSTALL FAILS** → `minimum-stability` on the consuming lib is still `stable`. Set `"minimum-stability": "dev"` (and optionally `"prefer-stable": true`) at the top level of that lib's `composer.json`.
- **`composer validate --strict` complains about every `"sugarcraft/*": "@dev"`** → EXPECTED. Drop `--strict`. Pre-1.0 path-repo deps cannot use exact-version constraints because nothing is tagged on Packagist yet. Plain `composer validate` is the project's convention.
- **`gh pr create` warns about uncommitted changes after the closure edit** → Caliber's pre-commit refresh likely touched a `CALIBER_LEARNINGS.md`. Informational only — the PR still creates. Re-stage and amend if you want a clean tree, or proceed.
- **`composer install` works on your machine but CI fails** → you forgot to propagate the closure to a reverse-dependent lib OR to root `/composer.json`. Re-run Step 4's `grep -l '"sugarcraft/<CONSUMER>"' */composer.json` and double-check every match plus the root file were updated.