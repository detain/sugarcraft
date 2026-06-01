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
