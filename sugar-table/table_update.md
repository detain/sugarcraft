# Sugar-Table Enhancement Plan

## Overview

This plan addresses the comprehensive update and fix of the sugar-table library. The library has good bones but has several broken features that are advertised but not implemented, and several missing features that users would expect from a terminal table component.

## Current State Assessment

### Working Features
- Column definitions (key, title, width, flexibleWidth, maxWidth, filterable, alignLeft, style)
- ColumnWidth enum (Fixed, Percent, Dynamic, Content) with multi-pass computation
- WrapMode enum (None, WordWrap, Character)
- Row data (RowData, Row with style/zebra support)
- StyledCell for per-cell ANSI style overrides
- Pagination (withPageSize, withPage, NextPage, PreviousPage, SelectPage, PageFooter)
- Sorting (SortBy asc/desc, multi-column sort, ClearSort)
- Filtering (per column, case-insensitive)
- Selection (SelectNext, SelectPrevious, withSelectedIndex, CurrentRow)
- Zebra striping
- Missing data indicator
- Border styling (withBorder and withBorderStyle)
- Base style, header/footer control
- styleFunc callback
- i18n support
- Good test coverage with snapshot tests

### Broken Features (Need Fixing)
1. **Frozen Columns** - `withFrozenCols()` stores indices but never uses them in rendering
2. **Horizontal Scroll** - `withScrollX()` stores value but never applies it
3. **Multiline Mode** - `withMultilineMode(true)` stores flag but never uses it; `renderRowLines()` referenced in docs doesn't exist
4. **computeColumnWidths** - Method exists and computes correctly but results are not used in rendering

### Missing Features (Need Implementation)
1. Global `search($text)` method to search across all columns
2. Row expansion/collapse functionality
3. "Showing X to Y of Z rows" footer display
4. Keyboard navigation for viewport scrolling (arrow keys, Page Up/Down)
5. Cell padding/gaps control
6. Click handlers for row selection
7. Column visibility toggle
8. Table width auto-sizing based on terminal
9. Data formatters per column
10. Border options for inner grid lines
11. Row height for multi-line content
12. Smooth scroll animation

## Phases

### Phase 1: Wire computeColumnWidths into Rendering
Fix the disconnect between computed column widths and actual rendering.

### Phase 2: Implement Frozen Columns
Make frozen columns actually work - columns stay fixed while others scroll.

### Phase 3: Implement Multiline Mode
Fix multiline mode so rows render with proper multi-line height.

### Phase 4: Implement Horizontal Scroll
Fix scrollX so it actually scrolls columns horizontally.

### Phase 5: Add Global Search
Implement search across all columns at once.

### Phase 6: Add Row Expansion/Collapse
Add expandable row functionality.

### Phase 7: Add "Showing Rows" Footer
Implement the existing i18n key for row count display.

### Phase 8: Add Keyboard Navigation
Add keyboard-based scrolling to viewport.

### Phase 9: Add Remaining Polish
Add padding control, column visibility toggle, table auto-width, data formatters.

### Phase 10: Final Review and Polish
Documentation sweep, test coverage, final verification.

## Each Phase Contains These Steps
1. Implementation step (coder subagent)
2. Review step (reviewer subagent)  
3. Fix issues step (coder subagent)
4. Update tests/workflows step (TestEngineer subagent)
5. Update documentation step (scribe/coder subagent)

## Updates File
All subagents should use `/home/sites/sugarcraft/sugar-table/.plans/updates.md` to communicate progress, issues, and blockers.

## Agent Instructions Location
Supervisor instructions: `/home/sites/sugarcraft/sugar-table/.plans/supervisor/instructions.md`
Step instructions: `/home/sites/sugarcraft/sugar-table/.plans/steps/{phase}-{step}/instructions.md`

## How to Run
The supervisor should be started with the prompt from the supervisor instructions file.
