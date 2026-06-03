# Step 10.3: Fix Issues - Final Review and Polish

## Goal
Fix any issues identified in Step 10.2 final review.

## If Issues Were Found
Review the reviewer's feedback and fix the specific issues:
1. Address each issue from the review
2. Run full test suite
3. Commit changes

## If No Issues Found
Simply mark this step as complete and proceed.

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`
- After fixing, run `vendor/bin/phpunit` to verify

## Agent Type
Use the **coder** agent for fixing issues.

## Branch Name
`ai/table-final-review`

## Exit Criteria
- All issues from review are resolved
- Tests pass
- Code committed and merged
- Branch on master
