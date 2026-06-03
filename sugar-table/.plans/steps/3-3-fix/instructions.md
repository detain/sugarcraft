# Step 3.3: Fix Issues - Implement Multiline Mode

## Goal
Fix any issues identified in Step 3.2 review.

## If Issues Were Found
Review the reviewer's feedback and fix the specific issues:
1. Address each issue from the review
2. Re-run tests to verify fixes
3. Commit changes

## If No Issues Found
Simply mark this step as complete and proceed.

## Important Notes
- Each time you use `gh` CLI commands, you MUST first `unset GITHUB_TOKEN`
- After fixing, run `vendor/bin/phpunit` to verify
- Focus on correctness and backward compatibility

## Agent Type
Use the **coder** agent for fixing issues.

## Branch Name
`ai/table-multiline-mode`

## Exit Criteria
- All issues from review are resolved
- Tests pass
- Code committed and merged
- Branch on master
