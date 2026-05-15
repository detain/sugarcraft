---
name: add-library-checklist
description: Walks through the full 14-file touchlist when scaffolding a new SugarCraft port. Creates `<slug>/composer.json`, `<slug>/phpunit.xml`, `<slug>/README.md`, `<slug>/CALIBER_LEARNINGS.md`, `<slug>/src/<Class>.php`, then updates root `composer.json` (`require` + `repositories[]`), `MATCHUPS.md`, `PROJECT_NAMES.md`, root `README.md`, `docs/index.html`, `docs/lib/<slug>.html`, `media/icons/<slug>.png`, `.github/workflows/vhs.yml` matrix, and `codecov.yml` (flags + components). Use when user says 'add a library', 'port <upstream>', 'scaffold lib <slug>', or 'new SugarCraft lib'. Do NOT use to modify an existing lib's metadata — use direct Edit instead.
paths:
  - composer.json
  - */composer.json
  - */phpunit.xml
  - */src/**/*.php
  - */tests/**/*.php
  - MATCHUPS.md
  - PROJECT_NAMES.md
  - docs/index.html
  - docs/lib/*.html
  - media/icons/*.png
  - .github/workflows/vhs.yml
  - codecov.yml
  - scripts/affected-libs.php
---
# Add a SugarCraft library — full touchlist

Scaffolds a new monorepo port end-to-end. Every file in the checklist must be touched or the lib is invisible to CI, docs site, Codecov, or the VHS renderer.

## Critical

1. **Pick prefix from `PROJECT_NAMES.md`** before writing any path. `Candy-` = foundation/system/framework, `Sugar-` = components/data/forms/apps, `Honey-` = math/physics/motion. Slug is kebab-case (`candy-shine`). Namespace is `SugarCraft\<Sub>\` where `<Sub>` drops the prefix (`candy-shine` → `SugarCraft\Shine\`). **Quirk:** `candy-core` → `SugarCraft\Core\` (umbrella). Composer package is always `sugarcraft/<slug>`.
2. **Verify slug is unused** before scaffolding: `ls /home/sites/sugarcraft/<slug> 2>/dev/null && echo TAKEN || echo FREE`. Also grep `MATCHUPS.md` and `PROJECT_NAMES.md` for the slug — they are the source of truth.
3. **Path-repo closure must be transitive.** If your `composer.json` requires `sugarcraft/candy-shine`, and `candy-shine` itself requires `sugarcraft/candy-core` and `sugarcraft/candy-sprinkles`, all three need entries under `repositories[]`. Copy the closure pattern from `sugar-charts/composer.json` — do not invent.
4. **Run sub-agents ONE AT A TIME** for this scaffold. `MATCHUPS.md`, root `README.md`, root `composer.json`, `docs/index.html`, and `codecov.yml` are shared files — parallel writes collide. Check `.codenomad/worktreeMap.json` if unsure whether another worktree owns the slug.
5. **`.github/workflows/vhs.yml` is HAND-MAINTAINED** — its inline `all=(...)` array does not auto-discover. Forget it and the rendered GIF never appears. Non-visual primitive libs (`candy-pty`, FFI bindings, codecs) are EXEMPT — note exemption in the PR body instead of adding to the matrix.
6. **`ci.yml` is auto-discovered** by `scripts/affected-libs.php`. Do NOT edit `ci.yml`. Only edit `scripts/affected-libs.php` if the lib needs Windows/macOS runners — add slug to `WINDOWS_LIBS` / `MACOS_LIBS` arrays.
7. **Drop `--strict` on `composer validate`** — every `"sugarcraft/*": "@dev"` flags as a warning, EXPECTED pre-1.0.

## Instructions

### Step 1 — Confirm slug, namespace, upstream, deps

Ask the user (or derive from request):
- Slug (e.g. `candy-newlib`)
- Upstream URL (e.g. `https://github.com/charmbracelet/newlib`) — for `Mirrors charmbracelet/<repo>` doc-comments and the `MATCHUPS.md` row
- One-line role summary
- Sibling deps (transitive closure — copy from a nearest analog)

Derive:
- Namespace `SugarCraft\<Sub>\` (drop prefix, PascalCase)
- Composer package `sugarcraft/<slug>`

Validate before proceeding: `ls /home/sites/sugarcraft/<slug>` returns no-such-file. Grep `PROJECT_NAMES.md` and `MATCHUPS.md` — slug absent.

### Step 2 — Reference shapes (READ before writing)

Read these canonical files. Do not write Step 3+ until done:
- `sugar-bits/composer.json` — leaf composer metadata
- `sugar-charts/composer.json` — path-repo transitive closure example
- `candy-core/phpunit.xml` — PHPUnit 10 config canonical
- `candy-sprinkles/src/Style.php` — immutable + fluent `mutate()` helper canonical
- `sugar-wishlist/src/Lang.php` — i18n `Lang::t()` wrapper (only if lib needs i18n)
- `MATCHUPS.md` — row format + status icons (🔴🟡🟢🚀 — start at 🔴 or 🟡)
- `codecov.yml` — flags + components blocks
- `.github/workflows/vhs.yml` — the `all=(...)` array (visual libs only)

Verify: you can name the exact composer keys, the `mutate()` signature, and the `MATCHUPS.md` column order from memory before proceeding.

### Step 3 — Create `<slug>/composer.json`

Uses Step 1 inputs. Required keys in order:

```json
{
    "name": "sugarcraft/<slug>",
    "description": "<one-line role>. Mirrors charmbracelet/<upstream>.",
    "type": "library",
    "license": "MIT",
    "keywords": ["sugarcraft", "<upstream-go-name>", "<area>"],
    "homepage": "https://github.com/sugarcraft/<slug>",
    "authors": [{"name": "Joe Huss", "email": "detain@interserver.net", "role": "Maintainer"}],
    "support": {
        "issues": "https://github.com/sugarcraft/<slug>/issues",
        "source": "https://github.com/sugarcraft/<slug>",
        "docs": "https://github.com/sugarcraft/<slug>#readme"
    },
    "minimum-stability": "dev",
    "require": {
        "php": "^8.3",
        "sugarcraft/<dep>": "@dev"
    },
    "require-dev": {"phpunit/phpunit": "^10.5"},
    "autoload": {"psr-4": {"SugarCraft\\<Sub>\\": "src/"}},
    "autoload-dev": {"psr-4": {"SugarCraft\\<Sub>\\Tests\\": "tests/"}},
    "repositories": [
        {"type": "path", "url": "../<dep>", "options": {"symlink": true}}
    ]
}
```

`repositories[]` must list the FULL transitive closure of every `sugarcraft/*` constraint in `require`. Verify: `cd <slug> && composer validate` (without `--strict`) reports valid.

### Step 4 — Create `<slug>/phpunit.xml`

Copy `candy-core/phpunit.xml` verbatim, adjust testsuite name to the lib's `<Sub>`. Must contain `bootstrap="vendor/autoload.php"`, `colors="true"`, `failOnWarning="true"`, `cacheDirectory=".phpunit.cache"`, and `<source><include><directory>src</directory></include></source>`.

### Step 5 — Create `<slug>/src/<Class>.php`

Uses Step 1 namespace. Skeleton:

```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;

/**
 * Mirrors charmbracelet/<upstream>.<Class>.
 */
final class <Class>
{
    public function __construct(
        public readonly <type> $<field>,
    ) {}

    public function with<Field>(<type> $value): self
    {
        return $this->mutate(['<field>' => $value]);
    }

    /** @param array<string, mixed> $changes */
    private function mutate(array $changes): self
    {
        return new self(
            $changes['<field>'] ?? $this-><field>,
        );
    }
}
```

Public class `final`. `declare(strict_types=1);` mandatory. Bare-named accessors (no `get` prefix).

### Step 6 — Create `<slug>/tests/<Class>Test.php`

Namespace `SugarCraft\<Sub>\Tests`. At least one test per public method. Patterns from `sugar-bits/tests/` and `candy-core/tests/`: snapshot (assert raw `\x1b[1m` SGR bytes), behaviour (drive `update()`, assert `[Model, ?Cmd]`), coercion (negative/oversized/empty/null).

Verify: `cd <slug> && composer install && vendor/bin/phpunit` is green.

### Step 7 — Create `<slug>/README.md`

Must contain `composer require sugarcraft/<slug>:@dev` snippet, quickstart code block, and the Codecov badge wired to the `<slug>` flag (copy from `sugar-bits/README.md`).

### Step 8 — Create `<slug>/CALIBER_LEARNINGS.md`

Start empty with a top-level heading `# <slug> learnings`. Populated by future sessions.

### Step 9 — Update root `composer.json`

Add to `require`: `"sugarcraft/<slug>": "@dev"`. Add to `repositories[]`: `{"type": "path", "url": "./<slug>", "options": {"symlink": true}}`. Run `composer validate` (no `--strict`) at repo root.

### Step 10 — Update `MATCHUPS.md` and `PROJECT_NAMES.md`

`MATCHUPS.md`: add a row mapping `charmbracelet/<upstream>` → `sugarcraft/<slug>` with the appropriate status icon (🔴 not started, 🟡 in progress, 🟢 complete, 🚀 released). Keep table column order intact.

`PROJECT_NAMES.md`: add the naming entry under the correct prefix section (`Candy-` / `Sugar-` / `Honey-`) with the rationale.

### Step 11 — Update root `README.md`

Three places:
- Library count (header)
- Lib table row (alphabetical inside prefix section)
- The whole-monorepo `for d in ...` test loop — append slug

### Step 12 — Update `docs/index.html` and create `docs/lib/<slug>.html`

`docs/index.html`: add a homepage tile (copy an existing tile in the same prefix section).

`docs/lib/<slug>.html`: create the per-lib page (copy nearest analog from `docs/lib/`).

### Step 13 — Add `media/icons/<slug>.png`

256×256 candy-themed PNG. If user has not supplied one, request it and continue with a placeholder note in the PR body — do NOT commit a missing icon.

### Step 14 — Update `.github/workflows/vhs.yml` (visual libs only)

Add slug to the inline `all=(...)` bash array. **Skip this step** for non-visual primitives (FFI bindings, codecs, PTY layers like `candy-pty`) — note exemption in the PR body.

### Step 15 — Update `codecov.yml`

Add `<slug>` to the `flags:` block AND to `component_management.individual_components[]`. Both — Codecov silently ignores a flag without a component.

### Step 16 — Conditional `scripts/affected-libs.php` edit

Only if lib needs Windows or macOS runners: add slug to the `WINDOWS_LIBS` or `MACOS_LIBS` array in `scripts/affected-libs.php`. Default-skip — most libs are Linux-only.

### Step 17 — Verification gate (BEFORE commit)

Run in order, halt on first failure:

```sh
cd /home/sites/sugarcraft/<slug> && composer install && vendor/bin/phpunit
cd /home/sites/sugarcraft && composer validate
php /home/sites/sugarcraft/scripts/affected-libs.php | grep <slug>
```

Last command MUST list the new slug. If absent, `composer.json` or `phpunit.xml` is malformed.

### Step 18 — Commit and ship

Branch `ai/<slug>-scaffold`. Author `Joe Huss <detain@interserver.net>`. Caliber pre-commit hook handles agent-config sync (check with `grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo hook-active || echo no-hook`). Follow ship-as-you-go: commit → push → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`. PR body MUST end with `## Test plan` citing test count + suite name.

## Examples

**User:** "Port charmbracelet/lipgloss-table as `sugar-griddle`."

**Actions:**
1. Confirm: slug `sugar-griddle`, namespace `SugarCraft\Griddle\`, package `sugarcraft/sugar-griddle`, upstream `charmbracelet/lipgloss-table`. Verify `ls sugar-griddle` returns nothing; grep `MATCHUPS.md` — absent.
2. Read reference shapes (Step 2). Closure for `sugar-charts` shows path-repos for `candy-core`, `candy-sprinkles` — confirm `sugar-griddle` needs same plus any table-specific deps.
3. Scaffold `sugar-griddle/composer.json`, `phpunit.xml`, `src/Table.php` (final class, `mutate()` helper, `with*()` methods), `tests/TableTest.php`, `README.md`, `CALIBER_LEARNINGS.md`.
4. Update root `composer.json` (`require` + `repositories[]`), `MATCHUPS.md` (🟡 row), `PROJECT_NAMES.md` (Sugar- section), root `README.md` (count + table row + test loop), `docs/index.html` (tile), `docs/lib/sugar-griddle.html` (new), drop `media/icons/sugar-griddle.png`.
5. Append `sugar-griddle` to `.github/workflows/vhs.yml` `all=(...)` array (visual lib). Add to `codecov.yml` flags + components.
6. Run Step 17 verification gate — all green.
7. Branch `ai/sugar-griddle-scaffold`, commit, push, `gh pr create`, merge, pull master.

**Result:** `sugar-griddle/` is the 46th lib in the monorepo, picked up automatically by `ci.yml`, renders demos via `vhs.yml`, reports coverage to its own Codecov flag, and is discoverable from the homepage tile grid.

## Common Issues

- **`composer validate` reports `sugarcraft/<dep>: @dev is not a valid stability flag`** — you passed `--strict`. Drop it; `@dev` is expected for path-repos pre-1.0.
- **`Class SugarCraft\<Sub>\<Class> not found` in tests** — PSR-4 mismatch. Verify `composer.json` `autoload.psr-4` matches `SugarCraft\\<Sub>\\` exactly (with double backslashes in JSON), then `composer dump-autoload` in `<slug>/`.
- **`ci.yml` skips the new lib** — `scripts/affected-libs.php` did not pick it up. Run `php scripts/affected-libs.php | grep <slug>`; if absent, `<slug>/composer.json` is missing or `<slug>/phpunit.xml` is missing.
- **VHS GIF never renders after merge** — slug missing from `.github/workflows/vhs.yml` `all=(...)` array. The matrix is hand-maintained; `ci.yml` auto-discovery does NOT cover it.
- **Codecov badge in README is gray/404** — flag exists in `codecov.yml` but no component, OR component exists but flag is missing. Both blocks need the slug.
- **`gh pr create` warns `1 uncommitted change`** — informational; Caliber's pre-commit refresh touched `<slug>/CALIBER_LEARNINGS.md` after `git add`. PR still creates. Verify the change is benign before merge.
- **Sibling lib's tests fail after adding new lib** — you added a transitive `@dev` dep without updating that lib's `repositories[]`. Add a `{type: path, url: "../<slug>", options: {symlink: true}}` entry to every consuming lib's `composer.json`.
- **`Cannot redeclare class SugarCraft\<Sub>\<Class>`** when running the whole-monorepo loop — leftover symlink from an aborted scaffold. Remove `vendor/sugarcraft/<slug>` from siblings and re-run `composer install`.
- **`media/icons/<slug>.png` committed as 0 bytes** — placeholder slipped through. Use `file media/icons/<slug>.png` to confirm it is a real PNG before pushing.