# Caliber Learnings

Accumulated patterns and anti-patterns from development sessions.
Auto-managed by [caliber](https://github.com/caliber-ai-org/ai-setup) — do not edit manually.

- **[pattern:façade-delegation]** `FuzzyMatch` is a thin delegating shim over the sugar-fuzzy SSOT (`SmithWatermanMatcher` + `ScoringProfile`). The two-row DP scorer and its Smith-Waterman constants (adjacent +5, mismatch -3, gap open -5, extend -1) live in sugar-fuzzy, not here — the pre-delegation note describing them as lister internals was stale. Keep scoring-algorithm learnings in sugar-fuzzy's CALIBER_LEARNINGS; this lib only owns the `\Stringable`-item contract and the input-order tiebreak in `match()`.
- **[pattern:filter-state-machine]** List filter state is modelled as a two-state enum (`FilterState::unfiltered ⇄ filtering`). `withFilterFn()` clones the model, saves `originalItems` (the AUTHORITATIVE list), applies the filter, and sets `filtering`; while filtering, every mutation writes through to `originalItems` and re-derives the projection, so nothing is silently lost. `withoutFilter()` restores `originalItems` and resets to `unfiltered`. There is no separate `filtered` resting state — a live filter stays `filtering`. The enum documents the transition contract explicitly — never infer state from item count alone.

- Lang class now extends `SugarCraft\Core\I18n\Lang` — `t()` method inherited from base; NAMESPACE and DIR are the only per-lib constants.

## Mouse hit-testing

- There is no sugar-mouse dependency in this lib (composer requires are `php ^8.3`, `sugarcraft/sugar-core`, `sugarcraft/sugar-fuzzy` only) - hit-testing is host-side: the host maps pointer rows to list indices itself. The library-side law is the defensive sweep in `Model::renderItem()`: item text passes through `Sanitize::untrustedForMarkedFrames()` so forged mouse-zone sentinels (U+E000/U+E001) can never reach a rendered frame. Do not re-add a Manager dependency.

## Rendering (row-granular delta)

- `view()` holds a `?list<string> $previousRows` memo of the last frame's styled rows. First paint (and any resize, filter transition, or `resetPreviousFrame()`) emits a full frame; later paints compare rows one by one and repaint ONLY changed rows with an absolute cursor-position + erase-line (`ESC[<row>;1H <row> ESC[K`). Truth-update 2026-11-03: the previous cell-projection design against sugar-cellbuffer corrupted styled frames (SGR bytes landed as literal glyph cells) because `Cell`/`SgrEmitter` cannot round-trip palette SGR spellings — the dependency was retired with the rewrite.
- The memo is per-instance render state carried through clones: interleaving two forked lineages on one terminal without `resetPreviousFrame()` is unsupported — the README says so; do not "fix" by silently deep-copying.
- A failed frame must reset the memo (and dims) in the catch path, and the error string is untrusted text: sanitize it (`Sanitize::untrustedForMarkedFrames`) before it reaches a frame, or an exception message becomes a live escape channel.

## Layout invariants

- `lines()` uses a scroll-end clamp: the window never strands rows below the viewport bottom (`first = min(desired, totalLines - visible)`), and `cursorOffset` is render-clamped to `height - 1` — the advertised geometry is what the old renderer broke at end-of-list.
- Item values pass through `Sanitize::untrustedForMarkedFrames()` at the `renderItem()` choke AND the error sink: zone sentinels (U+E000/U+E001) must never survive into either frame sink or a forged mouse zone becomes possible (fleet marked-frame law).
- `hardWrap()` is whitespace-faithful (indentation and inter-word runs survive, empty lines are rows, tabs expand) and row-budgeted: `renderItem()` passes `wrap` (or height) as a hard ceiling so a pathological `Stringable` costs O(budget), never O(value).
- The `DefaultPrefixer` corner/join glyphs key on the item's POSITION (`currentIndex`/`totalItems`), not the line index — keying on line index made `├`/`╰` unreachable and the tree gutter decorative.

## State transitions

- While a filter is active, every mutation applies to the authoritative list and re-derives the projection (`reapplyFilter()`); `removeItem()` re-anchors the cursor by item identity. A filter is a live view, not a one-time snapshot.
- Cursor movement saturates (`withCursorUp(PHP_INT_MAX)` lands at 0, never overflows to float under `strict_types`); pages span `height` ITEMS, not displayed lines.
