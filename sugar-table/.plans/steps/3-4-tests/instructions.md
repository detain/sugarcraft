# Step 3.4: Update Tests - Implement Multiline Mode

## Goal
Ensure tests and workflows are updated to reflect the multiline mode implementation.

## Tasks
1. Update existing multiline tests to properly test the feature
2. Add tests for row height calculation with mixed cell heights
3. Add tests for different WrapModes in multiline context
4. Verify golden files are updated if needed (with UPDATE_GOLDENS=1)
5. Run full test suite

## Specific Tests to Consider
- Test row with one multiline cell and one single-line cell
- Test row height equals max cell height
- Test all WrapModes in multiline mode
- Test multiline mode with zebra striping
- Test multiline mode with selection
- Test multiline mode with pagination

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`
- Use UPDATE_GOLDENS=1 only if the change is intentional
- Make sure golden file updates are correct

## Agent Type
Use the **TestEngineer** agent for test updates.

## Branch Name
`ai/table-multiline-mode`

## Exit Criteria
- Tests pass
- New tests added for multiline behavior
- Golden files updated if needed
- Commit, PR, merge to master
