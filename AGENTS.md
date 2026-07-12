# SugarCraft contributor playbook

PHP monorepo of 52 TUI library ports (Charmbracelet ecosystem). PSR-4, PHP 8.3+ (8.4+ for Windows FFI), PHPUnit 10, ReactPHP. Each lib has its own `composer.json` + `vendor/` wired via path repositories.

## Source-of-truth files

- `MATCHUPS.md` — upstream → SugarCraft port mapping (🔴🟡🟢🚀)
- `PROJECT_NAMES.md` — naming rulebook + prefix cheat sheet
- `LOCALES.md` — i18n locale codes
- `CALIBER_LEARNINGS.md` (root + per-lib) — accumulated patterns/gotchas
- `docs/index.html` — public site tiles · `docs/lib/<slug>.html` — per-lib pages **generated** by `tools/gen-docs.php` from `docs/_data/<slug>.{json,body.html}` (never hand-edit)
- `scripts/affected-libs.php` — dynamic CI matrix · `tools/check-path-repos.php` — closure checker
- `CONTRIBUTING.md`, `CODE_OF_CONDUCT.md`, `SECURITY.md`, `codecov.yml`, `.php-cs-fixer.dist.php`

## Naming

`Candy-` foundation/system, `Sugar-` components/data/apps, `Honey-` math/physics. Slug → kebab dir → composer pkg → namespace: `CandyShine` → `candy-shine/` → `sugarcraft/candy-shine` → `SugarCraft\Shine\` (quirk: `candy-core` → `SugarCraft\Core\`). Foundation `candy-forms` is the form-primitives base that `sugar-bits` + `candy-shell` depend on.

## Lib skeleton & composer.json

Reference `sugar-bits/` (components), `sugar-charts/composer.json` (path-repo closure), `candy-core/phpunit.xml` (test config), `candy-pty/src/Lang.php` (i18n wrapper). composer.json: PHP `^8.3`, PHPUnit `^10.5`, `minimum-stability: dev`, `prefer-stable: true`. Metadata block (after `license`): `keywords` (include `"sugarcraft"` + upstream Go name), `homepage`, single author `Joe Huss <detain@interserver.net>` role `Maintainer`, `support.{issues,source,docs}`. Sibling deps need `require` entry AND a `{type: path, url: "../<dep>", options:{symlink:true}}` repo for the FULL transitive closure.

PHPUnit XML: `bootstrap="vendor/autoload.php"`, `colors="true"`, `failOnWarning="true"`, `cacheDirectory=".phpunit.cache"`, source `<include><directory>src</directory></include>`.

## Code conventions

- `declare(strict_types=1);` first line. PSR-12 + PSR-4. Public classes `final` unless extension is contract.
- **Immutable + fluent**: every `with*()` returns a new instance via private `mutate()` (canonical `candy-sprinkles/src/Style.php`; trait `candy-core/src/Concerns/Mutable.php`). Nullable fields use a paired `bool $XSet` sentinel.
- Bare accessors (no `get`). Factories mirror upstream — `::new()` default, `Theme::ansi()`, `Spinner::line()`; never `::create()`/`::make()`/`::default()`.
- Doc-comment cites `Mirrors charmbracelet/<repo>.<Method>`. Comment WHY not WHAT.
- i18n: `Lang::t($key,$params)` over `SugarCraft\Core\I18n\T`; lookup exact → base → `en` → raw.

## Tests

PHPUnit 10, every public method ≥1 test. Snapshot byte (`view()` → raw SGR), cell-grid (`SugarCraft\Vt\Terminal`), behaviour (`update()` → `[Model,?Cmd]`), coercion (clamp edge cases). Stream-write: slice deltas with `ftell`/`fseek`/`stream_get_contents`, never `ftruncate;rewind;` (canonical `candy-core/tests/RendererTest.php`). FFI tests gate on `requirePtySyscalls()`. `candy-testing` provides `ProgramSimulator`, `ScriptedInput`, golden-file + tape-recorder helpers for TEA programs.

```sh
cd candy-core && composer install && vendor/bin/phpunit
```

## VHS demos

`.tape` under `<slug>/.vhs/`; `Set Theme "TokyoNight"`, quote ALL values, `Type "php examples/<demo>.php"`. CI re-renders via `.github/workflows/vhs.yml` (hand-maintained `all=(...)` array). Non-visual libs exempt. Don't commit GIFs.

## Adding a lib — checklist

`<slug>/composer.json`+`phpunit.xml`+`README.md`+`CALIBER_LEARNINGS.md`+`src/<Class>.php` · root `composer.json` (require + repositories) · `MATCHUPS.md` · `PROJECT_NAMES.md` · root `README.md` · `docs/index.html` · `docs/_data/<slug>.json`+`docs/_data/<slug>.body.html` then `php tools/gen-docs.php` (regenerates `docs/lib/<slug>.html` — do NOT hand-edit the page) · `media/icons/<slug>.png` · `.github/workflows/vhs.yml` · `codecov.yml`. `ci.yml` auto-discovers via `scripts/affected-libs.php` — only edit `WINDOWS_LIBS`/`MACOS_LIBS` for OS-specific runners.

## PR workflow

Branches `ai/<slug>-<short>` (AI) / `feat/<slug>-<short>` (human). Title `<lib>: <summary>`. Body ends with `## Test plan` citing test count. Bundle 2-4 items. Ship-as-you-go: commit → push → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`. Author `Joe Huss <detain@interserver.net>`.

## Audit-driven PRs

Mark `plans/AUDIT_*.md` items ✅ inline where they live; skip `credit upstream author`/UPGRADE_GUIDE (out of scope pre-1.0); one PR per lib in dependency order.

## Gotchas

- `composer validate --strict` flags every `"sugarcraft/*": "@dev"` — EXPECTED; drop `--strict`.
- New transitive `@dev` deps need their path-repo in EVERY consuming `repositories[]`.
- `vhs.yml` `all=(...)` array hand-maintained; `ci.yml` dynamic via `scripts/affected-libs.php`.
- Keep SVN creds in `.github/workflows/tests.yml` HARDCODED — repo secrets don't exist yet.
- Run sub-agents ONE AT A TIME — concurrent writes to `MATCHUPS.md`/`README.md` collide.
- Pass ALL external-CLI flags every invocation via `escapeshellarg((string)($field ?? ''))`.
- Bash CWD does NOT persist across calls — anchor with absolute paths or chain `&&`.

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
