# Step 7.1: Implementation - Add "Showing Rows" Footer

## Goal
Implement the "Showing X to Y of Z rows" footer display using the existing i18n key.

## Background
The lang/en.php file already has a `showing_rows` key: `'Showing {from} to {to} of {total} rows'`
But there's no method to display this in the footer. Currently only `PageFooter()` shows "Page N of M".

## Implementation Tasks
1. Add a `withFooterType(string $type): self` method or similar to control footer content
   - Type could be: 'page' (current Page N of M), 'rows' (Showing X to Y of Z), 'both'
2. OR add separate method like `withShowRowCount(bool $show = true)`
3. Modify `fillFooterRow()` to render appropriate content based on type
4. Update the page calculation to show row range:
   - from = (page * pageSize) + 1
   - to = min((page + 1) * pageSize, totalRows)
   - total = totalFilteredRows

## Key Files to Modify
- /home/sites/sugarcraft/sugar-table/src/Table.php
  - Add footer type control method
  - Modify fillFooterRow() for row count display
  - Add row range calculation

## API Design Options

Option A - Separate methods:
```php
$t = $table->withPageSize(25)->withShowRowCount(true);
```

Option B - Footer type enum:
```php
$t = $table->withPageSize(25)->withFooterType(FooterType::RowCount);
```

Option C - Footer content callback:
```php
$t = $table->withFooterFormatter(fn($from, $to, $total) => "...");
```

Recommend Option B for clarity and type safety.

## Verification
- Footer shows "Showing 1 to 25 of 100 rows" for page 0 with 100 total rows
- Footer shows "Showing 26 to 50 of 100 rows" for page 1

## Agent Type
Use the **coder** agent for implementation.

## Branch Name
`ai/table-showing-rows-footer`

## Handoff Notes
- Make sure to run `vendor/bin/phpunit` after changes
- The i18n key already exists in lang/en.php - use it!
- Keep page-style footer working as default

## Exit Criteria
- Row count footer displays correctly
- i18n key is used for formatting
- All existing tests still pass
- Code committed, PR created, merged to master
