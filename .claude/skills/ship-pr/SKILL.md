---
name: ship-pr
description: Runs the SugarCraft ship-as-you-go PR cadence for a cohesive change-set: stage → commit (author Joe Huss <detain@interserver.net>) → push → unset GITHUB_TOKEN && gh pr create → gh pr merge <n> --merge --delete-branch → git checkout master && git pull --ff-only. Bundles 2-4 related items per PR. Use when user says 'ship this', 'open PR', 'merge and continue', 'next phase', 'ship as you go', or after a cohesive change-set passes tests. Do NOT use to amend already-merged PRs, push directly to master, force-push, skip pre-commit hooks, or for work that has not passed `vendor/bin/phpunit` for every touched lib.
---
# ship-pr — SugarCraft ship-as-you-go PR cadence

## Critical

- **NEVER push to `master` directly.** Always work on a branch and merge via `gh pr merge`.
- **NEVER `--amend` a commit that is already on a merged PR.** Create a NEW commit on a NEW branch instead.
- **NEVER skip pre-commit hooks** (`--no-verify`). Caliber sync runs there — bypassing it desyncs agent configs.
- **NEVER bundle unrelated work.** One PR = one cohesive change-set of 2–4 related items (e.g. "sugar-bits: i18n + snapshot tests + locale fr"). Multi-lib audit work = one PR per lib/phase, sequenced by dependency order.
- **Tests gate the ship.** Run `composer test` for every touched lib before staging. If anything is red, STOP and fix — do not ship.
- **Commit author MUST be `Joe Huss <detain@interserver.net>`.** Verify with `git config user.email` before the first commit of a session; do NOT change `git config` globally — pass `--author` to `git commit` if the global is wrong.
- **`unset GITHUB_TOKEN` immediately before `gh pr create`.** Project-scoped `GITHUB_TOKEN` env vars override `gh auth` and silently fail or post as the wrong identity.
- **`composer validate --strict` is wrong here** — every `"sugarcraft/*": "@dev"` path-repo sibling trips it. Use `composer validate` (no `--strict`).
- **Bash CWD persists across calls.** Anchor every command with an absolute path or `cd <repo root> && ...`.

## Instructions

### Step 1 — Confirm the change-set is shippable

From the repo root, run:

```sh
git status
git diff --stat
```

List the libs touched (top-level dirs like `candy-core/`, `sugar-bits/`). Hold this list — Step 2 reuses it. Confirm the bundle is 2–4 related items. If it sprawls across unrelated libs, STOP and split into separate PRs (run this skill once per coherent slice). Verify: `git status` shows changes only in the intended files.

### Step 2 — Run the test suite for every touched lib

For each lib `<slug>` from Step 1:

```sh
cd <slug> && composer install --quiet && composer test
```

Also run reverse-dependents if a foundation lib (`candy-core`, `candy-sprinkles`, `candy-shell`) changed — at minimum re-run `sugar-bits`, `sugar-charts`, `candy-shine`.

Verify: every suite ends with `OK (...)`. If ANY suite fails or shows warnings (PHPUnit configs use `failOnWarning="true"`), STOP. Do not proceed to commit.

### Step 3 — Update cross-cut docs if the API surface or lib roster changed

- If a new lib was added or upstream parity changed, update `MATCHUPS.md` row + status icon (🔴🟡🟢🚀).
- If a new lib was added, also verify both CI workflow matrices include it (hand-maintained, not glob-driven).
- If public API visibly changed, update the lib's `README.md` quickstart and the root `README.md` table row.
- If this is audit work, mark items ✅ inline in the audit file with a one-line summary — do not move them.

Verify: `git status` reflects the doc updates.

### Step 4 — Check Caliber pre-commit hook

From the repo root:

```sh
grep -q "caliber" .git/hooks/pre-commit 2>/dev/null && echo "hook-active" || echo "no-hook"
```

- If `hook-active`: do nothing — the hook will run `caliber refresh` on commit. Tell the user: "Caliber will sync agent configs automatically via the pre-commit hook."
- If `no-hook`: tell the user "Caliber: Syncing agent configs with your latest changes..." then run:

  ```sh
  caliber refresh && git add CLAUDE.md .claude/ .cursor/ .cursorrules .github/instructions/ AGENTS.md CALIBER_LEARNINGS.md .agents/ .opencode/ 2>/dev/null
  ```

- If `caliber` is not found: read `.agents/skills/setup-caliber/SKILL.md` and follow it before proceeding.

Verify: hook present OR caliber sync completed without error.

### Step 5 — Create the branch

Branch name format: `ai/<slug>-<short>` for AI-driven work, `feat/<slug>-<short>` for human work. `<short>` is kebab-case, ≤4 words.

```sh
git checkout -b ai/<slug>-<short>
```

If already on a feature branch from a prior step, skip creation — confirm with `git branch --show-current`.

### Step 6 — Stage with explicit paths, never `git add -A`

Stage only the files Step 1 enumerated, plus Caliber-managed files from Step 4 if `no-hook`:

```sh
git add <path1> <path2> ...
```

Never run `git add -A` or `git add .` — pulls in `.env`, vendor dirs, `.phpunit.cache/`, scratch files. Re-run `git status` and confirm only intended files are staged. Verify: `git diff --cached --stat` matches the change-set scope.

### Step 7 — Commit with the canonical author + message format

Title format: `<lib>: <summary>` or `<lib>: <feature> (audit #N)` — e.g. `sugar-bits: add i18n locale fr + snapshot tests (audit #12)`.

Body ends with `## Test plan` section citing test count + suite name(s).

Use a heredoc to preserve formatting and pass `--author` to guarantee the correct identity even if `git config user.email` is wrong:

```sh
git commit --author="Joe Huss <detain@interserver.net>" -m "$(cat <<'EOF'
<lib>: <summary>

<1–3 bullets describing the bundled items>

## Test plan
- composer test in <slug>: <N> tests, all green
EOF
)"
```

If the pre-commit hook (Caliber) modifies files, the commit fails. Fix: re-stage the updated files and create a NEW commit (never `--amend` to bypass the hook). Verify: `git log -1 --pretty=fuller` shows author `Joe Huss <detain@interserver.net>`.

### Step 8 — Push the branch

```sh
git push -u origin ai/<slug>-<short>
```

Verify: push succeeds, prints the GitHub URL.

### Step 9 — Create the PR with `gh`

**Unset `GITHUB_TOKEN` in the same command** to prevent overriding `gh auth`:

```sh
unset GITHUB_TOKEN && gh pr create --title "<lib>: <summary>" --body "$(cat <<'EOF'
## Summary
- <bullet 1>
- <bullet 2>

## Test plan
- [x] composer test in <slug> (<N> tests)
- [x] composer test in <reverse-dep> if foundation lib touched

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

Capture the PR number from stdout (URL ends in `/pull/<N>`). Verify: `gh pr view <N>` shows the PR with the expected title + body.

### Step 10 — Merge with `--merge --delete-branch`

```sh
gh pr merge <N> --merge --delete-branch
```

`--merge` (not `--squash` or `--rebase`) preserves the commit on `master`. `--delete-branch` removes the remote feature branch. If CI is still running, `gh pr merge` will queue if branch protection requires checks — wait for the queue to drain or use `--auto` if branch protection allows auto-merge. NEVER force-merge a red PR. Verify: `gh pr view <N>` shows `MERGED`.

### Step 11 — Return to master and fast-forward

```sh
git checkout master && git pull --ff-only
git branch -d ai/<slug>-<short>
```

`--ff-only` ensures we never accidentally merge local commits onto master from the feature branch. Verify: `git status` shows `On branch master, Your branch is up to date with 'origin/master'`. Done — next change-set may begin.

## Examples

### Example 1 — single-lib bundled audit slice

User says: "ship the sugar-bits i18n work"

Actions taken:

1. `git status` shows `sugar-bits/src/Lang.php`, `sugar-bits/lang/fr.php`, a new sugar-bits test file, and `MATCHUPS.md`. → 4 files, 1 lib, coherent bundle ✓
2. `cd sugar-bits && composer install --quiet && composer test` → `OK (47 tests, 112 assertions)` ✓
3. `grep -q "caliber" .git/hooks/pre-commit` → `hook-active` ✓
4. `git checkout -b ai/sugar-bits-i18n-fr`
5. `git add sugar-bits/src/Lang.php sugar-bits/lang/fr.php sugar-bits/tests/ MATCHUPS.md`
6. `git commit --author="Joe Huss <detain@interserver.net>" -m "sugar-bits: add fr locale + Lang tests (audit #18) ..."`
7. `git push -u origin ai/sugar-bits-i18n-fr` → branch live
8. `unset GITHUB_TOKEN && gh pr create --title "..." --body "..."` → PR #421
9. `gh pr merge 421 --merge --delete-branch` → MERGED
10. `git checkout master && git pull --ff-only && git branch -d ai/sugar-bits-i18n-fr`

Result: PR #421 merged, master at new HEAD, ready for next change-set.

### Example 2 — foundation lib change requires reverse-dep tests

User says: "ship the candy-core Width util fix"

Actions taken:

1. `git status` shows changes under `candy-core/src/` + tests. Touches foundation → reverse-dep test required.
2. `composer test` in `candy-core`, `sugar-bits`, `sugar-charts`, `candy-shine` → all green ✓
3. Branch `ai/candy-core-width-emoji`, commit `candy-core: fix Width on multi-codepoint emoji`, body lists all 4 suites in `## Test plan`.
4. Steps 8–11 proceed normally. Result: PR #422 merged.

## Common Issues

- **`error: GH_TOKEN/GITHUB_TOKEN does not have required scopes`** during `gh pr create`: a project-scoped `GITHUB_TOKEN` is overriding your user `gh auth`. Fix: `unset GITHUB_TOKEN && gh pr create ...` in the same shell line.
- **`fatal: Author identity unknown`** or commits showing the wrong author: `git config user.email` is unset or set to a non-`detain@interserver.net` address. Fix: pass `--author="Joe Huss <detain@interserver.net>"` explicitly to `git commit`. Do NOT mutate global `git config`.
- **Pre-commit hook fails with Caliber errors**: the hook modified tracked agent-config files. Fix: `git status` to see what changed, `git add` those files, then create a NEW commit (do NOT `--amend`).
- **`gh pr merge` errors with `Pull request is not mergeable`**: CI red or merge conflict with `master`. Fix: investigate CI output via `gh pr checks <N>`; if conflict, `git checkout <branch> && git pull origin master && resolve && push`. Never `--force` push to resolve.
- **`composer validate` errors on `"sugarcraft/*": "@dev"`**: only happens with `--strict`. Drop `--strict` — path-repo siblings are expected to fail strict validation before 1.0.
- **`git pull --ff-only` fails with `Not possible to fast-forward`**: someone else merged to master while you were shipping. Fix: `git fetch origin && git reset --hard origin/master` (you already merged your PR; local master is just stale).
- **PHPUnit warning `Test code or tested code did not (only) close its own output buffers`**: PHPUnit configs set `failOnWarning="true"`, so warnings fail the suite. Fix the warning — do not ship around it.
- **New lib's tests never ran in CI after merge**: a CI matrix in `.github/workflows/` is missing the slug. Fix: open a follow-up PR adding both matrix entries (matrices are hand-maintained, not glob-driven).
- **`gh pr create` opens an editor instead of using `--body`**: heredoc was malformed. Fix: ensure `<<'EOF'` (single-quoted) and closing `EOF` is at column 0 with no trailing whitespace.
- **Two PRs touched `MATCHUPS.md` concurrently and one fails to merge**: classic shared-file collision. Fix: rebase the loser onto current master, re-resolve the row, re-run tests, re-push. Going forward, run sub-agents ONE AT A TIME — never parallel for monorepo-wide work.
