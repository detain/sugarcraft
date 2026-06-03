# Step 8.4: Update Tests - Add Keyboard Navigation

## Goal
Ensure tests and workflows are updated to reflect the keyboard navigation feature.

## Tasks
1. Add tests for keyboard navigation helpers
2. Test scrollYForKey for each key type
3. Test boundary clamping
4. Run full test suite

## Specific Tests to Consider
- testScrollYForKeyArrowUp
- testScrollYForKeyArrowDown
- testScrollYForKeyPageUp
- testScrollYForKeyPageDown
- testScrollYForKeyHome
- testScrollYForKeyEnd
- testScrollYClampedAtZero
- testScrollYClampedAtMax

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`

## Agent Type
Use the **TestEngineer** agent for test updates.

## Branch Name
`ai/table-keyboard-nav`

## Exit Criteria
- Tests pass
- New tests added for keyboard nav
- Commit, PR, merge to master
