# sugar-gallery — session learnings

Patterns and anti-patterns specific to this lib. Treat as project-specific rules.

- **Renderer-agnostic by design.** `PosterCard` holds *already-rendered* poster
  bytes (a string); it never decodes images. That keeps sugar-gallery off
  candy-mosaic (and ext-gd) — the consumer renders posters however it likes and
  hands the ANSI in via `withPoster()`. Don't add an image decoder dependency.
  (candy-mosaic appears in `require-dev` ONLY, so the integration suite and
  `examples/poster-grid-mosaic.php` can prove the glue against real renders; a
  standalone `composer require sugarcraft/sugar-gallery` still pulls in no
  decoder. Keep it that way: nothing in `src/` may reference `SugarCraft\Mosaic`.)
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

## The candy-mosaic glue (proven by `MosaicPosterIntegrationTest`)

- **The recipe is `Mosaic::render() → PosterCard::withPoster() → PosterGrid::withItem()`.**
  Ask the renderer for the cell box the card already reserves
  (`render($image, $cardWidth, $posterHeight)`), so no resizing arithmetic lives
  here. `Scale::Fill` is what the media grids use (poster art should fill its box,
  letterboxing looks broken in a grid). `box()` still normalises the result, so a
  renderer that returns more rows than asked for cannot inflate the row.
- **Inline vs overlay is the only renderer fork that reaches the widget.** Half/
  quarter-block (and ascii/ansi256/truecolor) return cell text → `withPoster()`.
  sixel/kitty/iterm2 return an opaque blob that would shred a text frame →
  register it with candy-mosaic's `ImageLayer` and use `withImage($bytes, $id)`;
  the card then draws a one-cell `ImageOverlay::marker()` and the runtime paints
  the art on top. `hasPoster()` deliberately covers BOTH fill modes, so the grid's
  fill policy never has to know which one a cell is in — keep it that way.
- **Cache the rendered bytes, keyed by protocol.** `DiskCache::key($url, $w, $h,
  $mosaic->protocol())` — width, height and protocol all change the bytes, and
  candy-mosaic bumps its `FORMAT_VERSION` when a renderer is fixed so old entries
  retire themselves. A cache probe handed to `indicesNeedingPoster()` must build
  the same key or it silently never hits.
- **`indicesNeedingPoster()` / `visibleCards()` are the promoted half of the
  per-cell async-fill loop** that used to be duplicated across every consumer
  screen. They encode only *grid* knowledge — window + overscan, "a card is
  loaded here", "this cell has no art yet". URL resolution against a server base,
  scheme/host/SSRF policy, concurrency limits, overlay-id bookkeeping and the
  Cmd/Msg plumbing stay in the app: pass them as `$isFillable` / `$isCached`
  callbacks rather than teaching the grid about any of them. A card the owner
  cannot source art for must be filtered OUT by the caller's predicate, or it gets
  re-queued on every scroll.
- **GD makes an offline image test possible** — `imagecreatetruecolor()` +
  `ImageSource::fromGd($gd, 'image/png')` (there is no `new GdImage(...)` in PHP 8;
  GD objects only come from GD functions). Vary the image per fixture:
  `ImageLayer` dedups placements by content hash and `PosterGrid::box()` memoizes
  by content, so six identical posters silently exercise one code path.

## Trust boundaries

- **A styled title is echoed verbatim, so `AnsiGuard` is what makes that safe.**
  SGR (`ESC [ <digits ; : > m`) passes; every other ECMA-48 escape form (CSI with
  any other final byte, OSC, DCS/SOS/PM/APC, charset designators, Fe/Fs pairs),
  every C0 control (including TAB/CR/LF — a title is one row) and DEL do not.
  `withStyledTitle($ansi)` is unchanged for BC; `assertSafe: true` throws,
  `withSafeStyledTitle()` coerces. One scanner (`AnsiGuard::runs()`) backs
  `isSafe`/`assertSafe`/`sanitize`, so the three can never disagree, and
  `sanitize()` is byte-preserving on safe input + idempotent — both pinned by tests.
- **`withSafeStyledTitle()` returns `$this` when sanitising leaves an empty
  string**, rather than setting a styled title that would render a blank row: the
  plain title is the sanitised path, so falling back to it is strictly better than
  blanking the cell. Same identity-means-no-op convention as `synced()` and
  `withoutItemsOutside()`.
- **`findings/sugar-gallery.md` predates this lib's rewrite** (it audits a
  `Gallery.php`/`FFIDecoder.php` that no longer exist). Do not treat that file as
  current: verify against `src/` before acting on a finding from it.
