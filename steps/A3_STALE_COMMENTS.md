# Step A3: Remove Stale Comments

## Goal
Find and remove or update outdated comments in the codebase that no longer reflect reality.

## Files to Modify

1. **`src/App.php`** — Remove/update this stale comment (around line 403-406):
   ```php
   // Note: ProcessList and ConnStats currently use DashboardPage as placeholder
   // since ConnectionsPage does not extend PageBase. Full ConnectionsPage
   // integration will come in a later phase.
   ```
   ConnectionsPage NOW extends PageBase, so this comment is false.

2. Any other stale comments found via grep across the candy-query src/

## Grep for Stale Comments
Run before coding to find all candidates:
```bash
grep -rn "placeholder" /home/sites/sugarcraft/candy-query/src
grep -rn "TODO" /home/sites/sugarcraft/candy-query/src  
grep -rn "FIXME" /home/sites/sugarcraft/candy-query/src
grep -rn "later phase" /home/sites/sugarcraft/candy-query/src
grep -rn "does not extend" /home/sites/sugarcraft/candy-query/src
```

## Acceptance Criteria
- No comments claiming ConnectionsPage doesn't extend PageBase
- No "placeholder" comments for features that are implemented
- Remaining TODOs/FIXMEs are genuinely outstanding items

## Coder Notes
- Be conservative - only remove comments that are provably stale
- If unsure whether a comment is stale, mark it for reviewer
- Do NOT add new comments unless they explain WHY, not WHAT
