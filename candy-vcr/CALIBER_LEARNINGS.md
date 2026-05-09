# candy-vcr Caliber Learnings

Accumulated patterns and gotchas for this library.

---

## Cassette format is JSONL, not JSON

Each line is a self-contained JSON document. The first line is the
header (`{"v":1, ...}`); subsequent lines are events keyed by `k`
(`resize`, `input`, `output`, `quit`). This lets a recorder flush
each event as it happens without needing to rewrite a closing `]`,
and lets a player stream the file rather than load it whole.

Trailing newlines are tolerated. Empty lines between events are
ignored. Lines past the header that don't parse as JSON throw —
silent skip would mask cassette corruption.

## `Event::payload` is an associative array, not a typed value object

Each `EventKind` carries different fields (`resize` → cols+rows,
`output` → bytes, `input` → msg envelope, `quit` → empty). Encoding
those as separate classes would multiply the surface area for what
boils down to a JSON map. PR3 introduces `MsgSerializer` for the
`input` payload's `msg` envelope; until then, payload is opaque.

## `end()` and other by-reference array functions don't work on readonly properties

`end($this->events)` errors with "Cannot modify readonly property" because
`end` takes its argument by reference (it advances the internal array
pointer). Same trap for `reset`, `next`, `prev`, `array_pop`, `array_shift`,
`sort`, etc. on a `public readonly array $events`. Use index access
(`$this->events[count($this->events) - 1]`) or `array_key_last()` instead.
The error file:line points at the line that calls the by-ref function,
not at any assignment, which can be confusing.

## `t` is rounded to 3 decimals (ms) on write

Floating-point seconds with sub-microsecond precision serialize as
`0.000123456789012` and lose to round-off on read. JsonlFormat
rounds to 3 places on write — matches the plan's "ms precision" and
keeps cassettes diff-friendly.
