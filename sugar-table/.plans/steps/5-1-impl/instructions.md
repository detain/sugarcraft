# Step 5.1: Implementation - Add Global Search

## Goal
Add a `search($text)` method that searches across ALL columns at once, complementing the existing per-column `Filter()` method.

## Background
Currently only per-column filtering exists via `Filter('colKey', 'text')`. Users expect a global search that matches across any column.

## Implementation Tasks
1. Add `search(string $text): self` method to Table
2. The method should filter rows where ANY column contains the search text (case-insensitive)
3. When text is empty, clear the global search
4. Add `ClearSearch(): self` method for explicit clearing
5. Combine with existing column filters - row must match both global search AND any column filters
6. Reset selectedIndex to 0 when search changes results

## Key Files to Modify
- /home/sites/sugarcraft/sugar-table/src/Table.php
  - Add search() method
  - Add ClearSearch() method
  - Modify filteredSortedRows() to include global search

## API Design
```php
// Search across all columns
$t = $table->search('alice');

// Clear search
$t = $table->ClearSearch();

// Combined with column filters
$t = $table->search('alice')->Filter('city', 'NYC');
```

## Verification
- search('alice') finds rows where any column contains 'alice'
- search('') clears the search
- Combined with existing Filter() works correctly
- Case-insensitive matching

## Agent Type
Use the **coder** agent for implementation.

## Branch Name
`ai/table-global-search`

## Handoff Notes
- Make sure to run `vendor/bin/phpunit` after changes
- Use existing filtering infrastructure, just apply to all columns
- Keep existing Filter() method working exactly as before

## Exit Criteria
- search() method works correctly
- ClearSearch() method works
- Works in combination with existing Filter()
- All existing tests still pass
- Code committed, PR created, merged to master
