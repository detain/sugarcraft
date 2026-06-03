# Step 6.1: Implementation - Add Row Expansion/Collapse

## Goal
Add the ability to expand and collapse rows to show additional detail content.

## Background
Users often want to see more detail for a row without leaving the table. Row expansion allows showing extra content below or within a row.

## Implementation Tasks
1. Add state to track which rows are expanded - likely a `private array $expandedRows = []` storing row indices
2. Add `withExpandedRows(array $indices): self` to set expanded rows
3. Add `toggleExpanded(int $rowIndex): self` to toggle a specific row
4. Add `isExpanded(int $rowIndex): bool` to check if a row is expanded
5. Modify rendering to show expanded content when a row is expanded
6. Expanded content could be rendered as additional lines within the row (multiline approach) or as a separate section

## API Design
```php
// Expand a specific row
$t = $table->toggleExpanded(2);  // expand/collapse row 2

// Check if expanded
$isExpanded = $t->isExpanded(2);

// Set expanded rows directly
$t = $table->withExpandedRows([0, 2, 5]);
```

## Expanded Content Options
1. **Multiline approach**: Use multilineMode concept - expanded rows get extra lines
2. **Detail row approach**: Render a detail row below the main row with expanded content
3. **Both approaches could be supported**

For simplicity, use the multiline approach where expanded rows show their full content (no truncation).

## Key Files to Modify
- /home/sites/sugarcraft/sugar-table/src/Table.php
  - Add expandedRows tracking
  - Add methods for expansion control
  - Modify rendering to show expanded content

## Verification
- toggleExpanded changes row expansion state
- Expanded rows show full content
- Collapsed rows truncate as before

## Agent Type
Use the **coder** agent for implementation.

## Branch Name
`ai/table-row-expansion`

## Handoff Notes
- Make sure to run `vendor/bin/phpunit` after changes
- Consider: expanded content could be the full unwrapped content
- Keep backward compatibility - non-expanded rows should work exactly as before

## Exit Criteria
- Expansion methods work correctly
- Expanded rows show more content
- All existing tests still pass
- Code committed, PR created, merged to master
