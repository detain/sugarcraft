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
splice the results back in at their absolute index:

```php
[$start, $end] = $grid->visibleRange(overscanRows: 1);
if ($start <= $end && $start !== $lastFetchedStart) {
    // fetch items [$start, $end] from your API, build cards keyed by index…
    $grid = $grid->withItems([$start => $card0, $start + 1 => $card1, /* … */]);
}
```

Async poster arrived for one cell? `->withItem($index, $card->withPoster($ansi))`.

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

## License

MIT © Joe Huss
