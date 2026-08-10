---
description: Start or resume the sugar-crush feature remediation plan (crush_feat_plan.md)
---

Switch to the `sugarcrush-feat-orchestrator` agent and have it read `/home/sites/sugarcraft/crush_feat_plan.md`, starting with the "Execution Protocol for Orchestrated Implementation" section.

**Scope:** $ARGUMENTS

If no arguments are provided: check whether `.sugar-crush-build/feat-plan-progress.json` already exists. If it does not, this is a fresh start — begin with the Wave 0 bootstrap step. If it does exist, this is a resume — read it and continue from whichever Wave is not yet `"done"`, per the orchestrator's own instructions.

If the argument is a Wave ID (e.g. `W2` or `W3`), treat that as an instruction to resume starting from that specific Wave rather than the first not-`"done"` Wave — only do this if the user is intentionally re-running or skipping ahead, since it bypasses the orchestrator's own dependency-order walk (W2 depends on parts of W1 having landed; W3 depends on all of W2 having landed).

The orchestrator will spawn one `sugarcrush-feat-phase-lead` at a time, each of which drives its Wave's steps through the builder → review → fix loop (serially for Wave 2, concurrently where file-disjoint for Waves 1/3/4) and commits directly to master once a step's review is clean, using the git concurrency rule to serialize actual commit/push operations across any concurrently-running tracks. If any spawned agent's response doesn't meet the Agent Failure Retry protocol's bar for a usable response, the orchestrator and its Phase Leads will retry with a fresh agent (up to 3 attempts) before treating it as a block.

**This whole run stays on `master`, always, even with several Wave-1/3/4 tracks building concurrently** — no branches, no PRs, no worktrees, at any tier (Builder, Reviewer, Fixer, or Committer). See `crush_feat_plan.md`'s "The git concurrency rule" for why file-disjointness rather than git isolation is what makes the parallelism safe here, and how each role is guarded against drifting off `master` (instruction-level for Builder/Fixer, hard permission-layer denial for the Committer, and a per-review + per-round branch check for the Reviewer and Plan Auditor).

Report back a summary of what completed and what, if anything, is now blocked. Once every Wave (W1 through W4) reports done, remind the user that `/sugarcrush-feat-review` should be run to perform the whole-plan audit before considering the plan actually finished — a Wave reporting "done" in its own progress file is not, on its own, sufficient evidence per this plan's own stated lessons about self-reported progress tracking.
