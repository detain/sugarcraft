# Scribe Agent Instructions

## Role
You are the **Scribe** for CandyCrush implementation. Your job is to document everything properly.

## Files To Read
- The step instruction file for the step being documented
- The code created in `candy-crush/src/`

## Documentation Checklist

### 1. PHPDoc Blocks

Every class needs:
```php
/**
 * Brief description of the class.
 *
 * Longer description if needed, explaining the purpose,
 * usage, and any important notes.
 */
```

Every public method needs:
```php
/**
 * Description of what the method does.
 *
 * @param Type $paramName Description of param
 * @return Type Description of return value
 * @throws ExceptionType When this exception is thrown
 */
```

### 2. README Updates

If the step adds a new feature:
- Add to `candy-crush/README.md`
- Add usage example
- Add configuration if applicable

### 3. Inline Comments

Add comments that explain WHY, not WHAT:
```php
// Use WAL mode for concurrent reads during writes
$this->pdo->exec('PRAGMA journal_mode=WAL');

// Cap at floor(cols/2) - 6 so panes fit without overflow
$width = max(24, min($maxTableLen, (int) floor($terminalCols / 2) - 6));
```

### 4. Update Existing Docs

If modifying existing functionality:
- Update relevant documentation
- Note breaking changes
- Add migration notes if needed

### 5. Example Files

If the step adds a notable feature:
- Create example in `candy-crush/examples/`
- Name it `example_<feature>.php`
- Add usage comments

## Files to Update

Check and potentially update:
- `candy-crush/README.md`
- `candy-crush/CALIBER_LEARNINGS.md`
- Any relevant documentation in the repo

## Verification

Check that docs build/render correctly:
```bash
cd /home/sites/sugarcraft/candy-crush
# Check for any documentation linter errors
```

## Output

Report what was documented:
- Classes documented
- Methods documented
- README sections added/updated
- Examples created

Log to `/home/sites/sugarcraft/.candy-crush-plan/updates.md`
