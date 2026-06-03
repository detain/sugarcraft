# Step 1.4: Update Tests - Wire computeColumnWidths into Rendering

## Goal
Ensure tests and workflows are updated to reflect the new behavior.

## Tasks
1. Review existing tests to ensure they adequately test the feature
2. Add any missing tests that would catch regressions
3. Verify .github/workflows/ci.yml doesn't need changes
4. Ensure phpunit.xml is still correct
5. Run full test suite with `vendor/bin/phpunit`

## Specific Tests to Consider
- Test that rendering actually uses computed column widths
- Test that Dynamic columns resize based on content
- Test that Content columns exactly fit content
- Test that Percent columns work alongside Dynamic/Content columns
- Test edge case: table too narrow for content

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`
- Use `UPDATE_GOLDENS=1 vendor/bin/phpunit` if golden files need updating
- Do not update golden files unless the change is intentional and correct

## Agent Type
Use the **TestEngineer** agent for test updates.

## Branch Name
`ai/table-compute-widths-fix`

## Exit Criteria
- All tests pass
- New tests added if needed
- Workflows unchanged (likely no changes needed)
- Commit, PR, merge to master
