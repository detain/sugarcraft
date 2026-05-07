---
name: scaffold-library
description: Scaffolds a new SugarCraft monorepo library following the canonical playbook in AGENTS.md. Creates <slug>/{composer.json,phpunit.xml,README.md,CALIBER_LEARNINGS.md,src/,tests/,examples/,lang/,.vhs/}, wires it into root composer.json (require + repositories), .github/workflows/{ci,vhs}.yml matrices, MATCHUPS.md, PROJECT_NAMES.md, CONVERSION.md, README.md table, docs/index.html + docs/lib/<slug>.html + docs/img/icons/<slug>.png. Use when user says 'add new library', 'port <upstream>', 'scaffold <slug>', 'new sugarcraft package', 'new lib'. Do NOT use for modifying existing libs, changing the umbrella metapackage's keywords, or adding a new locale to existing libs (use the i18n flow instead).
paths:
  - composer.json
  - */composer.json
  - */phpunit.xml
  - */src/**
  - */tests/**
  - */lang/**
  - */.vhs/**
  - .github/workflows/ci.yml
  - .github/workflows/vhs.yml
  - MATCHUPS.md
  - PROJECT_NAMES.md
  - CONVERSION.md
  - docs/index.html
  - docs/lib/*.html
---
# scaffold-library

## Critical

- **Slug rules**: kebab-case. Prefix MUST be one of `candy-` (foundation/system), `sugar-` (components/data/apps), or `honey-` (math/physics/motion). See `MATCHUPS.md` and `PROJECT_NAMES.md`.
- **PSR-4 namespace drops the prefix**: `candy-shine` → `SugarCraft\Shine\`. The single quirky exception is `candy-core` → `SugarCraft\Core\` (umbrella).
- **Every PHP file MUST start with `declare(strict_types=1);`**. Public classes are `final` unless extension is contractual.
- **CI matrices in `.github/workflows/ci.yml` AND `.github/workflows/vhs.yml` are hand-maintained, NOT glob-driven.** A new lib is invisible to CI until you add it to BOTH matrices.
- **Do NOT run `composer validate --strict`** — `@dev` sibling constraints are flagged by `--strict` but are EXPECTED in this monorepo. Use plain `composer validate`.
- **Do NOT add credit/upstream-acknowledgement sections** — out of scope for the pre-1.0 port (per `feedback_audit_skip_credit_upgrade`).
- **Commit author MUST be `Joe Huss <detain@interserver.net>`** — CI infra depends on this.
- Bundle the whole scaffold into ONE PR — do not split into many small PRs (per `feedback_pr_size`).

## Instructions

### Step 1 — Pick the name and confirm with the user

1. Read `MATCHUPS.md` and `PROJECT_NAMES.md` to confirm the slug isn't taken and the prefix matches role.
2. Decide:
   - Slug (kebab, e.g. `sugar-marbles`)
   - PSR-4 namespace component (e.g. `Marbles` → full namespace `SugarCraft\Marbles\`)
   - Upstream repo path (e.g. the upstream Go repo) and one-line role
3. **Verify** the slug doesn't already exist:

```sh
ls -d sugar-marbles 2>/dev/null && echo 'TAKEN — STOP' || echo 'free'
```

If TAKEN, this is a modify task, not a scaffold task.

### Step 2 — Create the package skeleton

Mirror the layout of an existing leaf lib like `sugar-bits/`. Every lib carries `composer.json`, a PHPUnit config XML, `README.md`, `CALIBER_LEARNINGS.md`, plus `src/`, `tests/`, `examples/`, `lang/`, `.vhs/` directories.

**`composer.json` template** — copy this, replacing slug, namespace, upstream, description, keywords:

```json
{
    "name": "sugarcraft/sugar-marbles",
    "description": "PHP port of upstream marbles — one-line role.",
    "type": "library",
    "license": "MIT",
    "keywords": ["tui", "terminal", "sugarcraft", "marbles"],
    "homepage": "https://github.com/sugarcraft/sugar-marbles",
    "authors": [
        { "name": "Joe Huss", "email": "detain@interserver.net", "role": "Maintainer" }
    ],
    "support": {
        "issues": "https://github.com/sugarcraft/sugar-marbles/issues",
        "source": "https://github.com/sugarcraft/sugar-marbles",
        "docs":   "https://sugarcraft.github.io/lib/sugar-marbles.html"
    },
    "require": {
        "php": "^8.1",
        "sugarcraft/candy-core": "@dev"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5"
    },
    "repositories": [
        { "type": "path", "url": "../candy-core", "options": { "symlink": true } }
    ],
    "autoload":     { "psr-4": { "SugarCraft\\Marbles\\": "src/" } },
    "autoload-dev": { "psr-4": { "SugarCraft\\Marbles\\Tests\\": "tests/" } },
    "minimum-stability": "dev",
    "prefer-stable": true
}
```

For each additional sibling lib your code requires, append BOTH a `require` entry AND a `repositories` entry. Pattern mirrored from `sugar-bits/composer.json`.

**PHPUnit config** — copy `sugar-bits/phpunit.xml` verbatim, change only the `<testsuite name="sugar-bits">` to match the new slug.

**Default English language file** — start with this header (every other locale file is a translation of this one; see `LOCALES.md` for codes):

```php
<?php

/**
 * English (default) translations.
 *
 * @return array<string, string>
 */

declare(strict_types=1);

return [
    // 'key.subkey' => 'message',
];
```

**VHS tape** — relative paths from the lib dir:

```
# Render with: vhs <lib-dir>/.vhs/<demo>.tape
Output .vhs/<demo>.gif

Set FontSize 14
Set Width 700
Set Height 240
Set TypingSpeed 60ms
Set Theme "TokyoNight"

Type "php examples/<demo>.php"
Enter
Sleep 8s
```

**`CALIBER_LEARNINGS.md`** — start with the canonical header (file is auto-managed):

```markdown
# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.
```

**Verify** before Step 3:

```sh
ls sugar-marbles/
cd sugar-marbles && composer validate    # NOT --strict
```

Must print `./composer.json is valid`.

### Step 3 — Wire into the root metapackage

Edit `/home/sites/sugarcraft/composer.json`:

1. Add to the `require` map: `"sugarcraft/sugar-marbles": "@dev"` (alphabetical-ish — match surrounding style).
2. Add to the `repositories` array: `{ "type": "path", "url": "sugar-marbles", "options": { "symlink": true } }`.

**Verify**:

```sh
cd /home/sites/sugarcraft && composer validate
```

### Step 4 — Add CI matrix entries (BOTH workflows)

The matrices are hand-maintained — without these, CI will not run the new lib.

1. `.github/workflows/ci.yml` — under `jobs.test.strategy.matrix.lib`, add `- sugar-marbles` to the list (preserve existing ordering pattern).
2. `.github/workflows/vhs.yml` — locate the matching matrix/lib list and add `sugar-marbles` there too.

**Verify**:

```sh
grep -c 'sugar-marbles' .github/workflows/ci.yml .github/workflows/vhs.yml
```

Must report ≥1 in each.

### Step 5 — Update central docs

Four edits, all required:

1. **`MATCHUPS.md`** — add a new row in the Libraries table with the upstream→port mapping. Status icon starts at 🔴 (scaffold only) and progresses 🔴 → 🟡 → 🟢 → 🚀.
2. **`PROJECT_NAMES.md`** — add a one-line entry plus rationale if the prefix/suffix choice isn't obvious.
3. **`CONVERSION.md`** — append a row to the Phase 9+ table: name | subdir | namespace | dependency chain.
4. **Root `README.md`** — add the new lib to the libraries table (match the existing column shape).

Skip `AUDIT_2026_05_06.md` unless the upstream is one we're already tracking gaps against.

### Step 6 — Wire the website

Two edits:

1. **`docs/index.html`** — add a new `<a class="lib-card" href="lib/sugar-marbles.html">` tile to the `#libraries` grid. Match an existing tile's structure exactly: `.lib-card-preview` (with demo `<img>` or `lib-card-preview--empty` placeholder), `.lib-icon-row`, title, `.lib-source`, `<p class="summary">`, `.links`.
2. **Per-lib detail page under `docs/`** — copy a sibling page (e.g. `docs/lib/candy-core.html`) and customise: title, meta description, og:* tags, hero header (icon path, title, sub-title, port-of chip), install snippet (`composer require sugarcraft/sugar-marbles`), Quickstart, "What's in the box" feature grid, Source & demos pulling each `.vhs/*.gif`. Drop a 256-square candy-themed PNG with transparent background under `media/` or `docs/img/icons/` (match where existing icons live). If the user hasn't supplied one, leave a placeholder and FLAG it explicitly in the PR body.

### Step 7 — Verify the whole stack

```sh
cd sugar-marbles && composer install && vendor/bin/phpunit
cd /home/sites/sugarcraft && composer validate
```

Both must succeed before committing. Then run the canonical monorepo loop from `CLAUDE.md` if any sibling lib was added to your `require`.

### Step 8 — Commit and PR

1. Branch: `ai/sugar-marbles-scaffold` (AI-driven) or `feat/sugar-marbles-scaffold` (human).
2. Bundle ALL of Steps 2–6 into ONE commit/PR — do not split.
3. PR title: `sugar-marbles: initial scaffold`.
4. PR body ends with a `## Test plan` checklist citing the test count: `sugar-marbles full suite green (N/N)`.
5. Author: `Joe Huss <detain@interserver.net>`.
6. Auto-merge is OFF; merge manually with `gh pr merge <num> --squash --delete-branch`.

## Examples

**User says**: "Port an upstream marbles library as `sugar-marbles`."

**Actions taken**:
1. Confirm `sugar-marbles` is unused in `MATCHUPS.md`, `PROJECT_NAMES.md`, and on disk.
2. Create the lib dir with `composer.json`, PHPUnit XML config, `README.md`, `CALIBER_LEARNINGS.md`, `src/Marbles.php`, `tests/MarblesTest.php`, `examples/marbles.php`, `lang/en.php`, `.vhs/marbles.tape` using the templates above. Namespace: `SugarCraft\Marbles\`.
3. Edit root `composer.json`: add `"sugarcraft/sugar-marbles": "@dev"` to `require` and the matching path repo to `repositories`.
4. Edit `.github/workflows/ci.yml` + `.github/workflows/vhs.yml`: add `- sugar-marbles` to each matrix lib list.
5. Add rows to `MATCHUPS.md`, `PROJECT_NAMES.md`, `CONVERSION.md`, root `README.md` table.
6. Add tile to `docs/index.html`, new per-lib detail page, plus placeholder PNG (FLAG that the icon needs an artist pass).
7. `cd sugar-marbles && composer install && vendor/bin/phpunit` — green (1/1).
8. Commit on `ai/sugar-marbles-scaffold`, open PR titled `sugar-marbles: initial scaffold`.

**Result**: Single PR adds the lib, wires CI, updates all four central docs, and lights up the website tile. Status row in `MATCHUPS.md` is 🔴 (scaffold) — bumps to 🟡 in the next PR when API parity work begins.

## Common Issues

**`composer validate` reports `"sugarcraft/candy-core: @dev" is dev-stable, but does not match minimum-stability`**

You ran `composer validate --strict`. Drop `--strict` — every sibling is `@dev` by design. Plain `composer validate` should print `./composer.json is valid`.

**`Could not find a matching version of package sugarcraft/sugar-marbles` when installing the root metapackage**

You added the package to the root `require` but forgot the matching `repositories` entry. Open `/home/sites/sugarcraft/composer.json` and confirm BOTH the `require` map AND the `repositories` array contain the new slug. Then `composer update sugarcraft/sugar-marbles` from the root.

**CI is green on master but the new lib never runs**

The matrices in `.github/workflows/ci.yml` and `.github/workflows/vhs.yml` are hand-maintained, not glob-driven:

```sh
grep -n 'sugar-marbles' .github/workflows/ci.yml .github/workflows/vhs.yml
```

If there are zero hits in either file, add the slug to both matrix `lib:` lists and re-push.

**`Class "SugarCraft\\Marbles\\Marbles" not found` in tests**

The `autoload` PSR-4 map in the lib's `composer.json` doesn't match the actual namespace declared in the source file. Confirm both say `SugarCraft\Marbles\`. Run `composer dump-autoload` from the lib dir.

**VHS demo renders but the `.gif` lands in the wrong path**

`Output .vhs/marbles.gif` MUST be a path relative to the lib dir, not to repo root. The CI workflow `cd`s into each lib before invoking `vhs`. Match the path in `sugar-bits/.vhs/progress.tape`.

**Icon 404 on the website**

The homepage tile in `docs/index.html` references the icon by path. Either drop in a real 256-square PNG with transparent background under `media/` (or `docs/img/icons/` to match existing tiles), or use the existing `lib-card-preview--empty` placeholder structure (copy from a tile that doesn't yet have a demo). Don't link a non-existent file.

**PR fails the `Author` check**

CI infra requires `Joe Huss <detain@interserver.net>` as the commit author:

```sh
git log -1 --format='%an <%ae>'
```

Must match exactly. Re-author with `git commit --amend --author='Joe Huss <detain@interserver.net>'` (this is the documented exception to "never amend").
