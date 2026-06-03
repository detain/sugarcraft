# Step H1: Fix Documentation Errors

## Goal
Fix incorrect API documentation in `docs/lib/candy-query.html`.

## Issues to Fix

### Issue 1: AdminProviderInterface::forFlavor() doesn't exist
In docs/lib/candy-query.html around line 156:
```html
<tr><td>AdminProviderInterface</td><td>forFlavor(flavor, serverContext)</td>
```

This static factory method doesn't exist. The correct usage is:
```php
$provider = $flavor === Flavor::MySQL 
    ? MysqlAdminProvider::new($serverContext)
    : PostgresAdminProvider::new($db);
```

### Issue 2: Review README.md for any other inaccuracies
Compare README.md architecture table against actual source files.

## Files to Modify

1. **`docs/lib/candy-query.html`** — Fix the API table entry for AdminProviderInterface
2. **`README.md`** — Fix any discrepancies found in architecture section

## Acceptance Criteria
- API table accurately describes existing methods
- No mention of non-existent static factories
- All class names and method signatures match actual code

## Coder Notes
- Only fix documented APIs that are actually wrong - don't rewrite working docs
- For AdminProviderInterface, document the concrete implementations (MysqlAdminProvider, PostgresAdminProvider) and how to choose
