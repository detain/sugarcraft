---
description: Start or resume the candy-code multi-agent orchestration plan (crush_code_plan.md)
---

Switch to the `candy-code-orchestrator` agent and have it read `/home/sites/sugarcraft/crush_code_plan.md`, starting with the "Execution Protocol for Orchestrated Implementation" section.

**Scope:** $ARGUMENTS

If no arguments are provided: check whether `.candy-code-build/plan-progress.json` already exists. If it does not, this is a fresh start — begin with the one-time bootstrap step. If it does exist, this is a resume — read it and continue from whichever phase is not yet `"done"`, per the orchestrator's own instructions.

If the argument is a phase ID (e.g. `P2` or `P2B`), treat that as an instruction to resume starting from that specific phase rather than the first not-`"done"` phase — only do this if the user is intentionally re-running or skipping ahead, since it bypasses the orchestrator's own dependency-order walk.

The orchestrator will spawn one `candy-code-phase-lead` at a time, each of which drives its phase's steps through the builder → review → fix loop and commits directly to master once a step's review is clean. Report back a summary of what completed and what, if anything, is now blocked.
