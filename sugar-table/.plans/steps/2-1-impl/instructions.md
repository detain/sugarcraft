# Step 2.1: Implementation - Implement Frozen Columns

## Goal
Implement frozen columns functionality so columns specified via `withFrozenCols()` actually stay fixed while other columns scroll horizontally.

## Background
The `withFrozenCols(array $indices)` method exists and stores indices in `$this->frozenCols`, but the rendering code never uses this information. The goal is to make frozen columns work properly.

## Implementation Tasks
1. Modify `renderToBuffer()` to separate frozen columns from scrollable columns
2. Render frozen columns first (they stay on the left)
3. Calculate scrollX offset for non-frozen columns
4. Modify rendering to skip columns before scrollX offset for non-frozen columns
5. Handle horizontal scrolling for non-frozen columns only
6. Ensure borders are correctly rendered between frozen and scrollable sections

## Key Concept
- Frozen columns are always visible on the left
- Non-frozen columns scroll horizontally based on scrollX
- When scrollX > 0, skip the first `scrollX` columns worth of content from non-frozen columns

## Key Files to Modify
- /home/sites/sugarcraft/sugar-table/src/Table.php
  - `renderToBuffer()` - handle frozen vs scrollable columns separately
  - `fillDataRow()` - apply scrollX offset for non-frozen columns
  - `fillHeaderRow()` - same handling
  - May need to track which columns are frozen vs scrollable

## Verification
- Table with frozen column 0 should show it always on left
- Scrolling right should hide columns after scrollX offset
- Frozen columns should not be affected by scrollX
- Test with multiple frozen columns

## Agent Type
Use the **coder** agent for implementation.

## Branch Name
`ai/table-frozen-cols`

## Handoff Notes
- Make sure to run `vendor/bin/phpunit` after changes
- Frozen cols work with horizontal scroll - when scrollX > 0, frozen cols stay visible
- Consider edge case: what if all columns are frozen? Should work without scrolling.

## Exit Criteria
- Frozen columns render correctly and stay on left
- scrollX affects non-frozen columns
- All existing tests still pass
- Code committed, PR created, merged to master
