# SugarCraft

PHP monorepo of 45+ TUI library ports (Charmbracelet ecosystem + originals). PSR-4, PHP 8.3+, PHPUnit 10, ReactPHP event loop.

@./AGENTS.md
@./CONTRIBUTING.md

## Layout

Each lib at its own dir shares the same skeleton: `composer.json` · `README.md` · `CALIBER_LEARNINGS.md` · `src/` (PSR-4 `SugarCraft\<Sub>\`). Cross-cuts: `MATCHUPS.md` · `PROJECT_NAMES.md` · `LOCALES.md` · `CALIBER_LEARNINGS.md` · `docs/index.html` · `media/` · `scripts/` · `codecov.yml`.

Project-wide auxiliary trees that agents should know about:

- `plans/` — audit prompt drivers like `plans/AUDIT_DOCS_PROMPT.md` (docs pass) and `plans/AUDIT_QUALITY_PROMPT.md` (code quality pass). Walk these top-to-bottom; mark items in place.
- `.opencode/` — cross-tool agent config mirror (`.opencode/memory`, `.opencode/skills`, `.opencode/package.json`) kept in sync by Caliber alongside `.claude/` and `.agents/`.
- `.codenomad/` — parallel-worktree coordination state; `.codenomad/worktreeMap.json` records which worktree owns which lib so sub-agents don't collide on shared files.
- `.logs/` — captured sub-agent run logs (e.g. `.logs/subtask2.log`). Inspect after a failed sub-agent run before retrying.
- `.sisyphus/` — long-running task checkpoints for resumable multi-step jobs.

**Foundation** (`Candy-`): `candy-core` · `candy-sprinkles` · `candy-shell` · `candy-shine` · `candy-kit` · `candy-freeze` · `candy-wish` · `candy-zone` · `candy-metrics` · `candy-mold` · `candy-tetris` · `candy-log` · `candy-palette` · `candy-lister` · `candy-hermit` · `candy-mines` · `candy-mosaic` · `candy-flip` · `candy-query` · `candy-serve` · `candy-vt` · `candy-vcr` · `candy-pty`.

**Components/apps** (`Sugar-`): `sugar-bits` · `sugar-charts` · `sugar-prompt` · `sugar-glow` · `sugar-spark` · `sugar-skate` · `sugar-stash` · `sugar-table` · `sugar-tick` · `sugar-toast` · `sugar-veil` · `sugar-crumbs` · `sugar-readline` · `sugar-stickers` · `sugar-calendar` · `sugar-boxer` · `sugar-post` · `sugar-wishlist` · `sugar-crush` · `sugar-dash`.

**Physics/motion** (`Honey-`): `honey-bounce` · `honey-flap`. **One-off**: `super-candy`.

## Commands

```sh
# Per-lib install + test
cd candy-core && composer install && vendor/bin/phpunit
```

```sh
# Whole monorepo canonical loop
for d in candy-core candy-sprinkles honey-bounce candy-zone sugar-bits \
         sugar-charts sugar-prompt candy-shell candy-shine candy-kit \
         candy-freeze sugar-glow sugar-spark candy-wish sugar-wishlist \
         candy-metrics candy-mold candy-tetris super-candy sugar-crush; do
  (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1
done
```

```sh
# Validate composer.json — drop --strict, every "@dev" sibling is flagged but EXPECTED
cd candy-core && composer validate
```

```sh
# Coverage locally (per-lib)
pecl install pcov && echo 'extension=pcov.so' | sudo tee /etc/php/8.3/cli/conf.d/20-pcov.ini
cd candy-core && vendor/bin/phpunit --coverage-clover=coverage.xml
```

```sh
# Bootstrap sugarcraft org repos (one-shot, idempotent)
gh auth login && ./scripts/bootstrap-org-repos.sh
gh workflow run sync-sugarcraft.yml -R detain/sugarcraft
```

## Conventions

- `declare(strict_types=1);` at top of every file. PSR-12 + PSR-4.
- Public classes `final` unless extension is part of contract.
- **Immutable + fluent**: every `with*()` returns new instance via private `mutate()` helper. Public `readonly` properties for state.
- Bare-named accessors (no `get` prefix). Factory methods mirror upstream: `Theme::ansi()`, `Theme::dracula()`, `Spinner::line()`.
- Doc-comment cites upstream: `Mirrors charmbracelet/<repo>.<Method>`.
- Don't comment what code does — only *why* (constraints, invariants, links to upstream issues).
- Slug → kebab dir → composer pkg → namespace: `CandyShine` → `candy-shine/` → `sugarcraft/candy-shine` → `SugarCraft\Shine\` (quirk: `candy-core` → `SugarCraft\Core\`).

## Adding a library

Follow `AGENTS.md` checklist end-to-end. Touched files: `<slug>/composer.json` · `<slug>/README.md` · `<slug>/CALIBER_LEARNINGS.md` · `<slug>/src/` · root `composer.json` (`require` + `repositories[]`) · `MATCHUPS.md` · `PROJECT_NAMES.md` · `README.md` · `docs/index.html` · `media/icons/<slug>.png` · `.github/workflows/ci.yml` matrix · `.github/workflows/vhs.yml` matrix · `codecov.yml` (flags + components).

Reference leaf libs for shape: `sugar-bits/` (components), `sugar-charts/composer.json` (path-repo closure), `candy-core/` (test config), `sugar-wishlist/src/Lang.php` (i18n wrapper).

## i18n

Locale files keyed per `LOCALES.md` recommended set (`en`, `fr`, `de`, `es`, `pt`, `pt-br`, `zh-cn`, `zh-tw`, `ja`, `ru`, `it`, `ko`, `pl`, `nl`, `tr`, `cs`, `ar`). Lookup chain: exact locale → base language → `en` → raw key. Each lib exposes `Lang::t($key, $params)` wrapping `SugarCraft\Core\I18n\T` (canonical in `sugar-wishlist/src/Lang.php`).

## PR workflow

Ship-as-you-go: commit → push → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only` → next change-set. Bundle 2–4 related items per PR. Multi-lib audit work splits into one PR per lib/phase ordered by dependency. Branches: `ai/<slug>-<short>` (AI) or `feat/<slug>-<short>` (human). Author commits as `Joe Huss <detain@interserver.net>`.

## Gotchas (from `CALIBER_LEARNINGS.md`)

- `composer validate --strict` flags every `"sugarcraft/*": "@dev"` — EXPECTED for path-repos pre-1.0. Drop `--strict`.
- New transitive `@dev` deps need their path-repo added to every consuming lib's `composer.json` `repositories` array. Copy from `sugar-charts/composer.json`.
- `.github/workflows/{ci,vhs}.yml` matrices are **hand-maintained**, NOT glob-driven. Adding a lib without updating both means PHPUnit/GIFs silently never run.
- Skip audit items for "credit upstream author" — out of scope before 1.0.
- Run sub-agents ONE AT A TIME, never in parallel — burns extra-usage budget; concurrent writes to shared files (`MATCHUPS.md`, `README.md`) collide. `.codenomad/worktreeMap.json` records active worktree ownership; check it before launching a parallel run.
- Keep SVN credentials in `.github/workflows/tests.yml` HARDCODED — secrets don't exist in repo settings yet.
- When wrapping external CLI tools, pass ALL flags every invocation using `escapeshellarg((string)($field ?? ''))` so `null`/`''` render as `''`.
- Bash tool's CWD persists across calls — anchor with absolute paths or `cd /home/sites/sugarcraft && ...` to avoid silent empty reads.
- `gh pr create` `Warning: 1 uncommitted change` is informational — the PR still creates. Often Caliber's pre-commit refresh touching `<lib>/CALIBER_LEARNINGS.md` after `git add`.
- Non-visual primitive libs (`candy-pty`, FFI bindings, codecs) are EXEMPT from the `vhs.yml` matrix; call out the exemption in the PR body.
- After a sub-agent failure, check `.logs/` (e.g. `.logs/subtask2.log`) and `.sisyphus/` checkpoints before retrying — resume rather than restart when possible.

## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ AGENTS.md .agents/ .opencode/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."

## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions. Per-lib variants live at `<lib>/CALIBER_LEARNINGS.md` (e.g. `candy-core/CALIBER_LEARNINGS.md`, `candy-wish/CALIBER_LEARNINGS.md`, `sugar-bits/CALIBER_LEARNINGS.md`).

## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort. Pin via `/model` in Claude Code or `CALIBER_MODEL` env var.

## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex (and the `.opencode/` mirror). Configs update automatically before each commit via `caliber refresh`. If the pre-commit hook is not set up, run `/setup-caliber`.
