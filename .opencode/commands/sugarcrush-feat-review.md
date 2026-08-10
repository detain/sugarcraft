---
description: Run the whole-plan audit for the sugar-crush feature remediation plan (crush_feat_plan.md) — independently re-verifies every step against live source, auto-dispatches fixes for anything it finds, and repeats until a clean pass
---

Read the "Final Plan Review" section of `/home/sites/sugarcraft/crush_feat_plan.md` before doing anything else — it defines the audit→fix→re-audit loop this command drives to completion. **You (the session running this command) are the loop driver.** Do not delegate the looping itself to a subagent and do not stop after one round just because an audit pass reported back — this command's job is done only when a round comes back clean, or the round cap below is hit.

**Scope:** $ARGUMENTS

If no arguments are provided: the scope is every step in every Wave (W1 through W4) that currently shows any status other than `not_started` in its respective `.sugar-crush-build/feat-wave-*-progress.json` — i.e. everything that's been attempted, whether or not it claims to be done. This can be run even if the plan isn't fully finished yet, as a spot-check.

If the argument is a Wave ID (e.g. `W2`) or a specific Step ID (e.g. `W2.S3`), scope every round below to just that Wave or step.

## The loop, run automatically, in this session, without waiting for the user to re-invoke the command

Repeat the following, starting at round 1, capped at **10 rounds**:

1. **Audit round.** Spawn a brand-new `sugarcrush-feat-final-reviewer` (via `delegate` — it's read-only) scoped to the current scope. It trusts nothing from any progress-tracking file and re-derives every verdict from `git log`, `git diff`, and actually running the code, exactly the way the original independent audit that produced `crush_code_update.md` worked for the first sugar-crush build. It appends a "verified-clean" or "gap-found" entry to `.sugar-crush-build/feat-audit-log.json` for every step it checks, and reports back a list of findings (empty if none). If this spawn's response fails the Agent Failure Retry protocol's bar for a usable response (see `crush_feat_plan.md`), retry with a fresh `sugarcrush-feat-final-reviewer`, up to 3 attempts, before treating the round itself as blocked and stopping the loop to report to the user.

2. **If the round found zero gaps:** stop here. The scope is genuinely finished. Report this clearly to the user, including the round number this took and confirmation the audit log shows real per-step entries (not just a summary claim) for the whole scope. Do not run another round.

3. **If the round found any gaps:** for **every** finding from this round (not just the first one, not a sample — every single one), dispatch a fix:
   - Spawn `sugarcrush-feat-orchestrator` (or, for a single narrow finding scoped to one step, its Phase Lead machinery directly) with the finding written out as a `<STEP_ID>.audit-fix` task, exactly as `crush_feat_plan.md`'s "Handling audit-fix tasks" section describes. This routes the fix through the normal Builder → fresh Reviewer → Commit loop — same 11-category checklist, same 5-cycle review cap, same Agent Failure Retry protocol as every other step in this plan. Audit-fixes are not a shortcut path with lighter scrutiny.
   - If two or more findings from this round touch overlapping files (check the file lists in each finding — the same file-disjointness rule used everywhere else in this plan applies here), dispatch those specific fixes **serially**, one fully complete before the next starts. Findings with disjoint files may be dispatched concurrently.
   - Wait for every dispatched fix to either land (committed and pushed) or come back blocked. If any fix comes back blocked (5 failed review cycles, or 3 failed agent-response retries), stop the loop immediately, do not start another audit round, and report the block to the user with full detail — an audit-fix that can't converge needs human attention the same way any other blocked step does.
4. Once every fix from this round has landed cleanly: go back to step 1 for a fresh audit round. **Always spawn a brand-new `sugarcrush-feat-final-reviewer` for the next round — never reuse the previous round's auditor or assume its earlier findings are still accurate now that fixes have landed.** A step whose fix was just committed may still have problems the fix didn't fully address; the next round's auditor re-checks it from zero, exactly like every other step, not on the assumption that "we just fixed that."

## If 10 rounds pass without converging

Stop. Do not run an 11th round automatically. Report to the user: how many rounds ran, how many distinct findings were dispatched as fixes across all rounds, and — if the same finding (or a close variant of it) reappeared in more than one round — call that out specifically, since a finding that keeps coming back after being "fixed" multiple times is a sign the underlying fix approach is wrong, not that it just needs one more try. This is exactly the situation this plan's "blocked" handling exists for: a human needs to look at why convergence isn't happening rather than the loop continuing to spend rounds on it indefinitely.

## What "the plan is actually finished" means

Not "every Wave's progress file says done" — that was exactly the claim that turned out to be worthless in the original build (see `crush_feat_plan.md`'s "Lessons Applied" section, Lesson 1). It is: **a `/sugarcrush-feat-review` run whose very first round comes back with zero findings**, with `.sugar-crush-build/feat-audit-log.json` showing real per-step verification entries for the whole scope. A run that needed several rounds to get there is still a legitimate "finished" outcome once it lands on a clean round — the plan doesn't need to have been perfect on the first audit pass, it needs to converge, and this command's job is to drive that convergence to completion in one sitting rather than leaving it as a manual multi-invocation chore.
