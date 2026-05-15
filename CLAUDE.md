# SugarCraft

PHP monorepo of 45+ TUI library ports (Charmbracelet ecosystem). PSR-4, PHP 8.3+, PHPUnit 10, ReactPHP event loop.

@./AGENTS.md
@./CONTRIBUTING.md

## Layout

Per-lib skeleton: `<slug>/composer.json` · `<slug>/phpunit.xml` · `<slug>/README.md` · `<slug>/CALIBER_LEARNINGS.md` · `<slug>/src/` (PSR-4 `SugarCraft\<Sub>\`) · `<slug>/tests/` · `<slug>/.vhs/`.

Cross-cuts: `MATCHUPS.md` · `PROJECT_NAMES.md` · `LOCALES.md` · `UPSTREAM_OPPORTUNITIES.md` · `docs/index.html` · `docs/lib/<slug>.html` · `media/icons/<slug>.png` · `scripts/affected-libs.php` · `scripts/bootstrap-org-repos.sh` · `codecov.yml`.

Aux trees: `plans/AUDIT_*.md` (walk top-down, mark `✅`/`⏭️` in place) · `.codenomad/worktreeMap.json` (parallel-worktree ownership) · `.opencode/` (Codex mirror) · `.logs/subtask*.log` · `.sisyphus/`.

**Foundation** (`Candy-`): `candy-core` · `candy-sprinkles` · `candy-shell` · `candy-shine` · `candy-kit` · `candy-freeze` · `candy-wish` · `candy-zone` · `candy-metrics` · `candy-mold` · `candy-tetris` · `candy-log` · `candy-palette` · `candy-lister` · `candy-hermit` · `candy-mines` · `candy-mosaic` · `candy-flip` · `candy-query` · `candy-serve` · `candy-vt` · `candy-vcr` · `candy-pty`.

**Components/apps** (`Sugar-`): `sugar-bits` · `sugar-charts` · `sugar-prompt` · `sugar-glow` · `sugar-spark` · `sugar-skate` · `sugar-stash` · `sugar-table` · `sugar-tick` · `sugar-toast` · `sugar-veil` · `sugar-crumbs` · `sugar-readline` · `sugar-stickers` · `sugar-calendar` · `sugar-boxer` · `sugar-post` · `sugar-wishlist` · `sugar-crush` · `sugar-dash`.

**Physics** (`Honey-`): `honey-bounce` · `honey-flap`. **One-off**: `super-candy`.

## Commands

```sh
cd candy-core && composer install && vendor/bin/phpunit
```

```sh
for d in candy-core candy-sprinkles honey-bounce candy-zone sugar-bits sugar-charts sugar-prompt candy-shell candy-shine candy-kit candy-freeze; do
  (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1
done
```

```sh
cd candy-core && composer validate && vendor/bin/phpunit --coverage-clover=coverage.xml
```

## Conventions

- `declare(strict_types=1);` at top of every file. PSR-12 + PSR-4.
- Public classes `final` unless extension is part of contract.
- **Immutable + fluent** — every `with*()` returns new instance via private `mutate()` helper; public `readonly` properties for state. Canonical: `candy-sprinkles/src/Style.php`.
- Bare-named accessors (no `get` prefix). Factory methods mirror upstream: `Theme::ansi()`, `Spinner::line()`, `Spring::fps(60)`.
- Doc-comment cites upstream: `Mirrors charmbracelet/<repo>.<Method>`.
- Slug → kebab dir → composer pkg → namespace: `CandyShine` → `candy-shine/` → `sugarcraft/candy-shine` → `SugarCraft\Shine\` (quirk: `candy-core` → `SugarCraft\Core\`).

## Adding a library

Reference shapes: `sugar-bits/` (components), `sugar-charts/composer.json` (path-repo closure), `candy-core/phpunit.xml` (test config), `sugar-wishlist/src/Lang.php` (i18n `Lang::t()` wrapper).

Touched: `<slug>/composer.json` · `<slug>/phpunit.xml` · `<slug>/README.md` · `<slug>/CALIBER_LEARNINGS.md` · `<slug>/src/<Class>.php` · root `composer.json` (`require` + `repositories[]`) · `MATCHUPS.md` · `PROJECT_NAMES.md` · `README.md` · `docs/index.html` · `docs/lib/<slug>.html` · `media/icons/<slug>.png` · `.github/workflows/vhs.yml` matrix · `codecov.yml`. `.github/workflows/ci.yml` auto-picks via `scripts/affected-libs.php` — only edit `WINDOWS_LIBS`/`MACOS_LIBS` pools for OS-specific runners.

## PR workflow

Ship-as-you-go: `git commit` → `git push` → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`. Bundle 2–4 related items. Branches: `ai/<slug>-<short>` or `feat/<slug>-<short>`. Author: `Joe Huss <detain@interserver.net>`.

## Gotchas

- `composer validate --strict` flags every `"sugarcraft/*": "@dev"` — EXPECTED; drop `--strict`.
- New transitive `@dev` deps need their path-repo added to every consuming lib's `repositories[]` — copy from `sugar-charts/composer.json`.
- `.github/workflows/vhs.yml` `all=(...)` array hand-maintained. Non-visual libs (`candy-pty`, FFI bindings, codecs) exempt.
- Keep SVN credentials in `.github/workflows/tests.yml` HARDCODED — repo secrets don't exist yet.
- Run sub-agents ONE AT A TIME — concurrent writes to `MATCHUPS.md`/`README.md` collide; check `.codenomad/worktreeMap.json`.
- Pass ALL CLI tool flags every invocation via `escapeshellarg((string)($field ?? ''))`.
- Bash CWD does NOT persist across calls — anchor with absolute paths or chain `&&`.
- After sub-agent failure check `.logs/subtask*.log` + `.sisyphus/` before retrying.

## Session learnings

Root `CALIBER_LEARNINGS.md` + per-lib variants: `candy-core/CALIBER_LEARNINGS.md` · `candy-wish/CALIBER_LEARNINGS.md` · `candy-shell/CALIBER_LEARNINGS.md` · `sugar-bits/CALIBER_LEARNINGS.md` · `candy-zone/CALIBER_LEARNINGS.md`.

## Before committing

Check: `grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"`. If hook-active, commit normally. Otherwise: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ AGENTS.md .agents/ .opencode/`. Run `/setup-caliber` if missing.

## Model

Default: `claude-sonnet-4-6` with high effort. Pin via `/model` or `CALIBER_MODEL`.

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
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ .cursor/ .cursorrules 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
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
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
<!-- /caliber:managed:sync -->
