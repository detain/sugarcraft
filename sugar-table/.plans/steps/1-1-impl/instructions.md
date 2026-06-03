# Step 1.1: Implementation - Wire computeColumnWidths into Rendering

## Goal
Fix the disconnect between `computeColumnWidths()` method and actual rendering. The method correctly computes column widths based on ColumnWidth enum values, but the results are not used in `renderToBuffer()`.

## Background
The `computeColumnWidths(int $tableWidth)` method exists in Table.php and:
1. Collects Fixed/Percent widths and counts Dynamic/Content columns
2. Distributes remaining space among Dynamic/Content columns  
3. Returns `array<int, int>` mapping column index to computed width

However, `renderToBuffer()` and `fillDataRow()` use `$column->width` directly instead of these computed widths.

## Implementation Tasks
1. Store computed widths in a private property after computing in `renderToBuffer()`
2. Modify `fillDataRow()` to use computed widths instead of `$column->width`
3. Modify `fillHeaderRow()` to use computed widths
4. Update `computeTotalWidth()` to use the computed widths or modify it to accept computed widths
5. Ensure ColumnWidth::Dynamic, ColumnWidth::Content, and ColumnWidth::Percent all work correctly with the rendering

## Key Files to Modify
- /home/sites/sugarcraft/sugar-table/src/Table.php
  - `renderToBuffer()` method - store computed widths
  - `fillDataRow()` - use computed widths for $colWidth
  - `fillHeaderRow()` - use computed widths for column widths  
  - May need to add a private property to cache computed widths

## Verification
- Existing tests should still pass
- Create a test that verifies ColumnWidth::Dynamic and ColumnWidth::Content columns render with content-based widths
- The `computeColumnWidths()` tests should now reflect actual rendering behavior

## Agent Type
Use the **coder** agent for implementation.

## Branch Name
`ai/table-compute-widths-fix`

## Handoff Notes
- Make sure to run `vendor/bin/phpunit` after changes
- If any existing test fails, analyze whether the test needs updating or the implementation is wrong
- Document any API changes clearly

## Exit Criteria
- All existing tests pass
- New tests verify the feature works
- Code is committed, PR created, merged to master
- Branch left on master for next step
