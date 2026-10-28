# BRIEF — C-TOOL-3.b: TUI paints the live shell tail

- area: tool
- src: design-tool.md
- item: C-TOOL-3 (sources: GAP-SH-08, k2-sum live defect, k2-dorm)
- kind: dormant-wire · effort M
- phase: normal
- ruling: none
- wave: None | late: False
- deps: C-TOOL-3.a
- serialized (claims): [chat:applyBackendToolEvent,pumpLiveToolEvents,replaceToolRunningPlaceholder] [renderer:renderPendingToolCall] [lang:tui.tool.tail]

## Files to create
`candy-code/tests/Renderer/RunningToolTailTest.php`, `candy-code/tests/Chat/ToolTailFoldTest.php`

## Files to modify
`candy-code/src/Chat.php` (applyBackendToolEvent ToolProgress arm, pumpLiveToolEvents ToolProgress arm, replaceToolRunningPlaceholder), `candy-code/src/Renderer.php` (renderPendingToolCall + new private tailLines()), `candy-code/lang/en.php` (`tui.tool.tail_more`)

## Declared regions
{"candy-code/src/Chat.php": ["applyBackendToolEvent", "replaceToolRunningPlaceholder", "pumpLiveToolEvents", "ToolProgress"], "candy-code/src/Renderer.php": ["private", "renderPendingToolCall", "tailLines"], "candy-code/lang/en.php": ["tui.tool.tail_more"]}

## Tests
create the two above (golden paint, an over-wide line clipped, a bidi/C1 payload sanitised, the slot cleared on finish, a settled-queue replay paints nothing stale)

## Docs/guards
none

## Approach (verbatim)

`Chat.php` records `ToolProgress` and discards it in both folds: `applyBackendToolEvent()` :3702 (settled queue) and `pumpLiveToolEvents()` :4230 (live pump; the comment says "The TUI paints no live tail this wave"). Both arms call `ToolTailSlots::set()`, and `replaceToolRunningPlaceholder()` (:4345, beside `PreviewSlots::clear` :4349) clears the slot. `Renderer::renderPendingToolCall()` (`candy-code/src/Renderer.php:5623`) paints the last N (6) tail lines under the running row, after `previewLines()`. The lines are dim, control and bidi sanitised through the renderer's existing frame sanitiser, display-cell clipped with `clipWithEllipsis`, one line per row (render invariants), with a `… N earlier lines` caption when truncated. The stale comments are rewritten.

## Risks
repaint cadence follows the live pump; without ext-pcntl the tail appears only on settle (documented already for agent lines).
