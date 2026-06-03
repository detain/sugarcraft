# SugarCraft

PHP monorepo of 51 TUI library ports (Charmbracelet ecosystem). PSR-4, PHP 8.3+, PHPUnit 10, ReactPHP event loop.

@./AGENTS.md
@./CONTRIBUTING.md

## Layout

Per-lib skeleton: `<slug>/composer.json` · `<slug>/phpunit.xml` · `<slug>/README.md` · `<slug>/CALIBER_LEARNINGS.md` · `<slug>/src/` (PSR-4 `SugarCraft\<Sub>\`) · `<slug>/tests/` · `<slug>/.vhs/`.

Cross-cuts: `MATCHUPS.md` · `PROJECT_NAMES.md` · `LOCALES.md` · `docs/index.html` · `docs/lib/<slug>.html` · `media/icons/<slug>.png` · `scripts/affected-libs.php` · `tools/check-path-repos.php` · `codecov.yml` · `.php-cs-fixer.dist.php`.

**Foundation** (`Candy-`): `candy-core` · `candy-ansi` · `candy-buffer` · `candy-async` · `candy-input` · `candy-layout` · `candy-sprinkles` · `candy-forms` · `candy-shell` · `candy-shine` · `candy-kit` · `candy-freeze` · `candy-wish` · `candy-zone` · `candy-mouse` · `candy-metrics` · `candy-log` · `candy-palette` · `candy-lister` · `candy-fuzzy` · `candy-hermit` · `candy-mines` · `candy-mosaic` · `candy-flip` · `candy-query` · `candy-serve` · `candy-vt` · `candy-vcr` · `candy-testing` · `candy-pty` · `candy-crush`.

**Components/apps** (`Sugar-`): `sugar-bits` · `sugar-charts` · `sugar-prompt` · `sugar-glow` · `sugar-spark` · `sugar-skate` · `sugar-stash` · `sugar-table` · `sugar-tick` · `sugar-toast` · `sugar-veil` · `sugar-crumbs` · `sugar-readline` · `sugar-stickers` · `sugar-calendar` · `sugar-boxer` · `sugar-post` · `sugar-wishlist` · `sugar-crush` · `sugar-dash` · `sugar-reel`. **Physics** (`Honey-`): `honey-bounce` · `honey-flap`. **One-off**: `candy-files`.

## Commands

```sh
cd candy-core && composer install && vendor/bin/phpunit
```

```sh
for d in candy-core candy-sprinkles candy-forms honey-bounce candy-zone sugar-bits sugar-charts candy-shell candy-shine; do
  (cd "$d" && composer install --quiet && vendor/bin/phpunit) || exit 1
done
```

```sh
php tools/check-path-repos.php --fix
PHP_CS_FIXER_IGNORE_ENV=1 php-cs-fixer fix --diff --allow-risky=yes
```

## Conventions

- `declare(strict_types=1);` first line. PSR-12 + PSR-4. Public classes `final` unless extension is contract.
- **Immutable + fluent**: every `with*()` returns a new instance via the `mutate()` helper; public `readonly` state. Canonical: `candy-sprinkles/src/Style.php`; trait in `candy-core/src/Concerns/Mutable.php`.
- Bare accessors (no `get`). Factories mirror upstream: `Theme::ansi()`, `Spinner::line()`, `Spring::fps(60)`. `::new()` is the default root — never `::create()`/`::make()`/`::default()`.
- Doc-comment cites upstream: `Mirrors charmbracelet/<repo>.<Method>`.
- Slug→namespace: `candy-shine/` → `sugarcraft/candy-shine` → `SugarCraft\Shine\` (quirk: `candy-core` → `SugarCraft\Core\`).
- i18n: `Lang::t($key,$params)` wraps `SugarCraft\Core\I18n\T` (`candy-pty/src/Lang.php`).
- TEA test harness: `candy-testing` provides `ProgramSimulator`, `ScriptedInput`, `GoldenFile`/`Assertions`, `TapeRecorder` for `Model`/`Cmd` programs.

## PR workflow

Ship-as-you-go: `git commit` → `git push` → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge <n> --merge --delete-branch` → `git checkout master && git pull --ff-only`. Bundle 2-4 related items. Branches `ai/<slug>-<short>`. Author `Joe Huss <detain@interserver.net>`.

## Gotchas

- `composer validate --strict` flags every `"sugarcraft/*": "@dev"` — EXPECTED; drop `--strict`.
- New transitive `@dev` deps need a path-repo in every consuming `repositories[]` — copy `sugar-charts/composer.json`.
- `.github/workflows/vhs.yml` `all=(...)` array hand-maintained; non-visual libs (`candy-pty`, `candy-testing`, FFI, codecs) exempt. `ci.yml` auto-discovers via `scripts/affected-libs.php`.
- Keep SVN creds in `.github/workflows/tests.yml` HARDCODED — repo secrets don't exist yet.
- Run sub-agents ONE AT A TIME — concurrent writes to `MATCHUPS.md`/`README.md` collide.
- Bash CWD does NOT persist across calls — anchor with absolute paths or chain `&&`.
- Per-lib `composer.lock`/`vendor/` go stale — `composer update` before trusting a local phpunit failure.

## Before Committing

**IMPORTANT:** Before every git commit, you MUST ensure Caliber syncs agent configs with the latest code changes.

First, check if the pre-commit hook is already installed:
```bash
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If **hook-active**: the hook handles sync automatically — just commit normally. Tell the user: "Caliber will sync your agent configs automatically via the pre-commit hook."
- If **no-hook**: run Caliber manually before committing:
  1. Tell the user: "Caliber: Syncing agent configs with your latest changes..."
  2. Run: `caliber refresh && git add CALIBER_LEARNINGS.md CLAUDE.md .claude/ .cursor/ .cursorrules AGENTS.md .agents/ .opencode/ 2>/dev/null`
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
