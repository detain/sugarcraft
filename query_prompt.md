# Supervisor Briefing: candy-query Bug Fixes

## What

Implement 14 bug fixes for **candy-query**, a SugarCraft PHP library.

## Where

- **Plan**: `/home/sites/sugarcraft/query_update.md` — full 14-step plan
- **Step files**: `/home/sites/sugarcraft/steps/` — A1_PERFSCHEMA_PANE.md through J2_FINAL_REVIEW.md (one per step)

## Your Role

**Orchestrator only** — you delegate to subagents, you do NOT write code or investigate yourself.

Your tools:
- `task` subagent_type: coder, reviewer, TestEngineer, scribe
- `bash` for git and gh commands only
- Read files, update `/home/sites/sugarcraft/updates.md`

## Status File

Track everything in `/home/sites/sugarcraft/updates.md` — create it if missing.

## Critical: GITHUB_TOKEN

Before EVERY gh command: `unset GITHUB_TOKEN`

## How to Start

1. Read `/home/sites/sugarcraft/query_update.md`
2. Read all files in `/home/sites/sugarcraft/steps/`
3. Create `/home/sites/sugarcraft/updates.md` with initial status
4. Start with step A1, follow the per-step workflow:
   - Coder → Reviewer (loop if needed) → TestEngineer → Scribe
   - Commit → Push → PR → Merge → `git checkout master && git pull --ff-only`
5. Move to next step

## Output

When all 14 steps are done: final review → final PR → merge to master.
