# Step 10.4: Update Tests - Final Review and Polish

## Goal
Final test verification and any needed updates.

## Tasks
1. Run full test suite
2. Verify all tests pass
3. Check test coverage is adequate
4. Ensure CI workflows are correct
5. Run UPDATE_GOLDENS=1 if needed for any render changes

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`
- Run full test suite: `vendor/bin/phpunit`
- Check coverage with CI

## Agent Type
Use the **TestEngineer** agent for final test verification.

## Branch Name
`ai/table-final-review`

## Exit Criteria
- All tests pass
- Golden files updated if needed
- CI passing
- Commit, PR, merge to master
