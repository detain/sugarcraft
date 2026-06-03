# Step 4.4: Update Tests - Implement Horizontal Scroll

## Goal
Ensure tests and workflows are updated to reflect the horizontal scroll implementation.

## Tasks
1. Add tests for scrollX behavior
2. Test scrollX=0, scrollX=1, scrollX > available columns
3. Test scrollX combined with frozen columns
4. Run full test suite

## Specific Tests to Consider
- Test scrollX=0 shows all columns
- Test scrollX=1 skips first column
- Test scrollX with frozen columns
- Test scrollX clamped to max

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`

## Agent Type
Use the **TestEngineer** agent for test updates.

## Branch Name
`ai/table-horizontal-scroll`

## Exit Criteria
- Tests pass
- New tests added for scrollX
- Commit, PR, merge to master
