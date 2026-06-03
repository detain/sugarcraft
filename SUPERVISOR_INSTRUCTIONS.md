# Supervisor Instructions: candy-query Bug Fixes

## Overview

You are the **orchestrator** for 14 bug fixes in the `candy-query` library. Your role is to delegate work to subagents, NOT to investigate or write code directly. You manage the pipeline, track status, and ensure each step is properly reviewed and merged.

---

## Critical Rule: GITHUB_TOKEN

**Before EVERY `gh` CLI command, you MUST run: `unset GITHUB_TOKEN`**

Failure to unset will cause auth failures. This is non-negotiable.

---

## Phase 1: Discovery (Do This First)

1. Read `/home/sites/sugarcraft/query_update.md` — the full 14-step plan
2. Read every file in `/home/sites/sugarcraft/steps/` (A1_PERFSCHEMA_PANE.md through J2_FINAL_REVIEW.md)
3. Create `/home/sites/sugarcraft/updates.md` if it doesn't exist (use the status tracking format below)
4. Understand the dependency order — some steps may be independent and run concurrently

---

## Phase 2: Per-Step Workflow

For each step (A1 → J2):

### Step A: Coder Subagent
```
task tool → subagent_type: coder
prompt: [contents of the step file]
```

The coder implements the fix in `candy-query/`.

### Step B: Reviewer Subagent (if issues found)
```
task tool → subagent_type: reviewer
prompt: "Review the changes in candy-query/ for [issue]. 
Focus on: [specific concerns from the step file].
Report any problems found."
```

- **Loop**: Coder → Reviewer → Coder → Reviewer until clean
- If a BLOCKING issue is found: note it in `updates.md` and move to next non-blocking step

### Step C: TestEngineer Subagent
```
task tool → subagent_type: TestEngineer
prompt: "Write PHPUnit tests for the changes made in step [X] of candy-query.
Tests should cover: [specific test cases from the step file].
Run vendor/bin/phpunit to verify."
```

### Step D: Scribe Subagent
```
task tool → subagent_type: scribe
prompt: "Update documentation for candy-query after step [X] changes.
Update: [specific docs to update from the step file]."
```

### Step E: Commit & Push
```bash
cd /home/sites/sugarcraft
git add -A
git commit -m "[Step X]: [brief description]"
git push origin ai/candy-query-[short]
```

### Step F: Create & Merge PR
```bash
unset GITHUB_TOKEN && gh pr create --title "[Step X]: [description]" --body "## Summary
- [What changed]

## Test plan
- [Test count]"
unset GITHUB_TOKEN && gh pr merge [PR_NUMBER] --merge --delete-branch
```

### Step G: Stay Current
```bash
git checkout master && git pull --ff-only
```

---

## Phase 3: Status Tracking

Maintain `/home/sites/sugarcraft/updates.md`:

```markdown
# candy-query Bug Fix Status

## Overall: IN PROGRESS (X/14 complete)

| Step | Status | Blockers | Notes |
|------|--------|----------|-------|
| A1   | ✅ DONE | -        | - |
| A2   | 🔄 IN PROGRESS | - | - |
| B1   | ⏳ PENDING | - | - |
| ...  | - | - | - |
| J2   | ⏳ PENDING | - | - |

## Blocking Issues
- [List any blocking issues here]

## Last Updated
[ISO timestamp]
```

---

## Phase 4: Final Review

After ALL 14 steps complete:

1. Run a final reviewer pass across entire `candy-query/`
2. Run full test suite: `vendor/bin/phpunit` in candy-query
3. Address any remaining issues
4. Final commit if needed
5. Create final PR with full changelog
6. Merge to master
7. Pull to local master

---

## Step Independence

Some steps may be independent. You may spawn limited concurrency (2-3 steps at once) ONLY when:
- Steps touch different files
- No shared state between them
- Each has its own coder/reviewer/TestEngineer/scribe cycle

Always maintain the commit → PR → merge cadence per step.

---

## Handling Blocking Issues

When a BLOCKING issue is found:
1. Note it in `updates.md` under "Blocking Issues"
2. Document what would be needed to resolve it
3. Move to the next non-blocking step
4. Do NOT halt the entire pipeline

---

## Subagent Reference

| Subagent | Role | Tools Available |
|----------|------|-----------------|
| coder | Implements fixes | read, write, edit, glob, grep, bash (builds/tests) |
| reviewer | Reviews code | read, glob, grep, bash |
| TestEngineer | Writes tests | write, bash (phpunit) |
| scribe | Updates docs | read, write, edit |

---

## Important Reminders

- **YOU DO NOT write code** — only spawn subagents and manage flow
- **Always unset GITHUB_TOKEN** before any gh command
- **Stay on master** after each merge with `git checkout master && git pull --ff-only`
- **Track everything** in updates.md
- **Commit messages** must include step identifier (e.g., "[A1]")
- **PR titles** must include step identifier for traceability

---

## Ship-As-You-Go Cadence

For EACH step after review is clean:
1. `git add -A && git commit -m "[Step X]: [desc]"`
2. `git push origin ai/candy-query-[short]`
3. `unset GITHUB_TOKEN && gh pr create ...`
4. `unset GITHUB_TOKEN && gh pr merge [n] --merge --delete-branch`
5. `git checkout master && git pull --ff-only`

Do NOT batch multiple steps into one PR unless explicitly allowed.
