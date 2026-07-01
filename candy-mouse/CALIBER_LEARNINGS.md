# Caliber Learnings — candy-mouse

> Accumulated patterns and gotchas discovered while building and using
> candy-mouse. New contributors: read this before touching the code.

## Sentinel codepoints

- Sentinel triple uses Unicode Private Use Area codepoints U+E000
  (open) and U+E001 (close).
- **Do not** collapse U+E000+ during text normalisation** — the
  sentinel codepoints would be lost and zones would fail to parse.
- These codepoints are guaranteed not to appear in any ANSI escape
  sequence (CSI starts with ESC [, OSC with ESC ]) and are invisible
  in terminal emulators.

## CJK column accounting

- `Width::string()` from candy-core is used during scan to account for
  wide East-Asian characters (2 cell columns each).
- Always use `Width::string()` for column arithmetic; do not use
  `strlen()` or `mb_strlen()`.

## ZoneClickTracker state machine

- Pending state is stored **per button** so multi-button mice work
  correctly.
- A null zone at press time (click outside any zone) clears the state
  on release without emitting a click.
- `setPressZone()` must be called by the caller immediately after
  `track(Press)` when the scanner is available — `ZoneClickTracker`
  cannot resolve the zone itself without a reference to the scanner.

### 2026-05-28 — Sentinel codepoints are PUA, not OSC 1337

Pattern: candy-mouse uses private-use Unicode PUA U+E000/U+E001 for
zone sentinel markers. These codepoints are guaranteed safe from ANSI SGR
sequence clobbering — they never appear in CSI (ESC [) or OSC (ESC ])
 byte ranges.
Anti-pattern: Do not normalise or collapse U+E000+ during text
processing — the sentinel codepoints would be lost and zones would fail to
parse.
Source: step-05 ai/candy-mouse-new

## Synchronous-only design (async gap)

- candy-mouse is **synchronous-only** — no ReactPHP async integration.
- `ZoneClickTracker::track()` and `Scanner::hit()` are blocking operations.
- If async TUI use cases emerge, consider an async variant of the scanner.
Source: plan_candy-mouse.md — Item 5.1

## O(n) Scanner::hit() — spatial index opportunity

- `Scanner::hit()` performs a linear O(n) scan over all zones.
- For n > 100 interactive zones (tables, lists), consider a grid-based
  spatial index or sorting zones by area as a heuristic.
- This is a known limitation documented in `Scanner.php:83-86`.
Source: plan_candy-mouse.md — Item 5.2

## Memoization opportunity for repeated scans

- `Scan::parse()` processes the entire rendered string in one pass.
- For static UIs where the terminal buffer rarely changes, caching or
  memoizing scan results could avoid redundant parsing.
- A `ScanIterator` (implementing `IteratorAggregate`) could also yield
  zones incrementally to support streaming renderers.
Source: plan_candy-mouse.md — Item 5.3
