---
name: worktree-workflow
description: Walks a teammate through claiming a task, creating its worktree, and opening the merge-back PR per the ship-as-you-go cadence. Use when a teammate says 'claim task', 'create worktree', 'open PR', or 'start work on <slug>'. Keeps the worktree lifecycle consistent across all teammates.
license: MIT
metadata:
  author: sugar-crush-team
  version: "1.0.0"
  phase: P7
  step: P7.S17
---

# Worktree Workflow

Guides a teammate through the full lifecycle of a isolated work branch using `git worktree`. Keeps the worktree lifecycle consistent so no two teammates improvise the git steps differently.

## When to Use

- A teammate says "claim task", "I'm working on <slug>", or "start worktree"
- A teammate is about to begin a feature or port that may conflict with parallel work
- Before opening a merge-back PR for any agent-authored change

## Step-by-Step

### 1. Claim the Task

Before creating any worktree, announce intent:

```bash
# In the sugar-crush repo, update the phase progress file
# (e.g. .sugar-crush-build/phase-P7-progress.json)
# Set the step status to "in_progress" with your agent handle as owner
```

If using a shared task board, move the card to "In Progress" and assign yourself.

### 2. Create the Worktree

Pick a branch name following the convention:

```
ai/<slug>-<short>   # AI-authored work
feat/<slug>-<short>  # human-authored work
```

Create the worktree from `master`:

```bash
git worktree add ../wt-<slug>-<short> master -b ai/<slug>-<short>
cd ../wt-<slug>-<short>
```

This creates an isolated directory with its own working tree but shares the `.git` object store with the host repo.

### 3. Verify Clean State

```bash
git status
# Expected: "nothing to commit, working tree clean"
# If not clean: git checkout -- . && git clean -fd
```

### 4. Do the Work

Implement the feature or port inside the worktree. Commit changes following the sugar-crush commit convention:

```bash
git add -A
git commit -m "<lib>: <summary>

- <change 1>
- <change 2>

Test plan: vendor/bin/phpunit --testdox"
```

### 5. Push the Branch

```bash
git push -u origin ai/<slug>-<short>
```

### 6. Open the Merge-Back PR

```bash
unset GITHUB_TOKEN && gh pr create \
  --title "<lib>: <summary>" \
  --body "$(cat <<'EOF'
## Summary
<1-3 bullet points describing the change>

## Test plan
- [ ] vendor/bin/phpunit passes for <lib>
- [ ] vendor/bin/phpunit passes for all consuming libs
- [ ] <any additional verification>

Closes #<issue-number>
EOF
)"
```

### 7. Merge (if CI passes)

After CI is green:

```bash
gh pr merge <pr-number> --merge --delete-branch
git checkout master
git pull --ff-only
```

### 8. Remove the Worktree

After merging, clean up:

```bash
git worktree remove ../wt-<slug>-<short
git worktree prune  # cleanup any stale entries
```

## Worktree Management Commands

| Command | Purpose |
|---------|---------|
| `git worktree list` | Show all worktrees and their branch |
| `git worktree add <path> <branch> -b <new-branch>` | Create a new worktree |
| `git worktree remove <path>` | Remove a finished worktree |
| `git worktree prune` | Clean up stale worktree references |

## Collision Prevention

- Never create two worktrees for the same `slug` — check `git worktree list` first
- If a worktree for your task already exists, `cd` to it and continue there — do not create a second
- Always branch from `master`, not from another worktree's branch

## Error Handling

| Situation | Action |
|-----------|--------|
| "fatal: '`<path>`' already exists" | Worktree already exists — `cd` to it |
| "fatal: cannot create worktree: 'master'" | Branch from current HEAD, not master |
| "fatal: 'master' is a branch but is not fully merged" | Use `--force` if you're certain, otherwise check with reviewer |
