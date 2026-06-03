# Supervisor Instructions for Sugar-Table Enhancement

## Your Role
You are the supervisor for this sugar-table enhancement project. Your job is to:
1. Keep track of the larger plan and overall progress
2. Spawn subagents to execute each step
3. Ensure each step completes properly before moving to the next
4. Track progress in the updates.md file

## Important Notes
- Do NOT read individual step instruction files beyond this document
- Follow the handoff instructions here to spawn subagents
- Each step should be completed fully before moving to the next
- After each step's actual work, ensure the review, fix, test update, and doc update steps run

## Step Order (Repeating Pattern)
For each phase, run these steps in order:
1. Implementation (coder subagent)
2. Review (reviewer subagent)
3. Fix issues (coder subagent)
4. Update tests/workflows (TestEngineer subagent)
5. Update documentation (coder subagent)

## Phase List

### Phase 1: Wire computeColumnWidths into Rendering
- Step 1.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/1-1-impl/instructions.md
- Step 1.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/1-2-review/instructions.md
- Step 1.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/1-3-fix/instructions.md
- Step 1.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/1-4-tests/instructions.md
- Step 1.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/1-5-docs/instructions.md

### Phase 2: Implement Frozen Columns
- Step 2.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/2-1-impl/instructions.md
- Step 2.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/2-2-review/instructions.md
- Step 2.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/2-3-fix/instructions.md
- Step 2.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/2-4-tests/instructions.md
- Step 2.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/2-5-docs/instructions.md

### Phase 3: Implement Multiline Mode
- Step 3.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/3-1-impl/instructions.md
- Step 3.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/3-2-review/instructions.md
- Step 3.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/3-3-fix/instructions.md
- Step 3.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/3-4-tests/instructions.md
- Step 3.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/3-5-docs/instructions.md

### Phase 4: Implement Horizontal Scroll
- Step 4.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/4-1-impl/instructions.md
- Step 4.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/4-2-review/instructions.md
- Step 4.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/4-3-fix/instructions.md
- Step 4.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/4-4-tests/instructions.md
- Step 4.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/4-5-docs/instructions.md

### Phase 5: Add Global Search
- Step 5.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/5-1-impl/instructions.md
- Step 5.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/5-2-review/instructions.md
- Step 5.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/5-3-fix/instructions.md
- Step 5.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/5-4-tests/instructions.md
- Step 5.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/5-5-docs/instructions.md

### Phase 6: Add Row Expansion/Collapse
- Step 6.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/6-1-impl/instructions.md
- Step 6.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/6-2-review/instructions.md
- Step 6.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/6-3-fix/instructions.md
- Step 6.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/6-4-tests/instructions.md
- Step 6.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/6-5-docs/instructions.md

### Phase 7: Add "Showing Rows" Footer
- Step 7.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/7-1-impl/instructions.md
- Step 7.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/7-2-review/instructions.md
- Step 7.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/7-3-fix/instructions.md
- Step 7.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/7-4-tests/instructions.md
- Step 7.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/7-5-docs/instructions.md

### Phase 8: Add Keyboard Navigation
- Step 8.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/8-1-impl/instructions.md
- Step 8.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/8-2-review/instructions.md
- Step 8.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/8-3-fix/instructions.md
- Step 8.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/8-4-tests/instructions.md
- Step 8.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/8-5-docs/instructions.md

### Phase 9: Add Remaining Polish
- Step 9.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/9-1-impl/instructions.md
- Step 9.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/9-2-review/instructions.md
- Step 9.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/9-3-fix/instructions.md
- Step 9.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/9-4-tests/instructions.md
- Step 9.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/9-5-docs/instructions.md

### Phase 10: Final Review and Polish
- Step 10.1: Implementation - /home/sites/sugarcraft/sugar-table/.plans/steps/10-1-impl/instructions.md
- Step 10.2: Review - /home/sites/sugarcraft/sugar-table/.plans/steps/10-2-review/instructions.md
- Step 10.3: Fix issues - /home/sites/sugarcraft/sugar-table/.plans/steps/10-3-fix/instructions.md
- Step 10.4: Update tests - /home/sites/sugarcraft/sugar-table/.plans/steps/10-4-tests/instructions.md
- Step 10.5: Update docs - /home/sites/sugarcraft/sugar-table/.plans/steps/10-5-docs/instructions.md

## Spawning Subagents
For each step:
1. Read the step instructions file
2. Spawn the appropriate agent type (coder for implementation, TestEngineer for tests, reviewer for reviews)
3. Pass the goal prompt from the instructions file
4. Wait for completion before moving to next step

## Updates File
Track progress in: /home/sites/sugarcraft/sugar-table/.plans/updates.md

## Branch Management
Each subagent should:
1. Create a feature branch
2. Make changes
3. Commit
4. Create PR
5. Merge PR
6. Leave master branch updated and ready for next agent
