---
name: add-library-checklist
description: Scaffolds a new SugarCraft monorepo port end-to-end across every touchpoint: creates <slug>/composer.json + phpunit.xml + README.md + CALIBER_LEARNINGS.md + src/<Class>.php, then wires it into root composer.json (require + repositories[]), docs/MATCHUPS.md, PROJECT_NAMES.md, root README.md table, docs/index.html tiles, docs/_data/<slug>.{json,body.html} → tools/gen-docs.php, media/icons/<slug>.png, .github/workflows/vhs.yml matrix, and codecov.yml (flag + component). Use when the user says 'add a library', 'port <upstream>', 'scaffold lib <slug>', 'new candy-/sugar-/honey- lib', 'create SugarX'. Do NOT use to edit an existing lib's metadata (use direct Edit), add a feature to an existing lib (follow that lib's CALIBER_LEARNINGS.md), rename a lib, or add a plain sugarcraft dep to an existing lib (use path-repo-closure).
paths:
  - */composer.json
  - */phpunit.xml
  - docs/_data/*
  - codecov.yml
  - .github/workflows/vhs.yml
  - docs/MATCHUPS.md
  - PROJECT_NAMES.md
---
# Add a Library — Full Monorepo Checklist

Scaffold a new port so it looks byte-identical to existing libs and passes every CI check. There are **14 touchpoints**. Miss one and CI (`scripts/affected-libs.php`, codecov, docs, vhs) drifts silently.

## Critical

- **Namespace derivation is mechanical.** Slug `sugar-boxer/` → composer `sugarcraft/sugar-boxer` → namespace `SugarCraft\Boxer\`. The Sub is the PascalCase of the part after the prefix. **Quirk:** `candy-core` → `SugarCraft\Core\` (not `SugarCraft\Candy\Core`). Prefix picks the family, NOT the namespace root — every lib is under `SugarCraft\`.
- **Prefix = role.** `candy-` = foundation/system, `sugar-` = components/data/apps, `honey-` = math/physics. Confirm with the user which family before naming; cross-check `PROJECT_NAMES.md`.
- **Every `sugarcraft/*` require needs a path-repo for the FULL transitive closure**, not just direct deps. Copy the `repositories[]` block from a sibling that already depends on the same set (canonical: `sugar-charts/composer.json`). Verify with `php tools/check-path-repos.php` (add `--fix` to auto-insert).
- **`composer validate --strict` is EXPECTED to fail** on `"@dev"` constraints — drop `--strict`.
- **NEVER hand-edit the generated pages in `docs/lib/`** — they are produced by `tools/gen-docs.php` from the sources in `docs/_data/` (copy `docs/_data/sugar-boxer.json` + `docs/_data/sugar-boxer.body.html` and swap identity).
- **Run sub-agents ONE AT A TIME** — concurrent writes to `docs/MATCHUPS.md` / root `README.md` collide.
- **Do NOT run `caliber`** on this machine and do NOT commit GIFs.

## Instructions

### Step 1 — Fix identity (do this first, everything derives from it)
Record: `slug` (kebab dir), `Sub` (namespace segment), root class name (usually PascalCase of the full slug, e.g. `SugarBoxer`), upstream repo URL + Go name, one-line role, and the direct `sugarcraft/*` deps. **Verify** the slug is unique: `ls -d */ | grep <slug>` returns nothing, and `grep -n <slug> PROJECT_NAMES.md docs/MATCHUPS.md`. Proceed only if unclaimed.

### Step 2 — Scaffold `<slug>/composer.json`
Copy from a sibling with matching deps. Required shape (from `sugar-boxer/composer.json`):
```json
{
    "name": "sugarcraft/<slug>",
    "description": "PHP port of <owner>/<repo> — <role>.",
    "type": "library",
    "license": "MIT",
    "keywords": ["tui", "<topic>", "<upstream-go-name>", "sugarcraft"],
    "homepage": "https://github.com/sugarcraft/<slug>",
    "authors": [{"name": "Joe Huss", "email": "detain@interserver.net", "role": "Maintainer"}],
    "support": {"issues": "https://github.com/sugarcraft/<slug>/issues", "source": "https://github.com/sugarcraft/<slug>"},
    "require": {"php": "^8.3", "sugarcraft/candy-core": "dev-master"},
    "require-dev": {"phpunit/phpunit": "^10.5"},
    "autoload": {"psr-4": {"SugarCraft\\<Sub>\\": "src/"}},
    "autoload-dev": {"psr-4": {"SugarCraft\\<Sub>\\Tests\\": "tests/"}},
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [{"type": "path", "url": "../candy-core", "options": {"symlink": true}}]
}
```
Add a `require` line AND a `repositories[]` path-repo for **each** sibling dep and its full transitive closure. **Verify:** `cd <slug> && composer install` then `php tools/check-path-repos.php` reports the closure clean before continuing.

### Step 3 — `<slug>/phpunit.xml`
Exact copy, only the testsuite `name` changes (from `sugar-boxer/phpunit.xml`):
```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php" colors="true"
         cacheDirectory=".phpunit.cache" failOnWarning="true">
    <testsuites>
        <testsuite name="<slug>"><directory>tests</directory></testsuite>
    </testsuites>
    <source><include><directory>src</directory></include></source>
</phpunit>
```
This file's presence is the marker `scripts/affected-libs.php` uses to discover the lib. **Verify** it exists.

### Step 4 — `<slug>/src/<Class>.php`
`declare(strict_types=1);` first line, `final` class, immutable+fluent via `mutate()` (see `candy-sprinkles/src/Style.php`), `::new()` root factory (never `::create()`/`::make()`/`::default()`), bare accessors (no `get`). Doc-comment the class with `Mirrors <owner>/<repo>`. For a TUI Model, implement `init()` / `update(Msg): [Model, ?Cmd]` / `view()` per `candy-core/src/Model.php` — side effects go in `Cmd`, never `view()`. Add `tests/<Class>Test.php` under `SugarCraft\<Sub>\Tests\` with ≥1 test per public method. **Verify:** `cd <slug> && vendor/bin/phpunit` is green.

### Step 5 — `<slug>/README.md` + `<slug>/CALIBER_LEARNINGS.md`
README: `composer require sugarcraft/<slug>` + quickstart + codecov badge (flag=`<slug>`). Copy a sibling README and swap identity. CALIBER_LEARNINGS.md may start as a stub heading.

### Step 6 — Root `composer.json`
Add the lib to root `require` and a `repositories[]` path-repo entry. Uses Step 1's slug. **Verify:** `composer validate` (WITHOUT `--strict`) passes.

### Step 7 — `docs/MATCHUPS.md`
Append a row to the correct table ("Charmbracelet libraries" or the relevant section). Format from existing rows:
```
| [<owner>/<repo>](https://github.com/<owner>/<repo>) | **<Class>** | `<slug>/` | `sugarcraft/<slug>` | `SugarCraft\<Sub>` | 🟡 | <role> |
```
Status: 🔴 planning, 🟡 in progress, 🟢 v1 ready, 🚀 split repo. New scaffolds are 🟡 (or 🔴 if no code).

### Step 8 — `PROJECT_NAMES.md`
Add the name to its category table so the naming rulebook stays authoritative.

### Step 9 — Root `README.md`
Add a table row (see line for `sugar-boxer`):
```
| <img src="media/icons/<slug>.png" width="48" alt=""> | **[<Class>](<slug>/)** | <role> Port of [<repo>](https://github.com/<owner>/<repo>) |
```

### Step 10 — add the `docs/_data/` sources, then generate
Create both data files (copy `docs/_data/sugar-boxer.json` + `docs/_data/sugar-boxer.body.html` and swap identity). JSON keys: `title`, `description`, `ogTitle`, `ogDescription`, `emoji`, `displayName`, `tagline`, `phpVersion`, `detailMeta` (tag-chips incl. `port of <a href=...>`). body.html: `<p class="lede">` → Install `<pre>` → Quickstart `<pre>`. Then run:
```sh
php tools/gen-docs.php
```
This regenerates the lib's page under `docs/lib/`. **Verify** the page appears; NEVER edit it by hand.

### Step 11 — `docs/index.html`
Add the icon-strip `<a>` link AND the `lib-card` block (both reference `sugar-boxer` around lines 135 / 488 as the template). The card points at `.vhs/<demo>.gif` and `img/icons/<slug>.png`.

### Step 12 — `media/icons/<slug>.png`
Add a 48px icon. If none yet, copy a placeholder sibling icon so the README/docs img refs resolve.

### Step 13 — `.github/workflows/vhs.yml`
Add `<slug>` to the hand-maintained `all=(...)` bash array (around line 143). Skip ONLY if the lib is non-visual (FFI, codec, pure-data — e.g. `candy-pty`, `candy-testing`). Add a `.vhs/<demo>.tape` under `<slug>/.vhs/` using `Set Theme "TokyoNight"`, quote ALL values, `Type "php examples/<demo>.php"`. Multi-directive lines are OK (`Down Sleep 200ms`).

### Step 14 — `codecov.yml`
Add TWO entries: a `flag_management` flag and a `component_management` component (templates around lines 165 and 470):
```yaml
# flag_management → flags:
    - name: <slug>
      paths: ["<slug>/src/**"]
      carryforward: true
# component_management → individual_components:
    - component_id: <slug>
      name: <slug>
      paths:
        - "<slug>/src/**"
      flag_regexes:
        - "^<slug>$"
```
`ci.yml` needs NO edit — it auto-discovers via `scripts/affected-libs.php` (the phpunit.xml from Step 3). Only touch `WINDOWS_LIBS`/`MACOS_LIBS` for OS-specific runners.

### Step 15 — Final verification + ship
```sh
cd <slug> && composer install && vendor/bin/phpunit   # green
cd .. && php tools/check-path-repos.php                # closure clean
php tools/gen-docs.php                                 # docs regenerated
composer validate                                       # no --strict
```
Then ship-as-you-go on branch `ai/<slug>-scaffold`: commit → push → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`. Author `Joe Huss <detain@interserver.net>`. Do NOT commit GIFs; do NOT run caliber.

## Examples

**User says:** "Port charmbracelet/gum as a new sugar- lib called sugar-gum"

**Actions taken:**
1. Identity: slug `sugar-gum`, Sub `Gum`, namespace `SugarCraft\Gum\`, class `SugarGum`, upstream `charmbracelet/gum`, deps `candy-core` + `candy-forms`. `grep sugar-gum PROJECT_NAMES.md docs/MATCHUPS.md` → unclaimed.
2–4. Create `sugar-gum/composer.json` (require + path-repos for candy-core, candy-forms + their closure copied from a lib that already uses both), `sugar-gum/phpunit.xml` (testsuite name `sugar-gum`), `sugar-gum/src/SugarGum.php` (`final`, `::new()`, `mutate()`), `sugar-gum/tests/SugarGumTest.php`. `cd sugar-gum && composer install && vendor/bin/phpunit` → green.
5–14. README + CALIBER_LEARNINGS + root composer.json + MATCHUPS row (🟡) + PROJECT_NAMES + root README row + `docs/_data/sugar-gum.json` + `docs/_data/sugar-gum.body.html` → `php tools/gen-docs.php` + docs/index.html card+strip + media/icons/sugar-gum.png + vhs.yml `all=(...)` array + codecov flag+component.
15. All verify gates green → ship on `ai/sugar-gum-scaffold`.

**Result:** `scripts/affected-libs.php` now discovers `sugar-gum`, codecov shows a `sugar-gum` flag+component, the `docs/lib/` page is live, and `vendor/bin/phpunit` passes — matching every existing lib's shape.

## Common Issues

- **`php tools/check-path-repos.php` reports "missing path-repo for sugarcraft/X (via A → B → X)"**: a transitive dep lacks its `repositories[]` entry. Run `php tools/check-path-repos.php --fix` to auto-insert, then re-run to confirm clean. Copy the closure from `sugar-charts/composer.json` if unsure.
- **`composer validate` errors on `"sugarcraft/*": "@dev"`**: only when you passed `--strict`. Drop `--strict` — `@dev` on sibling path-repos is EXPECTED and correct.
- **`vendor/bin/phpunit` fails locally but code looks fine**: per-lib `composer.lock`/`vendor/` go stale (gitignored, CI unaffected). Run `cd <slug> && composer update` before trusting the failure.
- **New lib missing from CI matrix**: `scripts/affected-libs.php` discovers libs by their `phpunit.xml`. Confirm Step 3's file exists at `<slug>/phpunit.xml` — no root edit is needed.
- **`Class SugarCraft\<Sub>\<Class>` not found in tests**: PSR-4 mismatch. `autoload` must be `"SugarCraft\\<Sub>\\": "src/"` and dir must be `<slug>/src/<Class>.php`. Run `composer dump-autoload` after fixing.
- **A generated page in `docs/lib/` shows stale/empty content**: never edit it directly. Fix the lib's sources in `docs/_data/` (its `.json` + `.body.html`) and re-run `php tools/gen-docs.php`.
- **codecov badge stays gray / flag missing**: you added the flag but not the component (or vice-versa). Both blocks in `codecov.yml` are required — flag under `flag_management`, component under `component_management`.
- **vhs CI skips the new lib**: the `all=(...)` array in `.github/workflows/vhs.yml` is hand-maintained. Add `<slug>` to it (unless the lib is legitimately non-visual).
