# sugar-gallery

Poster **grids** and **rails** for media TUIs — a 2-D virtualized `PosterGrid`
for large libraries, a horizontal `Rail` carousel for browse rows, and a
`PosterCard` tile. The widgets are **renderer-agnostic**: a card holds
*already-rendered* poster bytes (produce them however you like — e.g.
[candy-mosaic](https://github.com/sugarcraft/candy-mosaic)), so this lib pulls
in no image decoder.

## Install

```sh
composer require sugarcraft/sugar-gallery
```

The poster pipeline example and its integration tests render real images, so they
pull [candy-mosaic](https://github.com/sugarcraft/candy-mosaic) in as a
*development* dependency only — the library itself never depends on it.

## PosterGrid — virtualized, sparse, owner-paged

The grid knows the **total** item count up front but holds only the cards that
have been fetched, keyed by their **absolute index**; missing indices render as
skeletons. Only the rows inside the viewport are drawn, so a 50,000-item library
renders as cheaply as a 50-item one.

```php
use SugarCraft\Gallery\PosterGrid;
use SugarCraft\Gallery\PosterCard;

$grid = PosterGrid::new(cardWidth: 16, posterHeight: 9)
    ->withViewport($cols, $rows)
    ->reset(total: 5000);          // a fresh result set

// Keyboard nav (map your keys to these — all clamp + keep the cursor on screen):
$grid = $grid->right();            // ← → move within a row
$grid = $grid->down();             // ↑ ↓ move between rows
$grid = $grid->pageDown();         // PgUp / PgDn
$grid = $grid->home()->end();      // Home / End
$grid = $grid->moveTo(2600);       // jump (e.g. an A–Z letter offset)
```

### Owner-driven paging (the `need-range` pattern)

After each move, read the visible window and fetch the page(s) covering it, then
splice the results back in at their absolute index. `needsFetch()` dedups the
request against the last range you loaded, so the cursor can roam inside an
already-fetched window without re-hitting your API:

```php
$want = $grid->visibleRange(overscanRows: 1);
if ($grid->needsFetch($lastFetched, overscanRows: 1)) {
    // fetch items [$want[0], $want[1]] from your API, build cards keyed by index…
    $grid = $grid->withItems([$want[0] => $card0, $want[0] + 1 => $card1, /* … */]);
    $lastFetched = $want;

    // Keep the sparse card map bounded on a huge library: drop everything the
    // owner is no longer near (the map otherwise only ever grows).
    $grid = $grid->withoutItemsOutside($grid->visibleRange(overscanRows: 8));
}
```

Async poster arrived for one cell? `->withItem($index, $card->withPoster($ansi))`.

### Per-cell poster fill (the async-fill policy)

A range fetch gives you *cards*; the artwork arrives later, one cell at a time.
`visibleCards()` and `indicesNeedingPoster()` are the grid's half of that loop —
which cells are on screen, and which of them are still empty:

```php
foreach ($grid->indicesNeedingPoster(overscanRows: 1) as $index) {
    // queue exactly the cells that are visible, loaded, and still skeleton-shaped
}

// A card whose art you cannot source (no URL, a rejected host, a lazy detail
// fetch) would otherwise be re-queued on every scroll. Your predicate replaces
// the default "has a posterUrl" test wholesale:
$pending = $grid->indicesNeedingPoster(
    overscanRows: 1,
    isFillable: static fn (PosterCard $c): bool => str_starts_with((string) $c->posterUrl, 'https://'),
);

// Skip cells whose rendered bytes are already in a disk cache, so you never
// queue a load that would resolve synchronously anyway:
$pending = $grid->indicesNeedingPoster(overscanRows: 1, isCached: $probeCache);
```

Both are **renderer-agnostic on purpose**: the grid knows geometry and sparseness,
nothing about image protocols, URL schemes, host allow-lists, SSRF policy, or how
many fetches may run at once. That is your application's transport domain, and it
stays in your loader — see [`examples/poster-grid-mosaic.php`](examples/poster-grid-mosaic.php)
for the whole pipeline against a real `DiskCache`.

`visibleCards($overscanRows)` returns the same window as `index => PosterCard`
(ascending, skeletons absent) — the iteration you would otherwise hand-roll with
`item($i)` in a loop, e.g. to release overlay-image handles for the cells that
just scrolled away.

### Render

```php
echo $grid->render(focused: true);     // cursor shown only when the grid is focused
```

Pass a [candy-zone](https://github.com/sugarcraft/candy-zone) `Manager` to make
cells mouse-clickable — each is wrapped as zone id `cell:<index>`:

```php
$frame = $grid->render(true, $zones);
$clean = $zones->scan($frame);                 // strip markers, record bounds
$zone  = $zones->anyInBounds($mouseMsg);       // → "cell:42"
```

## Rail — horizontal carousel

```php
use SugarCraft\Gallery\Rail;

$rail = new Rail('Continue Watching', $cards);
$rail = $rail->moveCursor(+1, Rail::perRow($railWidth, $cardWidth));
echo $rail->render($railWidth, focused: true, cardWidth: 16, posterHeight: 9);
```

## PosterCard — one tile

```php
use SugarCraft\Gallery\PosterCard;

$card = new PosterCard(id: '42', title: 'The Matrix', posterUrl: $url);
$card = $card->withPoster($renderedAnsi);   // attach when the async render lands
$card = $card->withProgress(0.6);           // optional continue-watching bar
echo $card->render(focused: true, width: 16, posterHeight: 9);
```

Every card row is exactly `width` cells wide and the grid normalizes each cell
to `cardWidth × (posterHeight + 2)`, so columns and rows always line up whether
or not a card carries a progress bar.

### Feeding it from candy-mosaic

The card holds *already-rendered* bytes, so the widget pulls in no decoder — but
[candy-mosaic](https://github.com/sugarcraft/candy-mosaic) is the renderer this
lib is built against, and the glue is three lines:

```php
use SugarCraft\Mosaic\{ImageSource, Mosaic, Scale};

$image  = ImageSource::fromFile($path);                    // or fromGd()/fromUrl()
$ansi   = Mosaic::halfBlock()->withScale(Scale::Fill)
    ->render($image, $cardWidth, $posterHeight);          // cells, not pixels
$grid   = $grid->withItem($index, $card->withPoster($ansi));
```

`Mosaic::render()` takes the *same* cell box the card reserves
(`cardWidth × posterHeight`), so the poster drops into the grid with no resizing
arithmetic on your side; anything too big or too small is boxed to the cell
anyway.

Pixel-graphics protocols (`sixel`, `kitty`, `iterm2`) are not cell text — a
`Mosaic` reports `isInline() === false` for them. Those bytes go to candy-mosaic's
`ImageLayer`, and the card carries only the marker the runtime paints over:

```php
$placed = $layer->placeTracked($bytes, $cardWidth, $posterHeight);   // ['sixel','kitty','iterm2']
$grid   = $grid->withItem($index, $card->withImage($bytes, $placed->imageId));
```

Cache the *rendered* bytes, not the source image — a render is expensive, a decode
less so, and the cache key must change when the terminal's protocol does:
`DiskCache::key($url, $width, $height, $mosaic->protocol())`.

Run the whole thing yourself (GD only, no network):

```sh
php examples/poster-grid-mosaic.php
```

### Title trust boundary

The plain `title` is treated as **untrusted** (it is typically DB-sourced):
`render()` runs it through `AnsiGuard::stripControls()` before drawing it, which
removes every control byte and every escape sequence — C0 (including `BEL`, `TAB`,
CR/LF, and the `ESC` that introduces a cursor-move or clear-screen), DEL, the 8-bit
C1 controls in both wire forms, and any SGR styling a plain title has no business
carrying. Opaque bytes that are simply not valid UTF-8 stay put: they are mojibake,
not controls, and one of them must never be able to disable the strip.

`withStyledTitle($ansi)` is the **escape hatch** for a *pre-styled* title (e.g. a
[candy-fuzzy](https://github.com/sugarcraft/candy-fuzzy) match highlight). It is
emitted **verbatim** — only ANSI-aware *truncated*, never stripped — because
sanitising it would destroy the very SGR escapes it exists to carry. That makes
**you** the trust boundary: pass only styling you produced yourself over
already-safe text, **never** raw untrusted / DB-sourced bytes. When the source
is untrusted, leave the styled title unset and rely on the sanitised plain
`title`.

Two opt-ins turn that documented contract into an enforced one. Both admit **SGR
styling only** (`ESC [ <params> m`) — every other escape form (cursor movement,
erase, OSC / DCS / APC payloads, bare C0 controls, an 8-bit C1 control written
either as the raw byte or as the UTF-8 encoding of U+0080–U+009F, a truncated
sequence) counts as unsafe, per `AnsiGuard`:

```php
// Fail fast: throws InvalidArgumentException (offset + hex of the offender)
$card->withStyledTitle($highlight, assertSafe: true);
PosterCard::assertSafeAnsi($highlight);           // same guard, standalone

// Coerce instead: keep the colour, drop anything else
$card = $card->withSafeStyledTitle($maybeHostile);
```

`AnsiGuard::isSafe()` / `::sanitize()` are public if you want to check or clean
before you build the highlight. Nothing about the default path changed —
`withStyledTitle($ansi)` is still verbatim, so existing callers are untouched.

## License

MIT © Joe Huss
