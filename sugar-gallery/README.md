# sugar-gallery

Poster **grids** and **rails** for media TUIs — a 2-D virtualized `PosterGrid`
for large libraries, a horizontal `Rail` browse strip for rows like "Continue
Watching", and a
`PosterCard` tile. The widgets are **renderer-agnostic**: a card holds
*already-rendered* poster bytes (produce them however you like — e.g.
[sugar-image](https://github.com/sugarcraft/sugar-image)), so this lib pulls
in no image decoder. It is a **library, not a viewer app** — no binary ships;
a runnable pure-PHP demo lives at [`examples/poster-grid.php`](examples/poster-grid.php).
There is **no in-tree consumer yet** — the examples are the current integration proof.

## Install

```sh
composer require sugarcraft/sugar-gallery
```

> The package is pinned `dev-master` like every sugarcraft sibling — no stable
> tags exist yet, so a plain `composer require` needs `"minimum-stability": "dev"`
> with `"prefer-stable": true` in your root `composer.json` (or an explicit
> `sugarcraft/sugar-gallery: dev-master` constraint).

The poster pipeline example and its integration tests render real images, so they
pull [sugar-image](https://github.com/sugarcraft/sugar-image) in as a
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
    ->withReset(total: 5000);          // a fresh result set

// Keyboard nav (map your keys to these — all clamp + keep the cursor on screen):
$grid = $grid->right();            // ← → step one cell (crosses row edges)
$grid = $grid->down();             // ↑ ↓ move between rows
$grid = $grid->pageDown();         // PgUp / PgDn
$grid = $grid->home();             // Home
$grid = $grid->end();              // End
$grid = $grid->moveTo(2600);       // jump (e.g. an A–Z letter offset)
```

The lib owns **no** keys — these methods are yours to map however you like (the
legends in this section are illustrative; there is no built-in help-key set):

```php
return match ($key) {
    '↓' => $grid->down(),
    'J' => $grid->down(),      // vim-style, if that's your scheme
    default => $grid,
};
```

It also owns **no colour**: every frame ships unstyled text, and any SGR you see
in output rode in on *your* card bytes (`withPoster()` / `withStyledTitle()`).
Theming is entirely yours. Six chrome glyphs matter at narrow layouts, and they
split four-and-two. (Four are East-Asian-Ambiguous width — `●` `U+25CF` and `○` `U+25CB`
(the rail selection bullets), `▓` `U+2593` (the filled leg of the progress bar) and `…`
`U+2026` (truncation ellipsis). Most modern terminals render them single-cell, but
a user's ambiguous-width setting can widen them by a cell — no font install required.
The other two are neutral by codepoint and stay put: the focus marker is `▸`
`U+25B8` — the *small* black triangular right, not the ambiguous large `▶` `U+25B6`
it is so easily confusable with — and the skeleton/placeholder shade is `░` `U+2591`.)

### Owner-driven paging (the `need-range` pattern)

After each move, read the visible window and fetch the page(s) covering it, then
splice the results back in at their absolute index. `needsFetch()` dedups the
request against the last range you loaded, so the cursor can roam inside an
already-fetched window without re-hitting your API. Initialise `$lastFetched =
null` once before the first move — `needsFetch()` treats null as "nothing loaded
yet" and always fetches:

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
Splice contract: a non-numeric key (PHP casts `'2'`/`2.7`/`true` to int before they ever reach
the guard) or a non-card value in `withItems()` throws `InvalidArgumentException`
and nothing lands (the partial merge is discarded), while a *negative* index is accepted and
quietly dropped by both setters (`withItem()` returns unchanged) — deliberate, so a host can splice
a whole API page without pre-filtering it.

### Per-cell poster fill (the async-fill policy)

A range fetch gives you *cards*; the artwork arrives later, one cell at a time.
`visibleCards()` and `indicesNeedingPoster()` are the grid's half of that loop —
which cells are on screen, and which of them are still empty (`PosterCard::hasPoster()`
false — never-fetched, poisoned, or failed cells):

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

When the cursor sits on an unfetched cell, the ▸ runs down **every** poster row of
that skeleton — a whole-tile ghost of the cursor, not a single-row tick. This is
pinned intentional: a one-row marker would read as a rendering glitch on a tile the
grid has not painted yet.

An empty grid (`isEmpty()`) renders `''` — zero rows, not a blank frame — so the
owner can tell "nothing here yet" from "framed empty cells" and paint its own
empty-state in its own chrome (`PosterGrid::emptyMessage()` hands you the
localised `grid.empty` sentence if you want the library's wording). Need the
position readout without the chrome? `positionText()` returns the
`  (n/total)` counter string (`''` when there is nothing to position), and
`fitsViewport($cols, $rows)` answers whether a single cell fits at all — with no
arguments it checks against the grid's own current viewport — below
`cardWidth` × `cellHeight` the layout silently floors at one column and one
row, so a too-narrow viewport *lies*; detect it with this predicate instead.

Pass a [sugar-mouse](https://github.com/sugarcraft/sugar-mouse) `Manager` to make
cells mouse-clickable — each is wrapped as zone id `cell:<index>`:

```php
$frame = $grid->render(true, $zones);
$clean = $zones->scan($frame);                 // strip markers, record bounds
$zone  = $zones->anyInBounds($mouseMsg);       // → Zone|null — read $zone->id ("cell:42")
```

Zone ids cover every cell in the window, skeleton placeholders included — before
acting on a `cell:<index>` hit, check `item($index)` / `needsFetch()` so a click
on an unfetched cell triggers a load rather than a selection.

## Rail — horizontal browse strip

```php
use SugarCraft\Gallery\Rail;

$rail = Rail::new('Continue Watching', $cards);
$rail = $rail->moveCursor(+1, Rail::perRow($railWidth, $cardWidth));
echo $rail->render($railWidth, focused: true, cardWidth: 16, posterHeight: 9);
```

The heading (field: `title`) rides the **same** title guard as a card —
`AnsiGuard::stripControls()`
plus a rail-width clamp — and the empty state is localised
(`Lang::t('rail.empty')`, see [Internationalisation](#internationalisation)).
`moveCursor()` takes a *delta*. To restore an arbitrary `(cursor, scroll)` pair —
e.g. returning to a remembered position — construct via `Rail::new(...)`: its
third and fourth arguments are that pair. A stale pair is clamped on
construction, not restored verbatim: the cursor lands inside the card list and
the scroll at or below it (`[0, cursor]`).

## PosterCard — one tile

```php
use SugarCraft\Gallery\PosterCard;

$card = new PosterCard(id: '42', title: 'The Matrix', posterUrl: $url);
// (`PosterCard`'s constructor is public and its `::new()` mirrors it;
//  `PosterGrid` has a *private* constructor — always build it via `PosterGrid::new(...)`.)
$card = $card->withPoster($renderedAnsi);   // attach when the async render lands
$card = $card->withProgress(0.6);           // optional continue-watching bar
echo $card->render(focused: true, width: 16, posterHeight: 9);
```

The bar `withProgress()` draws carries a numeric readout when the row has room: the
`▓`/`░` run is followed by an exact `(NN%)` label — the glyph run alone saturates, so
a wide-enough download is picturally indistinguishable from a finished one. Below two
bar cells of room the label is dropped and the run reclaims its cells; there the
bar's unfilled leg shares the skeleton's `░` glyph (sugar-gallery-040), so a
downloading card is distinguished from a not-yet-started one by its `▓` leg only. A
host that needs a guaranteed readout — anything that cannot see the bar at all —
surfaces the percentage itself.

Every card row is exactly `width` cells wide and the grid normalizes each cell
to `cardWidth × (posterHeight + 2)`, so columns and rows always line up whether
or not a card carries a progress bar. The one exception: a verbatim
`withPoster()`/`withStyledTitle()` frame carrying an UNTERMINATED escape sequence
can shift the pad count — the box fit treats it as one open run to end-of-line,
and bytes you hand the card verbatim are caller-owned.

### Feeding it from sugar-image

The card holds *already-rendered* bytes, so the widget pulls in no decoder — but
[sugar-image](https://github.com/sugarcraft/sugar-image) is the renderer this
lib is built against, and the glue is three lines:

```php
use SugarCraft\Image\{ImageSource, Mosaic, Scale};

$image  = ImageSource::fromFile($path);                    // or fromGd()/fromUrl()
$ansi   = Mosaic::halfBlock()->withScale(Scale::Fill)
    ->render($image, $cardWidth, $posterHeight);          // cells, not pixels
$grid   = $grid->withItem($index, $card->withPoster($ansi));
```

(`Scale::Fill` stretches art to the exact cell box and **distorts aspect** when
the poster ratio differs from `cardWidth × posterHeight` cells; pair
`Scale::Fit` with your own centering if you need the ratio preserved.)

`Mosaic::render()` takes the *same* cell box the card reserves
(`cardWidth × posterHeight`), so the poster drops into the grid with no resizing
arithmetic on your side; anything too big or too small is boxed to the cell
anyway.

Pixel-graphics protocols (`sixel`, `kitty`, `iterm2`) are not cell text — a
`Mosaic` reports `isInline() === false` for them. Those bytes go to sugar-image's
`ImageLayer`, and the card carries only the marker the runtime paints over:

```php
$placed = $layer->placeTracked($bytes, $cardWidth, $posterHeight);   // sixel / kitty / iterm2

if ($placed->imageId !== null) {                 // null once the 6398-marker window is full
    $grid = $grid->withItem($index, $card->withImage($bytes, $placed->imageId));
}
```

`imageId` is nullable for exactly that reason: the overlay addresses images with
private-use-area cells, and there are only 6398 of them (sugar-core's
`ImageOverlay::MAX_IMAGES`),
so an exhausted layer leaves the cell as the skeleton instead of painting the wrong art.

Cache the *rendered* bytes, not the source image — a render is expensive, a decode
less so, and the cache key must change when the terminal's protocol does:
`DiskCache::key($url, $width, $height, $mosaic->protocol())`.

Run the whole thing yourself (GD only, no network):

```sh
composer install          # the mosaic example needs the dev-only sugar-image dep…
php examples/poster-grid-mosaic.php   # …and a PHP built with ext-gd
```

composer pulls neither automatically — `suggest` entries are advisory. The library
itself needs **PHP >= 8.3 and zero extensions** (its runtime deps are sugar-core, sugar-style
and sugar-mouse); ext-gd is required only by sugar-image, which the mosaic
example and the integration tests use. The terminal baseline: all chrome is Unicode (`░ ▓ ▸ ● ○ …`) and there is **no
ASCII fallback** — the widgets need a UTF-8 terminal with block-element
coverage, or the skeleton, the focus cue and the progress bar arrive mojibaked.
Both examples are non-interactive
one-shot dumps — no key loop (map your own keys per the *Keyboard nav* legend in
the first PosterGrid example above) — and assume a truecolour terminal.

`examples/poster-grid.php` by contrast is pure PHP — no image stack, no extensions.

### Title trust boundary

The plain `title` is treated as **untrusted** (it is typically DB-sourced):
`PosterCard::render()` runs it through `AnsiGuard::stripControls()` before
drawing it — and **so does the `Rail` heading** —
removing every control byte and every escape sequence: C0 (including `BEL`,
`TAB`, CR/LF, and the `ESC` that introduces a cursor-move or clear-screen),
DEL, the 8-bit C1 controls in both wire forms, and any SGR styling a plain
title has no business carrying. Both rail header lines (title and empty
state) are additionally clamped to the rail width with an ellipsis, so no
title — and no locale — can overflow it. Bytes that are simply not valid UTF-8
stay put: they are mojibake, not controls, and one of them must never be able
to disable the strip. The single exception is a byte in the 8-bit C1 band that
no well-formed sequence has claimed. A terminal reads that one as a control,
so it goes.

`withStyledTitle($ansi)` is the **escape hatch** for a *pre-styled* title (e.g. a
[sugar-fuzzy](https://github.com/sugarcraft/sugar-fuzzy) match highlight). It is
emitted **verbatim**: it is only ever truncated (ANSI-aware, and marked with the
same `…` the plain path shows on overflow; sugar-gallery-032), never stripped,
because sanitising it would
destroy the very SGR escapes it exists to carry. That makes
**you** the trust boundary: pass only styling you produced yourself over
already-safe text, **never** raw untrusted / DB-sourced bytes. When the source
is untrusted, leave the styled title unset and rely on the sanitised plain
`title`.

Four entry points turn that documented contract into an enforced one — one guard
flag, its standalone twin, and two coercing setters. All four admit **SGR
styling only** — `ESC [` followed by nothing but digits, `;` and `:` until a final
`m`; a private (`ESC [ ?…m`) or intermediate-byte SGR (a `… [` with a space before
the final `m`) is *not* accepted — every other escape form (cursor movement,
erase, OSC / DCS / APC payloads, bare C0 controls, an 8-bit C1 control written
either as the raw byte or as the UTF-8 encoding of U+0080–U+009F, a truncated
sequence) counts as unsafe, per `AnsiGuard`:

```php
// Fail fast: throws InvalidArgumentException (offset + hex of the offender)
$card->withStyledTitle($highlight, assertSafe: true);
PosterCard::assertSafeAnsi($highlight);           // same guard, standalone

// Coerce instead: keep the colour, drop anything else
$card = $card->withSafeStyledTitle($maybeHostile);
$card = $card->withSafePoster($bytesFromYourCache);   // same sweep for poster bytes
```

`AnsiGuard::isSafe()` / `::sanitize()` are public if you want to check or clean
before you build the highlight. `withStyledTitle(assertSafe: true)` and
`assertSafeAnsi()` classify; they do not sweep: they see the zone sentinels
`U+E000`/`U+E001` as ordinary printable text and pass them (`isSafe()` on a
forged sentinel pair answers true). Sweeping them is the sweepers' job — both
`sanitize()` and `stripControls()` run
`Sanitize::stripZoneSentinels()` inline before the ANSI scan, and
`AnsiGuard::stripSentinels()` does exactly that and nothing else.
Nothing about the default path changed —
`withStyledTitle($ansi)` is still verbatim, so existing callers are untouched.

**Poster bytes carry the same contract as styled titles.** `withPoster($ansi)`
is emitted verbatim by design (cell-text art is SGR + block glyphs, and a strip
would eat the art); whoever produced those bytes owns their safety — generate
them yourself (e.g. a `Mosaic` render of an image you chose). If your poster can
come from somewhere you do not control, `withSafePoster($ansi)` applies the SGR
whitelist sweep row-by-row: colours and block glyphs stay byte-identical, every
cursor-move / OSC / DCS / control sequence goes. `withImage($bytes, $id)` overlay
bytes never enter the text frame at all — the frame carries only the marker
cell, the bytes themselves ride on the card —
but they travel their own protocol's trust boundary on the way to the screen.

**Zone sentinels are swept by the coercing setters and again at paint.** sugar-mouse marks hit-zones with
the private-use-area sentinel pair `U+E000`/`U+E001` around the zone id; forged
sentinels inside untrusted titles or poster text would otherwise splice a phantom
zone (a click target over nothing) or a duplicate zone id (a throw in the app's
click path) straight into the frame. `stripControls()` / `sanitize()` /
`withSafePoster()` / `withSafeStyledTitle()` perform the surgical sweep
(`AnsiGuard::stripSentinels()`); the verbatim setters (`withPoster()`,
`withStyledTitle()`, `withImage()`) deliberately store exactly the bytes you
handed them, so the sweep runs again downstream — the grid sweeps every card
body on its way into the box memo (the swept bytes ride the cached frame) and
marks only swept output, and `Rail::render()` sweeps its own title —
so even verbatim `withPoster()` bytes cannot reach the marking choke point with
forgery intact. Stored bytes can still be verbatim, so the law at the frame's
own edge stays yours: an app that composes frames for a marker-consuming
renderer sanitises the assembled frame at its own boundary, not here.
ImageOverlay art markers live at
`U+E002` and above, outside the sentinel window: they survive every sweep.
That survival has a visible edge: an overlay poster renders as a single
`U+E002`-range marker cell (top-left of the reserved block; the runtime paints
the real graphics there), so a path that extracts *text* from a rendered frame —
search, clipboard, a screen scrape — reads unassigned private-use glyphs at that
cell. For those consumers sugar-core ships `Sanitize::stripPrivateUse()`, which
sweeps the whole private-use block out of extracted text; the frame itself must
keep the marker, or the image renderer has nothing to find.

## Internationalisation

`Lang::t()` resolves through `src/Lang.php` (namespace `gallery`) over the 16-locale
family in `lang/` — ar · cs · de · en · es · fr · it · ja · ko · nl · pl · pt · pt-br ·
ru · tr · zh-cn — with `en.php` as the source of truth (`lang/pt-br.php` is a
partial Brazilian voice: its `grid.empty` reads `(sem pôsteres)` where European
`lang/pt.php` keeps `(sem cartazes)`, while the other two keys still match pt).
Three keys live there: `rail.empty` (the Rail heading's empty
state), `card.failed` (the notice on a `withFailed()` card) and `grid.empty`
(surfaced by `PosterGrid::emptyMessage()`). They resolve **live at render time**
— nothing is baked into the widgets — so a mid-session `T::setLocale()` flips
the notices on the next frame. `tests/LocaleParityTest.php` pins the file family,
the key set, and the ordering against `en.php`, so a new key must land in all 16
files (or nowhere) to stay green. Locale selection follows the standard
`LC_ALL` → `LC_MESSAGES` → `LANG` chain of `sugar-core`'s `T::detect()` — but
detection is **opt-in**: *your app* calls `T::setLocale(T::detect())` at startup,
and until it does the widgets answer in `en`. The library never mutates process
locale state itself. One layout caveat: the Rail heading keeps fixed left-to-right
order (bullet, title, counter) whatever the locale — under `ar` the notices
render right-to-left while that line's parts stay LTR. Compose the heading from
the accessors yourself if you need a fully mirrored rail.

## API surface

Beyond the workflow above, the public surface is small and mostly self-evident:

**PosterGrid** — navigation `left()` / `right()` (one cell, crossing row edges,
clamped at both ends), `up()` / `down()` (one row; `down()` steps onto the last
item on a partial bottom row), `pageUp()` / `pageDown()` (one viewport:
`columns() × visibleRows()`), `home()` / `end()`, `moveTo($index)` (clamped).
State: `total()`, `loadedCount()` (cards loaded *within* the current total —
entries the total shrank past do not count), `cursorIndex()`, `cursorRow()`,
`scrollRow()`, `item($i)` / `cursorCard()` (both nullable — `null` until the
index is fetched), `isEmpty()`.
Geometry: `columns()` = `max(1, intdiv(cols + hSpacing, cardWidth + hSpacing))`,
`visibleRows()`, `totalRows()`, `cellHeight()` (= `posterHeight + 2`).
Result set: `withReset($total)`, `withTotal($total)` (keeps cached cards, clamps the
cursor), `withItem()` / `withItems()`, `withoutItemsOutside([$a, $b])`,
`withViewport($cols, $rows)`.
Window: `visibleRange($overscan)`, `visibleCards($overscan)`,
`needsFetch($lastFetched, $overscan)`, `indicesNeedingPoster(...)` — all documented
above. Affordances: `positionText()` and `fitsViewport()` (explicit viewport, or
the grid's own when called argument-free); the static
`PosterGrid::emptyMessage()` returns the localised empty-grid notice.

**Rail** — `withCards()` / `withCard()` (unknown id: silent no-op) /
`withTitle()` / `withCursor()` (clamped into the card list),
`moveCursor($delta, $perRow)` (scrolls minimally to keep the cursor in view),
`focusedCard()`, `isEmpty()`, and the static layout helper
`Rail::perRow($railWidth, $cardWidth, $spacing)` (how many cards fit a row) and
`Rail::frameHeight($posterHeight)` (how many lines `render()` emits — head, poster
rows, title, plus a progress-bar row when ANY loaded card carries progress; a bar on
an off-window card reserves a line the paint omits, so the bound is upper, never
lower). `Rail::render()` takes `focused:` as a
**required** argument — the asymmetry against `PosterGrid::render(bool $focused =
true)` is deliberate (a rail in a media browser is usually *not* the focused pane
and defaulting it lit up the wrong row in early apps). `Rail::render()` takes no
zones manager — rails are painted chrome; only `PosterGrid::render(focused, $zones)`
marks hit-test zones.

**PosterCard** — the unavailable state: `withFailed()` / `isFailed()` (renders
`?` rows under the localised `card.failed` notice, counts as unfilled to
`hasPoster()`, so the fill policy re-queues the cell). Plus two cache levers:
`PosterCard::clearFitCache()` and `PosterGrid::clearBoxCache()` drop process-wide
memos. Both memos are internally capped — `PosterCard::FIT_CACHE_CAP` and
`PosterGrid::BOX_CACHE_CAP` are **private** constants, so referencing either
name from your code raises a fatal error (copying the number is fine; it simply
is not part of the API — the sanctioned levers are the clear methods). The two caps are
implemented differently (by-ref method-static vs plain static) on purpose.
Each memo holds at most 2048 entries, evicting the oldest half when full, so
resident bytes stay bounded (worst case ≈ 2048 frames per memo). Clearing returns an entry count
and never changes a single rendered byte.

Geometry is clamped at construction: `cardWidth ≥ 4`, `posterHeight ≥ 1`, both
spacings `≥ 0` (and again inside `render()` — a card asked to render narrower than
4 cells re-clamps silently rather than emitting a negative pad).

## License

MIT © Joe Huss
