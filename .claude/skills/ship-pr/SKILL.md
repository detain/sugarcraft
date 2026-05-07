---
name: ship-pr
description: Runs the SugarCraft ship-as-you-go PR cadence for a cohesive change-set: stage → commit (author Joe Huss <detain@interserver.net>) → push → unset GITHUB_TOKEN && gh pr create → gh pr merge --merge --delete-branch → git checkout master && git pull --ff-only. Bundles 2-4 related items per PR. Use when user says 'ship this', 'open PR', 'merge and continue', 'next phase'. Do NOT use to amend already-merged PRs, push directly to master, or for work-in-progress that hasn't passed `vendor/bin/phpunit`.
---
# ship-pr — SugarCraft ship-as-you-go PR cadence

## Critical

- **Author MUST be `Joe Huss <detain@interserver.net>`.** CI infrastructure (sync-sugarcraft.yml, per-lib downstream pushes) keys off this. Never let `git config user.email` default to anything else for a maintainer-driven PR.
- **Never push to `master` directly.** Always branch → PR → merge. Even one-line fixes.
- **Always `unset GITHUB_TOKEN` before `gh pr create`/`gh pr merge`.** A stale env token shadows `gh auth` and produces 401s or wrong-account PRs.
- **Merge strategy is `--merge` (merge commit), not `--squash`.** Auto-merge is NOT enabled on the repo — you must call `gh pr merge` explicitly after the PR is open.
- **Bundle 2–4 related items per PR.** One-feature-per-PR produces churn the maintainer has explicitly rejected. Mix domains where it makes sense (one lib feature + a website polish + an audit row tick).
- **Tests must pass before pushing.** Run the per-lib PHPUnit binary in every touched lib and abort the ship if any suite is red.
- **Skip credit audit items.** Out of scope for pre-1.0; drop them rather than shipping them.
- **Do not amend or force-push** unless the user explicitly asks. If a hook fails, fix the issue and create a NEW commit.

## Instructions

### Step 1 — Confirm the change-set is shippable

1. `git status` and `git diff` — confirm the working tree only contains the cohesive change-set you intend to ship. If unrelated files are dirty, stash them or ask the user.
2. For every touched lib, run from its root:

   ```sh
   cd sugar-bits && composer install --quiet && vendor/bin/phpunit
   ```

   Abort if any suite is red. Do not "ship and fix in a follow-up."
3. If the change-set touches a lib's public surface, confirm the four cross-cut docs are in sync: `MATCHUPS.md`, `PROJECT_NAMES.md` (only if naming changed), `CONVERSION.md`, and `README.md` table.
4. If the change-set adds a NEW lib, also confirm: root `composer.json` `require` + `repositories`, `.github/workflows/ci.yml` matrix entry, `.github/workflows/vhs.yml` matrix entry, `docs/index.html` tile, per-lib detail page under `docs/`, plus icon under `media/` or `docs/img/icons/`.
5. Verify item count: 2–4 cohesive items. If only 1, ask the user whether to bundle in another small item before shipping.

**Verify before Step 2:** all touched suites green, working tree contains only the intended change-set.

### Step 2 — Branch + commit

1. Branch name format:
   - AI-driven: `ai/<slug>-<short-kebab>` (e.g. `ai/sugar-bits-spinner-themes`)
   - Audit-driven covering multiple libs: `ai/audit-<short>` (e.g. `ai/audit-2026-05-07-batch`)
   - Human contributor: `feat/<slug>-<short>` / `fix/<slug>-<short>` / `docs/<slug>-<short>`
2. Create the branch off the current `master`:

   ```sh
   git checkout -b ai/sugar-bits-spinner-themes
   ```

3. Stage explicit paths (never `git add -A` / `git add .` — root may contain `.agents/`, `.caliber/`, `.claude/` that must not ship):

   ```sh
   git add sugar-bits/src/Spinner.php sugar-bits/tests/SpinnerTest.php sugar-bits/README.md MATCHUPS.md
   ```

4. Commit with explicit author + HEREDOC body. The author flag is non-negotiable:

   ```sh
   git commit --author="Joe Huss <detain@interserver.net>" -m "$(cat <<'EOF'
   sugar-bits: spinner themes (audit #41)

   - bullet 1 — what shipped
   - bullet 2 — what shipped
   - bullet 3 — what shipped (2–4 items total)

   Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
   EOF
   )"
   ```

5. Confirm the author landed:

   ```sh
   git log -1 --format='%an <%ae>'
   ```

   Must print `Joe Huss <detain@interserver.net>`. If not, amend with `git commit --amend --author=...` (this is the one allowed amend — pre-push, before any PR exists).

**Verify before Step 3:** branch created, commit landed under the correct author, `git status` clean.

### Step 3 — Push + open the PR

1. **Always** prefix `gh` calls with `unset GITHUB_TOKEN` in the same shell command — a stale env token from another tool will silently break auth:

   ```sh
   unset GITHUB_TOKEN && gh auth status
   ```

   If auth is broken, stop and tell the user. Do not paper over with a new token.
2. Push with upstream tracking:

   ```sh
   git push -u origin ai/sugar-bits-spinner-themes
   ```

3. Create the PR. Title format: `<lib>: <short summary> (audit #N)` for audit work, `<lib>: <feature>` for feature work, or `multi: <theme>` when the bundle crosses libs. Keep < 70 chars.
4. PR body MUST end with a `## Test plan` checklist citing test counts:

   ```sh
   unset GITHUB_TOKEN && gh pr create --base master --title "sugar-bits: spinner themes (audit #41)" --body "$(cat <<'EOF'
   ## Summary
   - <bullet 1>
   - <bullet 2>
   - <bullet 3>

   ## Test plan
   - [x] sugar-bits full suite green (NNN/NNN)
   - [x] candy-core full suite green (NNN/NNN)
   - [x] composer validate clean (per-lib @dev warnings expected)

   🤖 Generated with [Claude Code](https://claude.com/claude-code)
   EOF
   )"
   ```

5. Capture the PR URL the command prints — you'll need the number for Step 4.

**Verify before Step 4:** PR is open, URL is in hand, no auth errors.

### Step 4 — Merge + clean up

1. Merge with merge-commit strategy and delete the remote branch:

   ```sh
   unset GITHUB_TOKEN && gh pr merge 318 --merge --delete-branch
   ```

   Use `--merge` (NOT `--squash`) — the project keeps merge commits. `--delete-branch` cleans up the remote.
2. Return to master and fast-forward:

   ```sh
   git checkout master && git pull --ff-only
   ```

   If `--ff-only` refuses, somebody else pushed concurrently. Stop and tell the user — do not `git pull` (which would create a needless merge) or `git reset --hard`.
3. Delete the local branch:

   ```sh
   git branch -d ai/sugar-bits-spinner-themes
   ```

   Use `-d` (safe), not `-D` (force). If `-d` refuses, the branch wasn't merged into local master yet — re-pull and try again.
4. Confirm clean state: `git status` should show `nothing to commit, working tree clean` and `git log -1 --format=%s` should show the merge commit.

**Verify before next change-set:** local master is at the merge commit, working tree clean, both local and remote branch deleted.

### Step 5 — Continue the cadence

The ship-as-you-go contract: each cohesive change-set = one PR (commit + push + create + merge) before starting the next. Do not stack multiple unmerged branches. Once Step 4 is clean, you may begin the next change-set.

## Examples

### Example 1 — bundled audit batch across two libs

**User says:** "Ship the spinner-themes work and the sugar-glow ANSI fix."

**Actions taken:**

```sh
# Step 1 — verify
git status
cd sugar-bits && composer install --quiet && vendor/bin/phpunit   # 260/260 green
cd ../sugar-glow && composer install --quiet && vendor/bin/phpunit   # 84/84 green
cd ..

# Step 2 — branch + commit
git checkout -b ai/spinner-themes-glow-ansi
git add sugar-bits/src/Spinner.php sugar-bits/tests/SpinnerTest.php \
        sugar-glow/src/Glow.php sugar-glow/tests/GlowTest.php \
        MATCHUPS.md AUDIT_2026_05_06.md
git commit --author="Joe Huss <detain@interserver.net>" -m "$(cat <<'EOF'
multi: spinner themes + sugar-glow ANSI clamp (audit #41, #57)

- sugar-bits: add Theme::dracula()/tokyoNight() spinner factories
- sugar-bits: snapshot tests for the new themes (260/260 green)
- sugar-glow: clamp 24-bit color to nearest ANSI when COLORTERM unset
- audit: tick #41 and #57 ✅

Co-Authored-By: Claude Opus 4.7 (1M context) <noreply@anthropic.com>
EOF
)"

# Step 3 — push + PR
unset GITHUB_TOKEN && git push -u origin ai/spinner-themes-glow-ansi
unset GITHUB_TOKEN && gh pr create --base master \
  --title "multi: spinner themes + sugar-glow ANSI clamp (audit #41, #57)" \
  --body "$(cat <<'EOF'
## Summary
- sugar-bits: dracula + tokyoNight spinner themes
- sugar-glow: clamp 24-bit → ANSI when COLORTERM unset
- audit #41 + #57 ticked

## Test plan
- [x] sugar-bits full suite green (260/260)
- [x] sugar-glow full suite green (84/84)
- [x] composer validate clean

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"

# Step 4 — merge + clean
unset GITHUB_TOKEN && gh pr merge 318 --merge --delete-branch
git checkout master && git pull --ff-only
git branch -d ai/spinner-themes-glow-ansi
```

**Result:** PR #318 merged, master fast-forwarded, ready for the next change-set.

### Example 2 — single-lib feature, user asks to bundle

**User says:** "Ship the candy-shine theme work."

**Action:** This is one item. Ask first:
> "Just the theme work is a single item — the project prefers 2–4 items per PR. Want me to also tick the related audit row in `AUDIT_2026_05_06.md` and update the candy-shine README badge before shipping?"

Proceed only after the user confirms scope.

## Common Issues

**`gh: error: HTTP 401: Bad credentials` on `gh pr create`:**
1. A stale `GITHUB_TOKEN` env var is shadowing `gh auth`. Re-run with `unset GITHUB_TOKEN && gh ...`.
2. If it still fails: `unset GITHUB_TOKEN && gh auth status` — if logged out, stop and tell the user; do not run `gh auth login` autonomously.

**`fatal: Not possible to fast-forward, aborting.` on `git pull --ff-only`:**
1. Someone (or another agent) pushed to master while your PR was open. Do NOT use `git pull` (creates merge) or `git reset --hard` (loses work).
2. Run `git fetch origin master` and `git log HEAD..origin/master --oneline` to see what landed.
3. Tell the user, then rebase your local master with `git rebase origin/master` only if your local master has no unique commits (it shouldn't after merge — `gh pr merge` lands on the remote, not local).

**`error: The branch 'ai/...' is not fully merged.` on `git branch -d`:**
1. The merge happened on the remote but your local master hasn't pulled it yet. Run `git checkout master && git pull --ff-only` first, then retry `git branch -d`.
2. If still refused after a successful pull, the PR was merged with a strategy that rewrote commits. Use `git branch -D` only after confirming the PR is merged on GitHub (`gh pr view <num> --json state`).

**Commit author shows as wrong identity in `git log -1`:**
1. The `--author` flag was missing. Pre-push, amend: `git commit --amend --author="Joe Huss <detain@interserver.net>" --no-edit`.
2. If the wrong-author commit is already pushed, do NOT force-push. Open the PR as-is and tell the user; let them decide whether to reset+force or rewrite via a new PR.

**`gh pr merge` fails with `Pull request is not mergeable`:**
1. Required checks haven't completed. Run `unset GITHUB_TOKEN && gh pr checks <num>` to see what's pending or failing.
2. If a CI matrix entry is missing for a new lib, that's a Step 1 miss — `.github/workflows/ci.yml` and `.github/workflows/vhs.yml` are hand-maintained, not glob-driven.
3. Wait for checks (`gh pr checks <num> --watch`) rather than merging with `--admin`.

**PHPUnit suite red after a sibling lib changed:**
1. The libs are wired via `composer.json` path repositories — sibling source changes are picked up immediately. Re-run `composer install --quiet` in the failing lib and re-run the per-lib PHPUnit binary.
2. If still red, the sibling change broke the contract. Roll the broken sibling change into your bundle (Step 1, item 5) rather than shipping a partial fix.

**`composer validate` warns about `@dev` constraints:**
1. Expected — every sibling SugarCraft package is required as `@dev` because they live in path repositories. Drop `--strict` from the validate call. Only investigate if the warning is about a non-SugarCraft package.
