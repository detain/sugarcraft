# SugarCraft contributor playbook

PHP monorepo of 40+ TUI library ports (Charmbracelet ecosystem). PSR-4, PHP 8.3+ (PHP 8.4+ for Windows FFI features), PHPUnit 10, ReactPHP. Windows 10 1809+ required for TTY raw mode support.

## Source-of-truth files

- `MATCHUPS.md` — upstream → SugarCraft port mapping (status icons 🔴🟡🟢🚀)
- `PROJECT_NAMES.md` — naming-decision history + prefix cheat sheet
- `LOCALES.md` — i18n locale codes + recommended set
- `CALIBER_LEARNINGS.md` (root + per-lib) — accumulated patterns/gotchas
- `docs/index.html` — public website homepage tile grid
- `media/` — shared icons, profile.png, social-preview.png used by the homepage + social share metadata
- `scripts/` — Dockerfile (CI image) + bootstrap-org-repos.sh (org provisioning)
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`

## Naming

Pick prefix from `PROJECT_NAMES.md` cheat sheet:
- `Candy-` foundation/system/framework (`candy-core`, `candy-shell`, `candy-shine`)
- `Sugar-` components/data/forms/apps (`sugar-bits`, `sugar-prompt`, `sugar-charts`)
- `Honey-` math/physics/motion (`honey-bounce`, `honey-flap`)

Slug → kebab-case dir → composer pkg → namespace: `CandyShine` → `candy-shine` dir → `sugarcraft/candy-shine` → `SugarCraft\Shine`. (`SugarCraft\Core` is the one quirk — runtime shares umbrella name.)

## Lib skeleton

Reference the existing leaf lib `sugar-bits/` for the canonical layout. Each lib carries:

- `composer.json` — package metadata
- `README.md` — composer require + quickstart
- `CALIBER_LEARNINGS.md` — accumulated patterns
- `src/` — PSR-4 source

### `composer.json`

PHP `^8.3`, PHPUnit `^10.5`, `minimum-stability: dev`. Metadata block (after `license`, before `require`): `keywords` (lowercase kebab, include `"sugarcraft"` + upstream Go name like `"bubbletea"`), `homepage: "https://github.com/sugarcraft/<slug>"`, single author `Joe Huss <detain@interserver.net>` role `Maintainer`, `support.{issues,source,docs}`. PSR-4 `"<NS>\\<Sub>\\": "src/"` plus matching test namespace. Sibling deps: `"sugarcraft/<dep>": "@dev"` AND path-repo `{type: path, url: "../<dep>", options: {symlink: true}}` for the FULL transitive closure — copy from `sugar-charts/composer.json`.

### PHPUnit config

Each lib carries its own PHPUnit XML config. `bootstrap="vendor/autoload.php"`, `colors="true"`, `failOnWarning="true"`, `cacheDirectory=".phpunit.cache"`. Source `<include><directory>src</directory></include>`. See `candy-core/phpunit.xml`.

## Code conventions

- `declare(strict_types=1);` top of every file. PSR-12 + PSR-4.
- Public classes `final` unless extension is part of contract.
- **Immutable + fluent**: every `with*()` returns new instance via private `mutate()` helper. Public `readonly` properties for state.
- Bare-named accessors (no `get` prefix). Factory methods mirror upstream: `Theme::ansi()`, `Theme::dracula()`, `Spinner::line()`.
- **Factory naming**: `::new()` is the zero-arg/default root instance. Bare-named factories for variants — `Theme::ansi()`, `Theme::dracula()`, `Spinner::line()`, `Spring::fps(60)`. Do NOT introduce `::create()`, `::make()`, or `::default()` — those are the drift to avoid.
- Doc-comment cites upstream: `Mirrors charmbracelet/<repo>.<Method>`.
- Don't comment what code does — only why (constraints, invariants, links to upstream issues).

## Tests

PHPUnit 10. Every public method needs ≥1 test. Patterns (see `sugar-bits/tests/`, `candy-core/tests/`):
- **Snapshot** — `view()`, assert raw `\x1b[1m`-style SGR bytes. Don't abstract.
- **Behaviour** — drive `update()` with scripted `KeyMsg`/`MouseMsg`, assert `[Model, ?Cmd]` tuple.
- **Coercion** — feed edge cases (negative/oversized index, empty, null), assert clamp/no-op matching upstream.

Stream-write gotcha: don't `ftruncate; rewind;` between writes — slice deltas with `ftell`/`fseek`/`stream_get_contents` (canonical in `candy-core/tests/RendererTest.php`).

```sh
cd candy-core && composer install && vendor/bin/phpunit
```

## VHS demos

Each non-trivial demo gets a `.tape` file under the lib's `.vhs/` dir. CI re-renders to `.gif` via `.github/workflows/vhs.yml` (hand-maintained matrix — silently skips libs not listed). Tape uses `Set Theme "TokyoNight"`, dimensions, `Type "php examples/<demo>.php"`, `Enter`, `Sleep 2s`. Rendered GIF: `https://raw.githubusercontent.com/detain/sugarcraft/master/<slug>/.vhs/<demo>.gif`.

## i18n

Lang files under each lib's `lang/` dir per `LOCALES.md`. Lookup: exact locale → base language → `en` → raw key. Each lib has thin `Lang::t($key, $params)` wrapping `SugarCraft\Core\I18n\T` with namespace baked in (see `sugar-wishlist/src/Lang.php`). App-level overrides via `T::overrideNamespace`.

## Adding a lib — checklist

```
[ ] <slug>/composer.json + README.md + CALIBER_LEARNINGS.md
[ ] <slug>/src/<Class>.php
[ ] composer.json (root)               — repositories + require entry
[ ] .github/workflows/vhs.yml          — matrix lib: entry  (ci.yml auto-discovers via scripts/affected-libs.php)
[ ] scripts/affected-libs.php          — only if lib needs Windows/macOS runners; add to WINDOWS_LIBS / MACOS_LIBS
[ ] MATCHUPS.md                        — new row + status icon
[ ] PROJECT_NAMES.md                   — naming entry
[ ] README.md (root)                   — library count, table row, test-loop snippet
[ ] docs/index.html                    — homepage tile
[ ] media/ or docs/img/icons/          — 256-square candy-themed PNG
```

## PR workflow

- **Branches**: `ai/<slug>-<short>` (AI-driven), `feat/<slug>-<short>` (humans).
- **Title**: `<lib>: <summary>` or `<lib>: <feature> (audit #N)`.
- **Body** ends with `## Test plan` citing test count + suite name.
- **Bundle 2–4 related items per PR** — one-feature-per-PR is too much churn.
- **Multi-lib audit work**: split into one PR per lib/phase, sequenced by dependency order. Commit → push → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only` → next phase.
- Author: `Joe Huss <detain@interserver.net>`. Don't skip pre-commit hooks.

## Audit-driven PRs

When working through `AUDIT_*.md` files: mark items ✅ inline with one-line summary right where they live (don't move them — readers see history in place). Skip audit items for "credit upstream author" — out of scope pre-1.0.

## Release / split-out

At v1.0: tag monorepo `<slug>-v1.0.0`, `git filter-repo` into `github.com/sugarcraft/<slug>`, publish to Packagist as `sugarcraft/<slug>`, bump `MATCHUPS.md` row to 🚀, replace path-repo in root `composer.json` with Packagist constraint. All commits land in `detain/sugarcraft`; per-lib repos auto-distributed by `sync-sugarcraft.yml`.

## Gotchas

- `composer validate --strict` flags every `"sugarcraft/*": "@dev"` — EXPECTED for path-repos pre-1.0. Drop `--strict`.
- New transitive `@dev` deps need their path-repo added to every consuming lib's `composer.json` `repositories` array.
- `.github/workflows/ci.yml` matrices are dynamic — computed by `scripts/affected-libs.php` from filesystem + reverse-dep graph in `composer.json`. Adding a lib needs only `composer.json` + `phpunit.xml`. `.github/workflows/vhs.yml` is still hand-maintained — its `all=(...)` array must be updated or the GIF never re-renders.
- Run sub-agents ONE AT A TIME, never in parallel — burns extra-usage budget, concurrent writes to shared files like `MATCHUPS.md` collide.
- Keep SVN credentials in `.github/workflows/tests.yml` HARDCODED — secrets don't exist in repo settings yet.
- When wrapping external CLI tools, pass ALL flags every invocation using `escapeshellarg((string)($field ?? ''))` so `null`/`''` render as `''` rather than dropping the flag.
- Bash tool's working directory persists across calls — anchor with absolute paths or `cd /home/sites/sugarcraft && ...` to avoid silent empty reads.

<!-- caliber:managed:pre-commit -->
## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/copilot-instructions.md .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, read `.agents/skills/setup-caliber/SKILL.md` and follow its instructions to install Caliber.
<!-- /caliber:managed:pre-commit -->

<!-- caliber:managed:learnings -->
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
<!-- /caliber:managed:learnings -->

<!-- caliber:managed:model-config -->
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

<!-- /caliber:managed:model-config -->

<!-- caliber:managed:sync -->
## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, read `.agents/skills/setup-caliber/SKILL.md` and follow the setup instructions.
<!-- /caliber:managed:sync -->
