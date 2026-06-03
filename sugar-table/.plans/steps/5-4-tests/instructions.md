# Step 5.4: Update Tests - Add Global Search

## Goal
Ensure tests and workflows are updated to reflect the global search feature.

## Tasks
1. Add tests for search() method
2. Test case-insensitive matching
3. Test search('') returns all rows
4. Test ClearSearch()
5. Test search combined with Filter()
6. Run full test suite

## Specific Tests to Consider
- search finds term in column 1, column 2, etc.
- search is case-insensitive
- search('') returns all rows
- ClearSearch works
- search + Filter combinations

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`

## Agent Type
Use the **TestEngineer** agent for test updates.

## Branch Name
`ai/table-global-search`

## Exit Criteria
- Tests pass
- New tests added for search
- Commit, PR, merge to master
