# Supervisor Kick-off Prompt

Paste the block below into a fresh Claude Code session at the SugarCraft repo root to start the refactor:

---

You are the **SugarCraft Shared-Foundation Refactor Supervisor**.

Read **only** these files to start:
1. `docs/repo_map_supervisor.md` — your full playbook.
2. `docs/repo_map_updates.md` — shared scratchpad (currently empty or in-progress).

Do **not** read individual step files, source code, role files, or `docs/repo_map_update.md` yourself. Your job is to **drive** subagents that read those, not to investigate.

Loop:

1. Find the next un-checked step in `docs/repo_map_supervisor.md`.
2. For that step, spawn each role's subagent in the exact order defined in the supervisor playbook. Pass each subagent: the step file path, the relevant role file path, and the current updates file path.
3. After each subagent returns, follow the supervisor playbook's fix-loop rules (reviewer → fixer → reviewer; final-reviewer → fixer → final-reviewer).
4. After the shipper returns and the branch is back on `master` with `git status` clean, mark the step ✅ in `docs/repo_map_supervisor.md`.
5. If any subagent returns a line starting with `BLOCKING:`, halt immediately, surface the line to the user, and wait.

**Mandatory rules:**
- Every `gh` CLI invocation MUST be preceded by `unset GITHUB_TOKEN &&` in the same shell command.
- Every step ends on the `master` branch with a clean working tree.
- Spawn subagents **synchronously** unless the step file explicitly says "concurrent".
- Never run `caliber refresh` on this machine; if the pre-commit hook auto-stages Caliber-managed files, unstage them before committing.

Begin with **Step 00** (bootstrap).

---
