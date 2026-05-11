---
name: scaffold-library
description: Scaffolds a new SugarCraft library end-to-end per the AGENTS.md checklist. Creates <slug>/composer.json (path-repo closure copied from sugar-charts), README.md, CALIBER_LEARNINGS.md, phpunit.xml, src/<Class>.php with declare(strict_types=1) + PSR-4 namespace, tests/ stub, and updates root composer.json + MATCHUPS.md + PROJECT_NAMES.md + README.md table + docs/index.html tile + docs/lib/<slug>.html + .github/workflows/ci.yml + .github/workflows/vhs.yml + codecov.yml. Use when user says 'add new library', 'port <upstream>', 'create candy-X', 'create sugar-Y', or 'create honey-Z'. Do NOT use for adding features to an existing lib (follow that lib's CALIBER_LEARNINGS.md instead) or for renaming libs (separate flow).
paths:
  - */composer.json
  - */phpunit.xml
  - */src/**/*.php
  - */tests/**/*.php
  - MATCHUPS.md
  - PROJECT_NAMES.md
  - docs/index.html
  - docs/lib/*.html
  - .github/workflows/ci.yml
  - .github/workflows/vhs.yml
  - codecov.yml
---
# scaffold-library

End-to-end scaffolding of a new SugarCraft monorepo library. The output MUST be a checklist-complete lib that `composer install && composer test` passes on a clean clone.

## Critical

- **Pick the prefix from `PROJECT_NAMES.md`** before writing anything. `Candy-` = foundation/runtime, `Sugar-` = components/data/apps, `Honey-` = math/physics. If the role is ambiguous, ASK the user; do not guess.
- **Naming chain is rigid**: PascalCase upstream-style → kebab dir + composer pkg suffix → `sugarcraft/<slug>` composer pkg → `SugarCraft\<Sub>` namespace (drop the prefix). The one quirk: `SugarCraft\Core` keeps the umbrella name.
- **Copy the path-repo closure from an existing leaf lib** when wiring sibling deps. Every transitive `@dev` sibling needs BOTH a `require` entry AND a `repositories` path entry with `symlink: true`. Missing one = install fails.
- **`composer validate` MUST run without `--strict`**. `--strict` flags every `"sugarcraft/*": "@dev"` — this is EXPECTED before 1.0. Drop the flag.
- **CI matrices are hand-maintained**. Both CI workflows under `.github/workflows/` must get a matrix entry. Skipping either means PHPUnit or GIF rendering silently never runs for the new lib.
- **All commits land in the monorepo**. Do not create or push to per-lib downstream repos directly — the org sync workflow distributes them.

## Instructions

Use absolute paths — Bash CWD persists across calls and silent empty reads from a stale CWD are a documented gotcha.

### Step 1 — Confirm name, role, deps

Ask (or confirm) with the user:

1. Upstream repo URL — needed for `Mirrors charmbracelet/...` doc-comments and `MATCHUPS.md` row.
2. Proposed slug and prefix (Candy/Sugar/Honey). Cross-check against `PROJECT_NAMES.md` — if the slug already exists or the prefix conflicts with the role, stop and ask.
3. Direct sibling deps (e.g. depends on `candy-core` + `sugar-bits`). The path-repo closure is the FULL TRANSITIVE set, not just direct.

Derive: kebab-case slug, namespace = `SugarCraft\<SlugWithoutPrefix>` (exception: `candy-core` → `SugarCraft\Core`).

Verify: read `PROJECT_NAMES.md` to confirm slug is free.

### Step 2 — Read the canonical references

These are the source of truth — copy structure, don't invent it. Read in parallel:

- `sugar-charts/` for the canonical path-repo closure pattern.
- `candy-core/` for the canonical PHPUnit XML.
- `sugar-bits/` for the canonical leaf-lib skeleton.
- Root `composer.json` for where to add `require` + `repositories`.
- The CI workflow files under `.github/workflows/` for matrix entry shape.
- `MATCHUPS.md`, `PROJECT_NAMES.md`, root `README.md`, `docs/index.html`, `codecov.yml`.

Verify each file was read in full before generating any output.

### Step 3 — Create the lib's `composer.json`

Write the lib's composer manifest. Required block order (after `name`, `description`, `type`, `license`):

```json
{
  "keywords": ["sugarcraft", "<upstream-go-name>"],
  "homepage": "https://github.com/sugarcraft/<slug-placeholder>",
  "authors": [
    {"name": "Joe Huss", "email": "detain@interserver.net", "role": "Maintainer"}
  ],
  "support": {
    "issues": ".../issues",
    "source": ".../tree/master",
    "docs": ".../blob/master/README.md"
  },
  "require": {
    "php": "^8.3",
    "sugarcraft/<dep>": "@dev"
  },
  "require-dev": {
    "phpunit/phpunit": "^10.5"
  },
  "autoload": {"psr-4": {"SugarCraft\\<Sub>\\": "src/"}},
  "autoload-dev": {"psr-4": {"SugarCraft\\<Sub>\\Tests\\": "tests/"}},
  "repositories": [
    {"type": "path", "url": "../<dep>", "options": {"symlink": true}}
  ],
  "minimum-stability": "dev",
  "prefer-stable": true
}
```

Copy the `repositories` block verbatim from a working leaf lib and prune to the actual transitive closure. Verify: run `composer validate` (no `--strict`) — must exit 0 with at most the `@dev` warnings.

### Step 4 — Create the lib's PHPUnit config

Mirror an existing leaf lib's PHPUnit XML exactly. Required attributes on the root element:

```xml
<phpunit
    bootstrap="vendor/autoload.php"
    colors="true"
    failOnWarning="true"
    cacheDirectory=".phpunit.cache">
  <testsuites>
    <testsuite name="unit"><directory>tests</directory></testsuite>
  </testsuites>
  <source>
    <include><directory>src</directory></include>
  </source>
</phpunit>
```

### Step 5 — Create the source file under `src/`

Every PHP file starts with:

```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;
```

Class rules:

- `final class <Name>` unless extension is part of the public contract.
- Public `readonly` properties for state.
- Every `with*()` returns a new instance via a private `mutate(): self` helper.
- Bare-named accessors (NO `get` prefix) — `theme()` not `getTheme()`.
- Factory methods mirror upstream (`Theme::ansi()`, `Spinner::line()`).
- Doc-comment: `/** Mirrors upstream <Method>. */`.
- No comments restating WHAT — only WHY (constraints, invariants, upstream issue links).

### Step 6 — Create the lib's `README.md` and `CALIBER_LEARNINGS.md`

`README.md`: composer-require snippet, one-paragraph role summary, quickstart example, link to the upstream, Codecov per-flag badge (URL form: `?flag=<slug-placeholder>`).

`CALIBER_LEARNINGS.md`: empty stub with a top-level heading. Accumulated by future sessions, not by this scaffold.

### Step 7 — Create the lib's test file under `tests/`

Namespace `SugarCraft\<Sub>\Tests`. Class: `final class <Name>Test extends \PHPUnit\Framework\TestCase`. At minimum one snapshot/coercion test so the suite is green. Patterns:

- Snapshot tests assert raw `\x1b[...m` SGR bytes.
- Behaviour tests drive `update()` with scripted `KeyMsg`/`MouseMsg` and assert `[Model, ?Cmd]`.
- Coercion tests feed negative/oversized/empty/null to assert clamp/no-op.

Verify: `composer install && composer test` — green. If red, fix before continuing.

### Step 8 — Wire the root composer manifest

Use the Edit tool on the root manifest. Add to `require` and add the path-repo to `repositories` (mirror existing entries). Verify with `composer validate` (no `--strict`).

### Step 9 — Update cross-cut files

All Edit calls — do not rewrite, surgically insert:

- `MATCHUPS.md` — new row with status icon 🔴 (planned), 🟡 (partial), 🟢 (parity), 🚀 (split out). New scaffolds typically start 🟡 with a one-line scope note.
- `PROJECT_NAMES.md` — naming entry under the appropriate prefix section.
- Root `README.md` — bump the library count in the prose, add a row to the table, update the canonical test-loop snippet.
- `docs/index.html` — add a tile to the homepage grid. Use the same markup shape as adjacent tiles.
- `media/` or `docs/img/icons/` — 256-square candy-themed PNG. If no asset, leave a TODO note in the PR body — do NOT ship a missing-image tile silently.
- `codecov.yml` — add the per-flag entry so the Codecov badge resolves.

### Step 10 — Wire CI matrices (BOTH workflows)

The CI matrix arrays under `.github/workflows/` are hand-maintained, NOT glob-driven. Add the slug to each matrix list. Verify with Grep that the slug appears in both workflow files.

### Step 11 — Full verification gate

Run in sequence:

```sh
cd <slug> && composer install --quiet && composer test
cd <slug> && composer validate
composer validate
```

Then re-run the monorepo canonical loop for any lib that newly depends on the new slug.

Only after all checks pass, hand off to the user with the PR-ready summary (audit-driven title shape `<lib>: scaffold (audit #N)`, else `<lib>: scaffold initial port`).

## Examples

### Example 1 — naming collision caught before scaffolding

User: "Port the upstream renderer as candy-glow."

Actions:

1. Confirm prefix — renderer, foundation-y → `Candy-` fits. Check `PROJECT_NAMES.md` — `sugar-glow` exists, so the user means a different thing. Stop and clarify before scaffolding.
2. After user confirms naming, derive namespace `SugarCraft\Glow`. Conflict — `sugar-glow` already owns `SugarCraft\Glow`. Resolve with user (e.g. `SugarCraft\GlowRenderer`) before any file is written.

Result: collision caught before scaffolding; user picks final name.

### Example 2 — leaf lib depending on `candy-core` + `sugar-bits`

User: "Create a new sugar-prefixed lib, depends on candy-core and sugar-bits."

Actions:

1. Verify slug free in `PROJECT_NAMES.md`. Pick namespace.
2. Read `sugar-charts/` composer manifest — its path-repo closure already includes `candy-core` + `sugar-bits`, so copy and prune to those two.
3. Write the lib files (composer manifest, PHPUnit XML, README, CALIBER_LEARNINGS stub, source class, test class).
4. Edit root composer manifest (require + repositories), `MATCHUPS.md` (🟡 row), `PROJECT_NAMES.md`, root `README.md` (count + table + loop), `docs/index.html` (tile), `codecov.yml`, both CI workflows.
5. Run verification gate. All checks green.

Result: branch ready for `unset GITHUB_TOKEN && gh pr create`.

## Common Issues

**`Your requirements could not be resolved … sugarcraft/<dep> requires …` on install** — Missing transitive path-repo in the new lib's `repositories` block. Re-copy the full closure from a working leaf lib, prune to actual transitive set (run `composer why sugarcraft/<dep>` in a consumer to enumerate).

**`composer validate --strict` exits non-zero with `"sugarcraft/<dep>": "@dev" is not a stable version constraint`** — Drop the `--strict` flag. Pre-release path-repos always trip this. Documented in `AGENTS.md` gotchas.

**CI green locally, PHPUnit silently never runs in GitHub Actions** — Missing matrix entry in the CI workflow file. Hand-maintained, not glob-driven. Re-grep the slug against both workflow files.

**`PHPUnit\Framework\Exception: PHP Warning ...` even though code works** — `failOnWarning="true"` is on. Fix the warning at source; do NOT remove the attribute — every lib's PHPUnit config carries it.

**`Cannot redeclare ...` or PSR-4 autoload mismatch on first test** — Namespace doesn't match dir layout. Files must live under `src/` and the composer `autoload.psr-4` MUST use double backslash in JSON (`"SugarCraft\\<Sub>\\": "src/"`).

**Bash reads return empty content unexpectedly** — Bash CWD persisted from a previous `cd <slug>` and the lib doesn't exist yet. Always anchor with absolute paths.

**Codecov badge 404s on README** — Forgot the `codecov.yml` entry for the new flag. Add the per-lib flag block.
