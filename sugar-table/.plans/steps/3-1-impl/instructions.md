# Step 3.1: Implementation - Implement Multiline Mode

## Goal
Fix multiline mode so rows render with proper multi-line height when `withMultilineMode(true)` is set.

## Background
The `withMultilineMode(bool $multiline)` stores the flag but the rendering code never checks it. The docstring references `renderRowLines()` which doesn't exist. Currently, all cells render on a single line regardless of content.

## Implementation Tasks
1. Modify `renderToBuffer()` to check `$this->multilineMode`
2. When multilineMode is true:
   - Calculate max number of lines for each row based on wrapped cell content
   - Use the maximum across all cells in the row as row height
   - Render each cell's content across multiple lines
   - Properly space rows vertically
3. When multilineMode is false (default):
   - Keep existing single-line behavior for backward compatibility
4. Implement proper `renderRowLines()` method or equivalent logic
5. Handle cell wrapping using Column's WrapMode

## Key Concept
- Each row's height = max number of lines from any cell in that row
- Cells with fewer lines should still occupy full vertical space
- Row borders should span the full row height

## Key Files to Modify
- /home/sites/sugarcraft/sugar-table/src/Table.php
  - `renderToBuffer()` - handle multiline rows
  - `fillDataRow()` - may need to become `fillDataRowLines()` for multi-line rows
  - May add new helper methods for multiline rendering
- /home/sites/sugarcraft/sugar-table/src/Column.php
  - `renderCell()` returns list<string> - already exists for wrapping

## Verification
- Table with multiline content should render multiple lines per row
- Row with single-line content should still render correctly alongside multiline rows
- Test with different WrapModes (WordWrap, Character, None)

## Agent Type
Use the **coder** agent for implementation.

## Branch Name
`ai/table-multiline-mode`

## Handoff Notes
- Make sure to run `vendor/bin/phpunit` after changes
- Column::renderCell() already returns array of lines - use this!
- Default behavior (multilineMode=false) must remain backward compatible

## Exit Criteria
- multilineMode=true renders multi-line rows correctly
- multilineMode=false (default) unchanged
- All existing tests still pass
- Code committed, PR created, merged to master
