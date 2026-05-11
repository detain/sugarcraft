# SugarCraft

PHP monorepo of 40+ TUI library ports (Charmbracelet ecosystem). PSR-4, PHP 8.3+, PHPUnit 10, ReactPHP event loop.

@./AGENTS.md
@./CONTRIBUTING.md

## Layout

Each lib at its own directory shares the same skeleton: `composer.json` · `README.md` · `CALIBER_LEARNINGS.md` · `src/` (PSR-4 `SugarCraft\<Sub>\`). Auxiliary trees: `media/` (icons, profile.png, social-preview.png used by the website hero + social share cards) and `scripts/` (Dockerfile for reproducible CI image, bootstrap-org-repos.sh for one-shot org provisioning).

**Libs**: `candy-core` `candy-sprinkles` `candy-shell` `candy-shine` `candy-kit` `candy-freeze` `candy-wish` `candy-zone` `candy-metrics` `candy-mold` `candy-tetris` `candy-log` `candy-palette` `candy-lister` `candy-hermit` `candy-mines` `candy-mosaic` `candy-flip` `candy-query` `candy-serve` `candy-vt` `candy-vcr` `candy-pty` `honey-bounce` `honey-flap` `sugar-bits` `sugar-charts` `sugar-prompt` `sugar-glow` `sugar-spark` `sugar-skate` `sugar-stash` `sugar-table` `sugar-tick` `sugar-toast` `sugar-veil` `sugar-crumbs` `sugar-readline` `sugar-stickers` `sugar-calendar` `sugar-boxer` `sugar-post` `sugar-wishlist` `sugar-crush` `super-candy`.

**Cross-cuts**: `MATCHUPS.md` (upstream→port), `PROJECT_NAMES.md` (naming), `LOCALES.md` (i18n codes), `CALIBER_LEARNINGS.md` (gotchas), `docs/index.html` (website), `media/` (shared icons + social-preview.png + profile.png), `scripts/` (Dockerfile, bootstrap-org-repos.sh).

## Commands

```sh
# Per-lib install + test
cd candy-core && composer install && vendor/bin/phpunit

# Whole monorepo (canonical loop)
for d in candy-core candy-sprinkles honey-bounce candy-zone sugar-bits \
         sugar-charts sugar-prompt candy-shell candy-shine candy-kit \
         candy-freeze sugar-glow sugar-spark candy-wish sugar-wishlist \
         candy-metrics candy-mold candy-tetris super-candy sugar-crush; do
  (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1
done

# Validate composer.json — drop --strict, every "@dev" sibling is flagged but EXPECTED
cd candy-core && composer validate
```

```sh
# Bootstrap org repos (one-shot, idempotent) — uses scripts/bootstrap-org-repos.sh
gh auth login
./scripts/bootstrap-org-repos.sh
gh workflow run sync-sugarcraft.yml -R detain/sugarcraft
```

```sh
# Build the reproducible CI Docker image from scripts/Dockerfile
docker build -f scripts/Dockerfile -t sugarcraft-ci .
```

## Conventions

- `declare(strict_types=1);` at top of every PHP file. PSR-12 + PSR-4.
- Public classes `final` unless extension is part of the contract.
- **Immutable + fluent**: every `with*()` returns a new instance via a private `mutate()` helper. Public `readonly` properties for state.
- Bare-named accessors (no `get` prefix). Factory methods mirror upstream: `Theme::ansi()`, `Spinner::line()`.
- Doc-comment cites upstream: `Mirrors charmbracelet/<repo>.<Method>`.
- Don't add comments restating code — comments document *why*, not *what*.

## Adding a library

Follow `@./AGENTS.md` end-to-end. In short: name (`Candy-`/`Sugar-`/`Honey-` per `PROJECT_NAMES.md`) → scaffold the lib dir → wire root `composer.json` (`require` + `repositories`) → update `MATCHUPS.md`, `README.md` table → add CI matrix entry in BOTH `.github/workflows/ci.yml` AND `.github/workflows/vhs.yml` (hand-maintained, not glob-driven) → website tile in `docs/index.html` plus per-lib detail page under `docs/` plus icon under `media/` (or `docs/img/icons/`).

## i18n

Locale files live under each lib's `lang/` dir keyed per `LOCALES.md`. Lookup chain: exact locale → base language → `en` → raw key. Each lib exposes `Lang::t($key, $params)` wrapping `SugarCraft\Core\I18n\T` (see `sugar-wishlist/src/Lang.php`).

## PR workflow

Ship-as-you-go: commit → push → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only` → next change-set. Bundle 2–4 related items per PR; multi-lib audit work splits into one PR per lib/phase ordered by dependency. Author commits as `Joe Huss <detain@interserver.net>`.

## Gotchas (from `CALIBER_LEARNINGS.md`)

- `composer validate --strict` flags every `"sugarcraft/*": "@dev"` — EXPECTED for path-repos pre-1.0. Drop `--strict`.
- New transitive `@dev` deps need their path-repo added to every consuming lib's `composer.json` `repositories` array. Copy the full block from a working leaf lib (e.g. `sugar-charts/composer.json`).
- Skip audit items for "credit upstream author" — out of scope pre-1.0.
- Run sub-agents ONE AT A TIME, never in parallel — burns extra-usage budget and concurrent writes to shared files like `MATCHUPS.md` collide.
- Keep SVN credentials in `.github/workflows/tests.yml` HARDCODED — secrets don't exist in repo settings yet.
- When wrapping external CLI tools, pass ALL flags every invocation using `escapeshellarg((string)($field ?? ''))` — even empty values render as `''` rather than dropping the flag.
- All commits land in `detain/sugarcraft`; per-lib repos under `github.com/sugarcraft/<slug>` are auto-distributed downstream by `sync-sugarcraft.yml`.

## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ AGENTS.md .agents/ 2>/dev/null`
  3. After it completes, briefly tell the user what Caliber updated. Then proceed with the commit.

**Valid `caliber refresh` options:** `--quiet` (suppress output) and `--dry-run` (preview without writing). Do not pass any other flags — options like `--auto-approve`, `--debug`, or `--force` do not exist and will cause errors.

**`caliber config`** takes no flags — it runs an interactive provider setup. Do not pass `--provider`, `--api-key`, or `--endpoint`.

If `caliber` is not found, tell the user: "This project uses Caliber for agent config sync. Run /setup-caliber to get set up."
## Session Learnings

Read `CALIBER_LEARNINGS.md` for patterns and anti-patterns learned from previous sessions.
These are auto-extracted from real tool usage — treat them as project-specific rules.
## Model Configuration

Recommended default: `claude-sonnet-4-6` with high effort (stronger reasoning; higher cost and latency than smaller models).
Smaller/faster models trade quality for speed and cost — pick what fits the task.
Pin your choice (`/model` in Claude Code, or `CALIBER_MODEL` when using Caliber with an API provider) so upstream default changes do not silently change behavior.

## Context Sync

This project uses [Caliber](https://github.com/caliber-ai-org/ai-setup) to keep AI agent configs in sync across Claude Code, Cursor, Copilot, and Codex.
Configs update automatically before each commit via `caliber refresh`.
If the pre-commit hook is not set up, run `/setup-caliber` to configure everything automatically.
