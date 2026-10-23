# SugarDatepicker — Caliber Learnings

Accumulated patterns and gotchas discovered while building and auditing
sugar-datepicker.

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

Source: ai/sugar-datepicker-phase6

---

## [pattern:view-caching] DO NOT cache views on this immutable type (retired)

This entry used to document a two-tier view cache (`$cachedCells` +
`$cachedView`) that DatePicker no longer has. The cache was deliberately
REMOVED: every mutator here returns a fresh clone, so any instance-keyed
render cache is stale-by-construction the moment a clone is mutated through
another handle, and the invalidation roster (navigation/selection/cursor/
styling/today — fifteen methods) was pure footgun surface. The current law:
`View()` rebuilds cells and the ANSI string from instance state on every
call; same input, same bytes, no hidden state. `testViewDoesNotMutateInstance`
and `testViewIsDeterministicAcrossCalls` pin it.

Source: ai/sugar-datepicker-phase6 (cache era) — rewritten 2026-10-23
docs-fix-1023 E2 after the README/learnings were found teaching deleted
machinery.

---

## [pattern:configurable-viewport] Viewport width is configurable; out-of-range is clamped

The `View()` method accepts a `$width` parameter (default 21) and a
`$showWeekNumbers` flag. Widths outside 15-63 are silently CLAMPED into
that range, not rejected — the clamp is the contract.
Week number column adds 4 characters when enabled.

Cell layout: 3 characters per day column (2 for day number + 1 space).
A width of 21 gives exactly 7 day columns. Minimum 15 allows 5 columns.

Source: ai/sugar-datepicker-phase6 — wording corrected 2026-10-23
docs-fix-1023 E2 (range "bounds" were always clamps; now pinned by
`testViewWidthIsClampedNotRejected`).

---

## [pattern:api-casing-debt] PascalCase and camelCase coexist in the public API

DatePicker mixes two casings: the legacy charmbracelet-port surface is
PascalCase (`GoToPreviousMonth`, `SelectDate`, `View`, `SetTime`,
`MoveCursor*`, `With*Style`, `IsSelecting`) while the house immutable/setter
surface is camelCase (`withMinDate`, `handleKey`, `rangeMode`, `inBounds`,
`withoutToday`). This is documented debt, NOT to be "fixed" silently — the
PascalCase names are the published Packagist surface and renaming them is a
hard break. New methods follow camelCase (house convention); when a rename
program lands, route it through a deprecation alias, never a substitution.

Source: 2026-10-23 docs-fix-1023 E2
