<img src=".assets/icon.png" alt="candy-vt" width="160" align="right">

# CandyVt

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-vt)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-vt)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-vt?label=packagist)](https://packagist.org/packages/sugarcraft/candy-vt)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

In-memory virtual terminal emulator — parses an ANSI byte stream into a
cell grid with cursor, mode, SGR style, and hyperlink state.

Mirrors [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt).

## Install

```sh
composer require sugarcraft/candy-vt
```

## Quickstart

```php
use SugarCraft\Vt\Terminal\Terminal;

$vt = Terminal::create(cols: 80, rows: 24);

// Feed raw ANSI bytes from a terminal program.
$vt->feed("\x1b[1;1H\x1b[31mmhello\x1b[0m");

// Inspect the rendered screen.
$screen = $vt->screen();
foreach ($screen->lines() as $row => $line) {
    echo "$row: $line\n";
}

// Current cursor position.
$cursor = $vt->cursor();
echo "cursor at {$cursor->row},{$cursor->col}\n";
```

## Architecture

| Layer | Class | Role |
|---|---|---|
| Facade | `Terminal\Terminal` | Owns a Parser + ScreenHandler; `feed()` drives bytes in |
| Parser | `Parser\Parser` | VT500 state machine — Paul-Williams algorithm, handles partial input, parses subparameters (`:) |
| Handler | `Handler\ScreenHandler` | Dispatches parser actions to Buffer / Cursor / Sgr / Mode |
| Screen | `Screen\Screen` | Immutable snapshot — read current grid after feeding bytes |
| Buffer | `Buffer\Buffer` | Cell grid — `rows × cols` of styled grapheme cells |
| Cursor | `Cursor\Cursor` | Position + visibility + origin mode tracking |
| SGR | `Sgr\Sgr` | Current graphics rendition: foreground / background / attributes |
| Mode | `Mode\Mode` | DEC private mode flags (`DECSET`/`DECRST`) including DECAWM auto-wrap |
| Hyperlink | `Hyperlink\Hyperlink` | OSC 8 URL + id tracker |
| Scrollback | `Screen\Scrollback` | Ring buffer — stores rows that scroll off the top (default 1000) |

### Parser handlers

Each handler translates parser actions into handler state mutations:

- `CursorHandler` — CUP, HVP, CUU/CUD/CUF/CUB, CR, LF/VT/FF, BS, RI, HOME, DECSC/USC
- `SgrHandler` — SGR sequences (colour + attributes)
- `EraseHandler` — ED, EL, ECH, DECSCA
- `ScrollHandler` — SU, SD, DECSTBM
- `ModeHandler` — DECSET/DECRST/DECMODESET/DECMODERST (includes DECAWM mode 7)
- `TabHandler` — TBC, HTS
- `OscHandler` — OSC window title, hyperlink, colour palette
- `ScreenHandler` — orchestrates all of the above; owns the Buffer

## Diff API

After feeding bytes, snapshot the screen and compare:

```php
$before = $vt->screen();
$vt->feed($moreBytes);
$after  = $vt->screen();

foreach ($before->diff($after) as $change) {
    [$row, $col, $prev, $next] = $change;
    echo "{$row},{$col}: '{$prev->grapheme}' → '{$next->grapheme}'\n";
}
```

## Auto-wrap (DECAWM)

DECAWM (`CSI ? 7 h` / `CSI ? 7 l`) controls whether the cursor
automatically wraps to the next line when a character is printed at the
rightmost column. The `Mode` object exposes this as:

```php
// Query the current state.
$mode = $vt->mode();
var_dump($mode->autoWrap);  // bool

// Build a new mode with auto-wrap forced on/off.
$wrapped = $mode->withAutoWrap(true);
```

When auto-wrap is **off** (the default), characters written at the
rightmost column are silently discarded. When **on**, the cursor moves
to column 0 of the next row before writing — and if that row is within a
scroll region the region scrolls up by one line, matching VT100 behavior.

Scroll regions (DECSTBM, `CSI r`) and auto-wrap interact correctly:
wrapping at the bottom of a scroll region triggers a scroll within that
region, not on the whole buffer.

## Scrollback buffer

When the screen scrolls (LF at the bottom, DECSTBM scroll region, or
auto-wrap at the bottom of a region), rows pushed off the top are stored
in a ring-buffer `Scrollback`. Access it via:

```php
$screen = $vt->screen();
$scrollback = $screen->scrollback();

foreach ($scrollback->all() as $rowIndex => $row) {
    // $row is array<int, Cell>
    echo "scrollback row $rowIndex\n";
}
```

The default capacity is 1000 rows. Configure it at construction or
retrospectively:

```php
// Set at construction.
$vt = Terminal::create(cols: 80, rows: 24, scrollbackSize: 5000);

// Retrospective change — existing scrollback is replaced.
$vt = $vt->withScrollbackSize(5000);
```

Available `Scrollback` accessors:

```php
$scrollback->count()    // int — rows currently stored
$scrollback->maxSize()  // int — configured capacity
$scrollback->at($n)     // array<int, Cell>|null — row offset N from oldest
$scrollback->all()       // array<int, array<int, Cell>> — all rows oldest→newest
```

The scrollback is consumed by `ScreenHandler`'s scroll-up and scroll-down
operations (SU/SD/IND/RI/NEL). Erase-of-scrollback (`CSI 3 J`) clears the
ring buffer.

## Test

```sh
cd candy-vt && composer install && vendor/bin/phpunit
```

## Related

- [SugarCraft monorepo](https://github.com/detain/sugarcraft)
- Upstream: [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt)
