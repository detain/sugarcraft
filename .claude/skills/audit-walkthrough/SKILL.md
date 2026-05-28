---
name: audit-walkthrough
description: Processes docs/plans/AUDIT_*.md files by marking items ✅/⏭️ inline where they live (never moving them — readers see history in place), skipping 'credit upstream author' and UPGRADE_GUIDE items (out of scope pre-1.0), and splitting multi-lib audits into one PR per lib sequenced by dependency order (candy-core → candy-sprinkles → honey-bounce → leaf libs). Use when the user says 'work through audit', 'process AUDIT_*.md', 'fix audit findings', or names a docs/plans/AUDIT_*.md file. Do NOT bundle multiple libs into one PR, do NOT use for net-new feature work, and do NOT retroactively rewrite or force-push already-merged audit PRs.
paths:
  - docs/plans/AUDIT_*.md
  - plans/AUDIT_*.md
  - **/AUDIT_*.md
---
# Audit Walkthrough

Work through a `docs/plans/AUDIT_*.md` file top-down, marking each item in place and shipping fixes as one PR per library in dependency order. Audit files are prompt templates / checklists (e.g. `AUDIT_QUALITY_PROMPT.md`, `AUDIT_TESTS_PROMPT.md`, `AUDIT_UPSTREAM_PROMPT.md`, `AUDIT_DOCS_PROMPT.md`, `AUDIT_WEBSITE_PROMPT.md`) — your job is to execute the findings, mark them, and ship.

## Critical

- **Mark in place — never move items.** When an item is done, edit the line/row where it already lives. Append `✅` + a one-line summary. For skipped items use `⏭️` + the reason. Readers see history in context; relocating items into a "Completed" section destroys that. (See marked rows in `docs/plans/dash_update_claude.md` for the exact shape.)
- **One PR per library. Never bundle multiple libs into one PR.** A multi-lib audit splits into N PRs, sequenced by dependency order: `candy-core` → `candy-sprinkles` → `honey-bounce` → leaf libs (anything whose `<slug>/composer.json` `require` lists `sugarcraft/*` deps already shipped above it). Within ONE lib, bundle 2–4 related items per PR.
- **Skip these item categories entirely** (out of scope pre-1.0), marking `⏭️ skipped — pre-1.0, out of scope` in place:
  - "credit upstream author" / attribution items
  - `UPGRADE_GUIDE` / migration-guide items
- **Never rewrite or re-open an already-merged audit PR.** If a finding slipped through, open a NEW follow-up PR; never amend/force-push merged history.
- **PRs only — never push to master.** Ship unambiguous fixes; FLAG public-API breaks for the user instead of shipping them.
- **Run sub-agents ONE AT A TIME** — concurrent writes to shared files (`MATCHUPS.md`, `README.md`, the audit file itself) collide.

## Instructions

1. **Locate and read the audit file.** Audit prompts live in `docs/plans/AUDIT_*.md`. Read the WHOLE file with Read (don't Grep a subset — you'll lose ordering and the per-category "Guard rails" / PR-body template). 
   *Verify:* you can name the audit's scope and quote its guard-rails section before proceeding.

2. **Build the lib queue in dependency order.** Bucket every item by `<slug>`. Order buckets `candy-core` → `candy-sprinkles` → `honey-bounce` → leaf libs. A lib is a leaf when its `<slug>/composer.json` `require` only lists `sugarcraft/*` deps already shipped above it. Create a TodoWrite task per `(lib, phase)` bucket so the PR sequence is visible. 
   *Verify:* no lib precedes a lib it depends on; every item is in exactly one bucket or marked skip-eligible. Uses the file from Step 1.

3. **Pre-filter skip-eligible items in one edit pass.** For items matching `credit upstream author`, `attribute upstream`, `UPGRADE_GUIDE`, or pre-1.0 attribution rewording, append `⏭️ skipped — pre-1.0, out of scope` to the original line with Edit. 
   *Verify:* `git diff docs/plans/AUDIT_*.md` shows only `⏭️` additions — no reordering, no deletions.

4. **Process the first lib bucket only.** Triage each remaining finding into (a) unambiguous fix, (b) flag-for-user (breaks public API). Implement (a) in `<slug>/src/` + `<slug>/tests/` following conventions: `declare(strict_types=1);` first line, PSR-12/PSR-4, `final` public classes, immutable `with*()` via `mutate()`, bare accessors, upstream-mirroring factories (`::new()` / `Theme::ansi()`). No architectural rewrites — audit = bug-fix + cleanup. As each item completes, append `✅ <verb> <what> (<file:line or test method>)` to its original line. 
   *Verify:* each `✅` sits on the original line; collect (b) items for the PR body.

5. **Run the lib's test suite before marking done.**
   ```sh
   cd <slug> && composer install && vendor/bin/phpunit
   ```
   If phpunit fails on a `sugarcraft/*` dep that looks correct, the local `vendor/`/`composer.lock` is stale (gitignored, CI unaffected) — run `composer update` then re-run before trusting the failure. If a transitive `@dev` dep won't resolve, copy the full `repositories` block from `sugar-charts/composer.json`. 
   *Verify:* PHPUnit reports `OK` with a non-zero count; capture count + suite name for the PR body.

6. **Sync Caliber if no hook.**
   ```sh
   grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
   ```
   `hook-active` → commit normally. `no-hook` → `caliber refresh && git add CLAUDE.md .claude/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null` first. Never `--no-verify`.

7. **Ship one PR for this lib (ship-as-you-go).**
   ```sh
   git checkout -b ai/<slug>-audit-<short>
   git add <slug>/ docs/plans/AUDIT_<...>.md
   git commit -m "<slug>: <summary> (audit #N)" --author "Joe Huss <detain@interserver.net>"
   git push -u origin ai/<slug>-audit-<short>
   unset GITHUB_TOKEN && gh pr create --title "<slug>: <summary> (audit #N)" --body "<see template>"
   gh pr merge <n> --merge --delete-branch
   git checkout master && git pull --ff-only
   ```
   `unset GITHUB_TOKEN` is mandatory before every `gh` call — otherwise `gh` uses the project bot token and `gh pr create` 401s. PR body MUST end with a `## Test plan` citing the phpunit count + suite from Step 5. 
   *Verify:* PR merged, branch deleted, local master fast-forwarded.

8. **Report flagged items, then advance the queue.** List flag-for-user items (location, finding, why it can't be fixed unambiguously). Move to the next lib in the Step-2 queue and repeat from Step 4. Stop and wait if the audit's guard rails say "stop after each PR." 
   *Final pass:* every audit item carries `✅` or `⏭️`, nothing was relocated.

## PR body template

```
## Summary

<audit-category> audit for `<slug>`:

Fixed (unambiguous):
- <bullets>

Flagged for user decision:
- <bullets — these would touch the public API>

## Test plan

- [x] cd <slug> && vendor/bin/phpunit  (N tests, all passing)
```

## Examples

**User says:** "Work through `docs/plans/AUDIT_QUALITY_PROMPT.md`."

**Actions taken:**
1. Read the file — eight-category quality sweep; guard rail "stop after each PR, never break public API silently."
2. Touches `candy-core` (6), `sugar-bits` (5), `sugar-charts` (4). Queue: `candy-core` → `sugar-bits` → `sugar-charts` (charts requires core + bits).
3. Pre-filtered 2 "credit upstream author" items → `⏭️ skipped — pre-1.0, out of scope` in place.
4. `candy-core` Cat A: 3 missing `declare(strict_types=1);` (fix), 1 `==`-on-`mixed` (flag — type-widening on public surface). Added the declares; appended `✅ added declare(strict_types=1) (candy-core/src/Util/Width.php:1)` etc. to each original line.
5. `cd candy-core && composer install && vendor/bin/phpunit` → 142 tests OK.
6. Branch `ai/candy-core-audit-quality` → commit (author Joe Huss) → push → `unset GITHUB_TOKEN && gh pr create` → `gh pr merge --merge --delete-branch` → `git checkout master && git pull --ff-only`.
7. Reported the `==`/type-widening item as flagged-for-user. Advanced to `sugar-bits`.

**Result:** One merged PR per lib in dependency order, audit file marked in place, API-breaking item surfaced for the user, credit items skipped.

**Inline marker shape** — correct:
```
- [ ] sugar-bits: Model::update() should clamp negative cursor index to 0 ✅ added clamp + test (sugar-bits/src/Model.php:88, ::test_negative_cursor_clamps_to_zero)
```
Wrong (moved into a new section — DON'T):
```
## Completed
- [x] sugar-bits: clamp negative cursor — DONE
```

## Common Issues

- **`gh pr create` returns `HTTP 401` / `Bad credentials`:** the project bot token leaked into the env. Run `unset GITHUB_TOKEN && gh pr create ...` in the same shell chain.
- **`vendor/bin/phpunit` fails on a `sugarcraft/*` dep that looks correct:** local `vendor/`/`composer.lock` is stale (gitignored; CI unaffected). `cd <slug> && composer update` then re-run. Only treat the failure as real afterward.
- **`composer install` errors `Could not find package sugarcraft/<dep> matching @dev`:** the consuming lib's `composer.json` `repositories` array is missing a path-repo. Copy the full block from `sugar-charts/composer.json`, paste in, re-run.
- **`composer validate` complains about every `"sugarcraft/*": "@dev"`:** expected for path-repos pre-1.0. Drop `--strict`.
- **PR title rejected / linter wants `<lib>:` prefix:** title MUST start with the slug + colon, e.g. `sugar-bits: clamp negative cursor (audit #7)`. Fix with `gh pr edit <n> --title "..."`.
- **Reviewer says the PR mixes two libs:** you bundled across libs. Split into one `ai/<slug>-audit-<short>` branch/PR each, sequenced candy-core → candy-sprinkles → honey-bounce → leaf.
- **You moved audit items into a 'Completed' section:** revert (`git checkout -- docs/plans/AUDIT_*.md`) and re-apply only inline `✅`/`⏭️` markers on the original lines.
- **Pre-commit hook fails:** fix the underlying issue and create a NEW commit — never `--no-verify`, never `--amend` a commit whose hook failed. If Caliber is missing, run `/setup-caliber`.
- **A finding would rename a public method (`getX()` → `x()`):** do NOT ship it. Add to "Flagged for user decision" in the PR body; breaking changes need explicit approval.