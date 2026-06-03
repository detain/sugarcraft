# Step 7.4: Update Tests - Add "Showing Rows" Footer

## Goal
Ensure tests and workflows are updated to reflect the row count footer feature.

## Tasks
1. Add tests for row count footer
2. Test correct from/to/total calculation
3. Test with different pages
4. Test with filtering
5. Run full test suite

## Specific Tests to Consider
- testShowingRowsFooter
- testRowCountWithPagination
- testRowCountWithFiltering

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`

## Agent Type
Use the **TestEngineer** agent for test updates.

## Branch Name
`ai/table-showing-rows-footer`

## Exit Criteria
- Tests pass
- New tests added for row count footer
- Commit, PR, merge to master
