# sugar-gallery — session learnings

Patterns and anti-patterns specific to this lib. Treat as project-specific rules.

- **Renderer-agnostic by design.** `PosterCard` holds *already-rendered* poster
  bytes (a string); it never decodes images. That keeps sugar-gallery off
  candy-mosaic (and ext-gd) — the consumer renders posters however it likes and
  hands the ANSI in via `withPoster()`. Don't add an image decoder dependency.
- **The grid is sparse + absolute-indexed.** `PosterGrid` stores cards keyed by
  ABSOLUTE index, not a packed list. Range fetches `withItems([$i => $card,…])`
  splice at the real offset so an A–Z jump to index 2600 shows that page, not the
  next appended page. This mirrors the web MediaGrid's `placePage()` /
  `need-range` design — keep them aligned.
- **Owner drives paging via `visibleRange()`.** The widget exposes the visible
  absolute-index window; it does NOT fetch. The screen reads the window after
  each move and calls its store. Returning `[0, -1]` for an empty grid lets the
  owner treat `start > end` as "nothing to fetch".
- **Uniform cells = `posterHeight + 2`.** Every cell is normalized to
  `cardWidth × (posterHeight + 2)` (poster + title + a reserved progress row) via
  `box()`, so a card with a progress bar and one without occupy the same height
  and the grid stays aligned. `box()` pads short cards and (defensively) clips
  tall ones.
- **Immutability via clone-mutate (PHP 8.3).** `PosterGrid` has too many fields
  for `new self(...)` everywhere, so it uses the sugar-dash `FocusManager`
  pattern: non-readonly private props + `$clone = clone $this; $clone->x = …`.
  `synced()` is the single choke point for cursor-clamp + scroll-follow; every
  navigation method routes through it and returns `$this` on a no-op so callers
  can detect "didn't move" by identity. `PosterCard`/`Rail` stay `readonly`.
- **candy-zone marks survive Layout joins.** Marking each cell `cell:<index>`
  *before* `Layout::joinHorizontal/joinVertical` works — the bubblezone-style
  markers are preserved through the joins and resolve correctly after `scan()`
  (verified in PosterGridTest). Mark only real cells (`idx < total`), not the
  blank trailing fillers.
