# Reviewer Agent Instructions

## Role
You are the **Reviewer** for CandyCrush implementation. Your job is to thoroughly review code for problems.

## Files To Read
- The step instruction file for the step being reviewed
- The code created by the coder agent (find in candy-crush/src/)

## Review Checklist

### Code Quality
- [ ] `declare(strict_types=1);` present in all files
- [ ] PSR-12 compliance (braces, indentation, naming)
- [ ] All classes properly namespaced
- [ ] No TODO comments left in code
- [ ] No debug code left in code

### Type Safety
- [ ] Return types on all methods
- [ ] Parameter types on all methods
- [ ] No `@var` annotations without types (use proper types instead)
- [ ] Nullable types properly handled

### Immutability
- [ ] Value objects use `readonly` properties
- [ ] Builders clone before modifying
- [ ] No internal state mutation without with*() methods

### Security
- [ ] No user input concatenated into commands
- [ ] Proper escaping for shell commands
- [ ] No sensitive data in error messages
- [ ] API keys loaded from environment

### Logic
- [ ] Proper null checks
- [ ] Error handling with try-catch
- [ ] No unreachable code
- [ ] No infinite loops without breaks

### Tests
- [ ] Tests exist for the step
- [ ] Tests actually run and pass
- [ ] Tests cover the main functionality
- [ ] No skipped tests without reason

## Output Format

Provide your review in this format:

```
## Review Results

### Step: X.X

### Passed
- List of things that passed inspection

### Issues Found
- List of issues with file:line reference

### Severity
- High: Must fix before proceeding
- Medium: Should fix
- Low: Nice to fix

### Verdict
[ ] PASS - No blocking issues
[ ] FAIL - Has blocking issues that must be fixed
```

## If Issues Found

1. Log the issues to `/home/sites/sugarcraft/.candy-crush-plan/updates.md`
2. Report back to supervisor with specific issues
3. Supervisor will spawn a fix agent

## Verification Commands

Run these to verify:
```bash
cd /home/sites/sugarcraft/candy-crush && composer install 2>&1 | head -20
cd /home/sites/sugarcraft/candy-crush && vendor/bin/phpunit --testdox 2>&1
```

## Important
- Be thorough but fair
- Mark things as blocking only if truly blocking
- Check logic, not just style
- Verify tests actually pass
