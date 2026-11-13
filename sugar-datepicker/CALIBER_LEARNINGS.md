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
the `moveCursorLeft()` method would need to call `goToPreviousMonth()` and
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
`view()` rebuilds cells and the ANSI string from instance state on every
call; same input, same bytes, no hidden state. `testViewDoesNotMutateInstance`
and `testViewIsDeterministicAcrossCalls` pin it.

Source: ai/sugar-datepicker-phase6 (cache era) — rewritten 2026-10-23
docs-fix-1023 E2 after the README/learnings were found teaching deleted
machinery.

---

## [pattern:configurable-viewport] Viewport width is configurable; out-of-range is clamped

The `view()` method accepts a `$width` parameter (default 21) and a
`$showWeekNumbers` flag. The clamp is on the TOTAL width (the `width`-wide
calendar area plus the optional 4-cell week column): it is bounded BELOW by the
natural grid (21 without a week column, 25 with one) and ABOVE by 63. Requests
outside that band are silently CLAMPED, not rejected — the clamp is the contract.
The lower bound guarantees all seven day columns stay visible; a narrower request
can no longer amputate the Friday/Saturday columns.

Cell layout: 3 characters per day column (2 for day number + 1 space).
A width of 21 gives exactly 7 day columns; below the natural grid the request is
raised to it.

Source: ai/sugar-datepicker-phase6 — wording corrected 2026-10-23
docs-fix-1023 E2 (range "bounds" were always clamps; now pinned by
`testViewWidthIsClampedNotRejected`).

---

## [pattern:api-casing-debt] PascalCase and camelCase coexist in the public API

DatePicker mixes two casings: the legacy antecedent-derived surface
(design antecedent EthanEFung/bubble-datepicker) is PascalCase
(`GoToPreviousMonth`, `SelectDate`, `View`, `SetTime`,
`MoveCursor*`, `With*Style`, `IsSelecting`) while the house immutable/setter
surface is camelCase (`withMinDate`, `handleKey`, `isRangeMode`, `inBounds`,
`withoutToday`). This is documented debt, NOT to be "fixed" silently — the
PascalCase names are the published Packagist surface and renaming them is a
hard break. New methods follow camelCase (house convention); when a rename
program lands, route it through a deprecation alias, never a substitution.

Source: 2026-10-23 docs-fix-1023 E2

STATUS 2026-11-13 (sugar-datepicker-013 / dp-02): the rename program LANDED
by declaration flip — every public method is DECLARED camelCase and the
PascalCase spellings remain resolvable call sites (PHP method names are
case-insensitive), demoted to a deprecated legacy layer the README
deprecation table maps one-for-one. A delegate-twin alias was impossible
(a same-class Pascal twin is a redeclare fatal), so the declarations moved
instead; `tests/ApiAliasTest.php` pins the zero-Pascal-declaration law and
byte parity per spelling. Do not re-introduce PascalCase declarations.

---

## [ruling:public-ctor-exception] VOs and stores ship public ctors + `::new()` mirrors

AGENTS.md doctrine: `::new()` is the advertised factory, private ctors the
goal. This lib deviates INVENTORY-WIDE — `DatePicker`, `Model`, `DateRange`
and `EventStore` all keep PUBLIC constructors because in-repo and host code
construct them bare (promoted-property ctors double as the documented shape).
Ruling: legal deviation, declared here, with `::new()` mirrors added wherever
missing (DateRange, EventStore) so the advertised entry exists; a v2 flip to
private ctors is TRACKED as a hard-break item, not to be done silently.
Documentation must keep pointing readers at `::new()`; `new DateRange(...)` /
`new EventStore(...)` in examples/README are treated as drift.

Source: sugar-datepicker-033/035 review (dp-30), 2026-11-13

---

## [ruling:clone-not-mutable] Immutable withers via per-method `clone $this`, no Mutable trait

`sugar-core/src/Concerns/Mutable.php` with its paired `bool $XSet` sentinels
is the canonical fluent shape; DatePicker instead clones per method with
plain nullable fields where null-means-absent (`minDate`, `maxDate`,
`today`). Ruling: deviate, declared — every absence here IS a null semantic
(no date bound vs a bound set to "none"), the XSet duplication would model a
distinction nobody renders, and the withers carry validation (bounds
cross-checks, `assertSgr`) that a generic `mutate()` seam would spread.
The per-method `clone $this` shape at e.g. `withMinDate()`/`withMaxDate()`
is the stamp of this ruling; do not "converge" it onto the trait.

Source: sugar-datepicker-035 review (dp-30), 2026-11-13
