# SugarCalendar — Caliber Learnings

Accumulated patterns and gotchas discovered while building and auditing
sugar-calendar.

---

## [pattern:buffer-cell-grid] Use Buffer for cell-grid rendering; ANSI SGR parse at render entry

The date grid is naturally a 7-column × N-row cell layout. Render into a
`Buffer`; only call `Buffer::toAnsi()` at the outermost render entry point.
This keeps SGR parsing isolated to a single call-site and ensures the cell
grid is available for hit-testing before serialization.

Source: step-34 ai/widget-shared

---

## [pattern:grid-index-cursor] Grid-index based cursor vs date-based cursor

The DatePicker's cursor is GRID-INDEX based (0-41), not date-based like
upstream bubbles/calendar (which uses DateTimeImmutable cursor).

When at grid index 0 and pressing Left, the cursor stays at 0 (no month wrap).
When at grid index 41 and pressing Right, the cursor stays at 41.

This is an architectural decision. If month-boundary wrap is desired,
the `MoveCursorLeft()` method would need to call `GoToPreviousMonth()` and
position the cursor at the last day cell of the previous month.

Source: ai/sugar-calendar-phase6

---

## [pattern:view-caching] Two-tier view caching for performance

DatePicker implements two-tier caching:

1. **Cells cache** (`$cachedCells`): The 42-cell grid array is cached and
   reused across `View()` calls with different display parameters (width,
   showWeekNumbers). Invalidated by all navigation/selection/cursor methods.

2. **View cache** (`$cachedView`): The fully rendered ANSI string is cached
   only for default parameters (width=21, showWeekNumbers=false). This is
   the common case for 60fps TUI scenarios.

Cache is invalidated by:
- Navigation: GoToPreviousMonth, GoToNextMonth, GoToPreviousYear, GoToNextYear, GoToToday, SetTime
- Selection: SelectDate, ClearDate, withRangeMode
- Cursor: MoveCursorLeft, MoveCursorRight, MoveCursorUp, MoveCursorDown, handleKey (Home/End)
- Styling: WithHeaderStyle, WithTodayStyle, WithSelectedStyle, WithCursorStyle, WithRangeStyle
- Today: withToday

Source: ai/sugar-calendar-phase6

---

## [pattern:configurable-viewport] Viewport width is configurable with range bounds

The `View()` method accepts a `$width` parameter (default 21, range 15-63).
Week number column adds 4 characters when enabled.

Cell layout: 3 characters per day column (2 for day number + 1 space).
A width of 21 gives exactly 7 day columns. Minimum 15 allows 5 columns.

Source: ai/sugar-calendar-phase6
