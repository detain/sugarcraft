---
name: add-library-checklist
description: Scaffolds a new SugarCraft monorepo port end-to-end across all 14 touchpoints: creates <slug>/composer.json + phpunit.xml + README.md + CALIBER_LEARNINGS.md + src/<Class>.php, then wires it into root composer.json (require + repositories[]), docs/MATCHUPS.md, PROJECT_NAMES.md, root README.md, docs/index.html, docs/lib/<slug>.html, media/icons/<slug>.png, .github/workflows/vhs.yml matrix, and codecov.yml (flag + component). Use when the user says 'add a library', 'port <upstream>', 'scaffold lib <slug>', 'new SugarCraft lib', 'create candy-X / sugar-Y / honey-Z'. Do NOT use to edit an existing lib's metadata (use direct Edit), add a feature to an existing lib (follow that lib's CALIBER_LEARNINGS.md), or rename a lib (separate flow).
paths:
  - */composer.json
  - */phpunit.xml
  - docs/MATCHUPS.md
  - PROJECT_NAMES.md
  - codecov.yml
  - .github/workflows/vhs.yml
  - docs/lib/*.html
---
# Add a SugarCraft library (full checklist)

Scaffold a new monorepo port across every required file. Skipping a touchpoint silently breaks CI badges, the homepage, or GIF rendering. Work top-to-bottom; each step has a validation gate.

## Critical

- **Pick the name FIRST** from `PROJECT_NAMES.md` (two CamelCase words: sweet + functional). Resolve slug → pkg → namespace before touching any file: `CandyShine` → dir `candy-shine/` → pkg `sugarcraft/candy-shine` → namespace `SugarCraft\Shine\`. **Quirk:** `candy-core` → `SugarCraft\Core\`.
- **`MATCHUPS.md` now lives at `docs/MATCHUPS.md`** (moved). Links inside it are relative to `docs/`.
- **`.github/workflows/ci.yml` auto-discovers** the lib from `composer.json` + `phpunit.xml` — do NOT edit it. Only edit `scripts/affected-libs.php` (`WINDOWS_LIBS`/`MACOS_LIBS`) if the lib needs an OS-specific runner.
- **`.github/workflows/vhs.yml` is hand-maintained** — the `all=(...)` bash array (~line 143) MUST gain the slug or the GIF never renders. Non-visual libs (FFI bindings like `candy-pty`, syscall wrappers, codecs) are EXEMPT — skip the tape AND the matrix entry, and call out the exemption in the PR body.
- Run sub-agents ONE AT A TIME — concurrent writes to `docs/MATCHUPS.md` / `README.md` / `codecov.yml` collide.
- Do NOT run `caliber refresh` on this machine.

## Instructions

### Step 1 — `<slug>/composer.json`
Copy `sugar-charts/composer.json` and prune. Exact shape:
```json
{
    "name": "sugarcraft/<slug>",
    "description": "PHP port of <upstream> \u2014 <one-line role>.",
    "type": "library",
    "license": "MIT",
    "keywords": ["tui", "terminal", "<feature kebab>", "<upstream go name e.g. bubbletea>", "sugarcraft"],
    "homepage": "https://github.com/sugarcraft/<slug>",
    "authors": [{"name": "Joe Huss", "email": "detain@interserver.net", "role": "Maintainer"}],
    "support": {
        "issues": "https://github.com/sugarcraft/<slug>/issues",
        "source": "https://github.com/sugarcraft/<slug>",
        "docs": "https://sugarcraft.github.io/lib/<slug>.html"
    },
    "require": {
        "php": "^8.3",
        "sugarcraft/candy-core": "dev-master"
    },
    "require-dev": {"phpunit/phpunit": "^10.5"},
    "autoload": {"psr-4": {"SugarCraft\\<Sub>\\": "src/"}},
    "autoload-dev": {"psr-4": {"SugarCraft\\<Sub>\\Tests\\": "tests/"}},
    "minimum-stability": "dev",
    "prefer-stable": true,
    "repositories": [
        {"type": "path", "url": "../candy-core", "options": {"symlink": true}}
    ]
}
```
Rules: `keywords` always include `"sugarcraft"` + the upstream Go name. Every `sugarcraft/<dep>` in `require` (sibling deps use `"dev-master"`) needs a matching `../<dep>` path-repo in `repositories[]` for the FULL transitive closure. **Gate:** run `php tools/check-path-repos.php --fix` then re-run bare `php tools/check-path-repos.php` — it must report zero gaps before continuing.

### Step 2 — `<slug>/phpunit.xml`
This file is the marker `scripts/affected-libs.php` keys off — CI ignores the lib without it. Copy `candy-core/phpunit.xml`, change only the testsuite name:
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
**Gate:** confirm `<slug>/composer.json` AND `<slug>/phpunit.xml` both exist — ci.yml discovery needs both.

### Step 3 — `<slug>/src/<Class>.php`
Uses the namespace from Step 1. Immutable + fluent pattern (canonical: `candy-sprinkles/src/Style.php`):
```php
<?php

declare(strict_types=1);

namespace SugarCraft\<Sub>;

/**
 * <one-line role>.
 *
 * Mirrors charmbracelet/<repo>.<Type>.
 */
final class <Class>
{
    private function __construct(
        public readonly string $field = '',
    ) {}

    public static function new(): self // ::new() is the default root — never ::create()/::make()/::default()
    {
        return new self();
    }

    public function withField(string $field): self
    {
        return $this->mutate(field: $field);
    }

    private function mutate(?string $field = null): self
    {
        return new self($field ?? $this->field);
    }
}
```
Bare accessors (no `get` prefix). Factories mirror upstream (`Theme::ansi()`, `Spinner::line()`). For nullable fields add a paired `bool $XSet = false` sentinel (see `sugar-bits/src/TextInput/TextInput.php` `withValidator()`). TUI roots implement `SugarCraft\Core\Model` (`init/update/view`). **Gate:** `cd <slug> && composer install && vendor/bin/phpunit` is green (write a stub test if no logic yet — every public method needs ≥1 test).

### Step 4 — `<slug>/README.md` + `<slug>/CALIBER_LEARNINGS.md`
README: `composer require sugarcraft/<slug>` + quickstart + per-flag Codecov badge (copy `sugar-bits/README.md` shape). `CALIBER_LEARNINGS.md`: start with a 1-line header, accumulate patterns as you port.

### Step 5 — root `composer.json`
Add the lib to root `require` and `repositories[]` (path-repo `{type: path, url: "./<slug>", options: {symlink: true}}`). **Gate:** `composer validate` from root (drop `--strict` — it flags every `"@dev"` / `"dev-master"`, which is EXPECTED pre-1.0).

### Step 6 — `docs/MATCHUPS.md` row
Append a table row under the right section (depends on this step's output: pkg + namespace from Step 1):
```
| [<upstream>](https://github.com/<org>/<repo>) | **<CamelName>** | `<slug>/` | `sugarcraft/<slug>` | `SugarCraft\<Sub>` | 🔴 | <role> |
```
Status icon: 🔴 planning · 🟡 in progress · 🟢 v1 ready · 🚀 split-out.

### Step 7 — `PROJECT_NAMES.md` entry
Add a naming-decision line (sweet word + functional word + rationale). Avoid sweet words with software meanings (`Cookie`).

### Step 8 — root `README.md`
Bump the library count (currently "46") and add the table row + test-loop snippet entry.

### Step 9 — `docs/index.html` tile
Add a homepage tile to the lib or app grid (match an existing `<a class="lib-tile">` block; icon `img/icons/<slug>.png`).

### Step 10 — `docs/lib/<slug>.html`
Copy `docs/lib/candy-flip.html` and replace title/description/og tags/icon/upstream-link/tag-chips. Canonical URLs: `https://sugarcraft.github.io/lib/<slug>.html`, icon `../img/icons/<slug>.png`.

### Step 11 — icon PNG
Add a 256px-square candy-themed icon at BOTH `media/icons/<slug>.png` and `docs/img/icons/<slug>.png` (the doc page + homepage reference `docs/img/icons/`).

### Step 12 — `.github/workflows/vhs.yml` matrix
Add the slug to the `all=(...)` bash array (~line 143). If `composer.json` declares `ext-ssh2`/`ext-gd`/`ext-ffi`/`ext-pdo_sqlite`, add it to the `extensions:` list (default `mbstring, intl, pcntl, ssh2`). Skip entirely for non-visual libs (note exemption in PR body).

### Step 13 — `codecov.yml` (flag + component)
Add BOTH an `individual_flags` entry and an `individual_components` entry:
```yaml
flag_management:
  individual_flags:
    - name: <slug>
      paths: ["<slug>/src/**"]
      carryforward: true
component_management:
  individual_components:
    - component_id: <slug>
      name: <slug>
      paths:
        - "<slug>/src/**"
      flag_regexes:
        - "^<slug>$"
```

### Step 14 — final verification
Run in order:
```sh
cd <slug> && composer install && vendor/bin/phpunit && cd ..
php tools/check-path-repos.php          # zero gaps
PHP_CS_FIXER_IGNORE_ENV=1 php-cs-fixer fix --diff --allow-risky=yes
composer validate                        # no --strict
```
All four green = ready to ship. PR: branch `ai/<slug>-<short>`, title `<slug>: <summary>`, body ends with `## Test plan` citing test count + suite name. Bundle 2-4 related items. Author `Joe Huss <detain@interserver.net>`.

## Examples

**User says:** "port charmbracelet/glamour as candy-shine"

**Actions taken:**
1. Name confirmed in `PROJECT_NAMES.md`: CandyShine → `candy-shine/` → `sugarcraft/candy-shine` → `SugarCraft\Shine\`.
2. Created `candy-shine/composer.json` (copied `sugar-charts/`, deps `candy-core`+`candy-sprinkles` with path-repos), `phpunit.xml` (testsuite `candy-shine`), `src/Renderer.php` (`final`, `declare(strict_types=1)`, `::new()`, `Mirrors charmbracelet/glamour.TermRenderer`), `README.md`, `CALIBER_LEARNINGS.md`.
3. Ran `php tools/check-path-repos.php --fix` → closure clean.
4. Wired root `composer.json`, `docs/MATCHUPS.md` (🟡 row), `PROJECT_NAMES.md`, root `README.md`, `docs/index.html` tile, `docs/lib/candy-shine.html`, `media/icons/candy-shine.png` + `docs/img/icons/candy-shine.png`, `vhs.yml` `all=(...)`, `codecov.yml` flag+component.
5. `cd candy-shine && composer install && vendor/bin/phpunit` → green.

**Result:** New lib appears on the homepage, gets its own CI matrix run + Codecov badge, and renders its GIF — no manual ci.yml edit needed.

**User says:** "add candy-pty, an FFI PTY binding" (non-visual)

**Actions:** Steps 1-11 + 13 as above; **skip** the `vhs.yml` matrix entry (Step 12) and the `.vhs/` tape. PR body notes: "candy-pty is an FFI syscall wrapper with no `view()` — VHS-exempt."

## Common Issues

- **`php tools/check-path-repos.php` reports `missing path-repo for sugarcraft/<dep> (via <a> → <b>)`:** a transitive dep isn't in `repositories[]`. Run `php tools/check-path-repos.php --fix` to auto-insert, then re-run bare to confirm zero gaps. The checker walks the FULL transitive graph, not just direct requires.
- **`composer validate` errors on `"dev-master"`/`"@dev"`:** only with `--strict`. Drop `--strict` — sibling `@dev`/`dev-master` constraints are EXPECTED pre-1.0.
- **New lib's tests/badge never appear in CI:** `<slug>/phpunit.xml` is missing — `scripts/affected-libs.php` discovers libs by `composer.json` + `phpunit.xml` together. Both must exist.
- **GIF never renders after merge:** the slug isn't in `vhs.yml`'s `all=(...)` array (~line 143). It's hand-maintained — ci.yml auto-discovery does NOT cover vhs.yml.
- **Codecov badge shows `unknown` / lib drops to 0%:** missing `individual_flags` OR `individual_components` entry in `codecov.yml` — both are required, and `flag_regexes` must be `"^<slug>$"` (anchored).
- **`vendor/bin/phpunit` fails locally but CI is green:** stale per-lib `vendor/` (gitignored). Run `composer update` in the lib dir before trusting the failure.
- **`Class not found` / wrong namespace:** verify the `autoload.psr-4` key matches `SugarCraft\<Sub>\` exactly, and that the directory slug, composer pkg, and namespace are consistent. Remember the `candy-core` → `SugarCraft\Core\` quirk.