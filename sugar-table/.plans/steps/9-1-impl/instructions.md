# Step 9.1: Implementation - Add Remaining Polish Features

## Goal
Implement remaining polish features that enhance the table usability.

## Features to Implement (pick what makes sense)

### 1. Cell Padding Control
Add `withCellPadding(int $padding): self` to add space inside cells.
- Currently all cells are flush against borders
- Padding would add inner spacing

### 2. Column Visibility Toggle
Add `withHiddenCols(array $indices): self` to hide columns without removing them.
- Useful for optional columns
- Hidden columns still affect data/filters but don't render

### 3. Table Width Auto-Sizing
Add `withAutoWidth(): self` that calculates optimal width based on content and terminal size (if detectable).
- Fall back to computeColumnWidths logic
- Or accept terminal width as parameter

### 4. Data Formatters per Column
Add `withFormatter(string $colKey, callable $formatter): self` or add to Column.
- Allow custom formatting of cell values (dates, numbers, currency)
- Column already has flexible structure

### 5. Click Handler Support
Add `onRowClick(callable $handler): self` callback.
- Callback receives row index and row data
- Enables interactive applications

## Recommendation
Focus on Cell Padding Control and Column Visibility Toggle as they provide most value with reasonable effort. Data formatters could use StyledCell which already exists.

## Implementation Approach
Choose 2-3 features that are well-defined and can be completed in one phase:
1. Cell Padding Control
2. Column Visibility Toggle
3. Perhaps Table Auto-Width (if straightforward)

## Key Files to Modify
- /home/sites/sugarcraft/sugar-table/src/Table.php
- /home/sites/sugarcraft/sugar-table/src/Column.php

## Agent Type
Use the **coder** agent for implementation.

## Branch Name
`ai/table-polish`

## Handoff Notes
- Make sure to run `vendor/bin/phpunit` after changes
- Focus on features with clear benefit and implementation path
- Don't over-engineer

## Exit Criteria
- Selected polish features implemented
- All existing tests still pass
- Code committed, PR created, merged to master
