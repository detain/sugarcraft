# Step 37 — Catch-all: remaining lib adoptions

**Branch:** `ai/sweep-catchall`
**Depends on:** step-30 (audit), steps 31-36 (specific cohorts handled)
**Blocks:** —

## Goal

Pick up any lib the step-30 audit identified that didn't fit into steps 31-36's themed groupings. Examples might include: candy-log, candy-metrics, candy-mold, sugar-dash (already touched? confirm), super-candy bits not yet migrated, etc. Each gets its smallest beneficial adoption; anything bigger gets pushed to `docs/repo_map_update_followups.md` as a future plan item.

Reference: step-30 audit output — this step is dynamic.

## Files expected to be modified

- Whatever the audit dictates. Update `docs/repo_map_update_followups.md` to mark items as "addressed in step-37" vs "deferred".

## Acceptance criteria

- [ ] Every audit item is either acted on in this step OR explicitly marked "deferred to future plan" in followups.md with rationale.
- [ ] No lib left in limbo (acted on partially, no deferral note).
- [ ] All affected libs' tests pass.
- [ ] ≥95 % coverage maintained on touched libs.
- [ ] `git status` clean on master.

## Coder brief

1. Read step-30 audit + check what's marked for step-37 vs other steps.
2. For each step-37 lib: apply the minimal adoption (e.g., one composer require + a few migration sites). If the migration is larger than expected, append a deferral note to followups.md and skip.
3. Run phpunit + check-path-repos in every touched lib.
4. **If catch-all turns out to be empty** (steps 31-36 covered everything): this step is a NO-OP; return `NO-OP — sweep complete, no remaining libs`.

## Tester brief

- Per touched lib: add minimum test demonstrating the new dep is exercised.
- If NO-OP: no tests.

## Scribe brief

- For each acted-on lib: README + CALIBER_LEARNINGS update.
- Update followups.md to mark items resolved or deferred.

## Ship brief

- **PR title**: `ecosystem sweep catch-all (step-37)`
- **PR body**:
  ```
  ## Summary
  - Picked up remaining libs from step-30 audit that didn't fit into 31-36.
  - Anything too large to fit here was deferred to docs/repo_map_update_followups.md with rationale.
  - <list affected libs here, fill in based on what the coder actually touched>

  ## Test plan
  - [x] vendor/bin/phpunit in each touched lib (≥95% each)
  - [x] followups.md status updated
  - [x] php tools/check-path-repos.php

  Refs: docs/repo_map_step_37.md, docs/repo_map_update_followups.md
  ```
- Commit subject: `ecosystem sweep: catch-all adoption cleanup`.

If NO-OP, ship as: `NO-OP — step-37 catch-all empty after steps 31-36`.
