# Step 2.4: Update Tests - Implement Frozen Columns

## Goal
Ensure tests and workflows are updated to reflect the frozen columns implementation.

## Tasks
1. Add tests for frozen columns functionality
2. Test that frozen columns stay visible when scrolling
3. Test that scrollX only affects non-frozen columns
4. Test edge cases (all frozen, no frozen, excessive scrollX)
5. Verify .github/workflows/ci.yml doesn't need changes
6. Run full test suite

## Specific Tests to Consider
- Test table with 1 frozen column, scroll to middle
- Test table with 2 frozen columns
- Test table with all columns frozen (no scroll)
- Test table with no frozen columns (existing behavior)
- Test scrollX with frozen columns

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`
- Add tests that actually verify the frozen behavior, not just that it renders

## Agent Type
Use the **TestEngineer** agent for test updates.

## Branch Name
`ai/table-frozen-cols`

## Exit Criteria
- Tests pass
- New tests added for frozen column behavior
- Commit, PR, merge to master
