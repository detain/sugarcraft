---
description: Start or resume plan_comparison.md (the candy-code comparison plan) — rolling execution keeping up to 10 agents busy, with step and wave review/fix loops
agent: cc-cmp-orchestrator
---

Run `plan_comparison.md` (repo root; `/home/sites/sugarcraft` on myadmin1) as the `cc-cmp-orchestrator` agent. Read its agent file (`.opencode/agents/cc-cmp-orchestrator.md`), then the plan's §0, §1, §2, §3 and §6, before doing anything.

**Arguments:** $ARGUMENTS

- **No arguments:** resume from `prompt_kit/findings/comparison-1026/lanes/run-state.json`, or start fresh if it is missing, keeping **10** agents busy.
- **`slots=<n>`:** keep up to `<n>` agents busy (1–10).

## Rolling execution

The plan's waves are a priority order, not a barrier.

**Each tick**, the run lead (`cc-cmp-lead`):
1. welds every lane whose review loop came back clean, into master;
2. starts the wave loop for each wave whose steps are all welded;
3. asks the scheduler (`schedule_plan.py --next`) which steps can start now — deps welded, no file or region collision with any lane still in flight, LATE only after all normal steps — and cuts a `../cc-cmp-<step>` worktree for each;
4. launches every agent for the tick in parallel, up to the slot count: builders for new lanes, fresh reviewers, fixers, and wave reviewers/fixers.

So when 2 lanes are in review, the other 8 slots build steps from the next waves.

**The loops:**
- **Step loop:** a fresh reviewer, then a fixer, then a new reviewer, until 0 critical, 0 major and no step-local minor findings.
- **Wave loop:** after a wave's steps are all welded, the same cycle on master.

After 5 rounds on the same finding, the step is blocked and the owner is told.

## Ordering

Before the first LATE step, the orchestrator asks the owner the open rulings in plan §6.3. After every step is welded it runs `cc-cmp-gate`, the §7 closeout loop.

Commits land on master as Joe Huss with no Co-Authored-By. Nothing is pushed unless the owner says so. Report what closed and anything blocked.
