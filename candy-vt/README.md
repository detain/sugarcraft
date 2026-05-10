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
| Parser | `Parser\Parser` | VT500 state machine — Paul-Williams algorithm, handles partial input |
| Handler | `Handler\ScreenHandler` | Dispatches parser actions to Buffer / Cursor / Sgr / Mode |
| Screen | `Screen\Screen` | Immutable snapshot — read current grid after feeding bytes |
| Buffer | `Buffer\Buffer` | Cell grid — `rows × cols` of styled grapheme cells |
| Cursor | `Cursor\Cursor` | Position + visibility + origin mode tracking |
| SGR | `Sgr\Sgr` | Current graphics rendition: foreground / background / attributes |
| Mode | `Mode\Mode` | Dec private mode (`DECSET`/`DECRST`) flags |
| Hyperlink | `Hyperlink\Hyperlink` | OSC 8 URL + id tracker |

### Parser handlers

Each handler translates parser actions into handler state mutations:

- `CursorHandler` — CUP, HVP, CUU/CUD/CUF/CUB, CR, LF/VT/FF, BS, RI, HOME, DECSC/USC
- `SgrHandler` — SGR sequences (colour + attributes)
- `EraseHandler` — ED, EL, ECH, DECSCA
- `ScrollHandler` — SU, SD, DECSTBM
- `ModeHandler` — DECSET/DECRST/DECMODESET/DECMODERST
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

## Test

```sh
cd candy-vt && composer install && vendor/bin/phpunit
```

## Related

- [SugarCraft monorepo](https://github.com/detain/sugarcraft)
- Upstream: [charmbracelet/x/vt](https://github.com/charmbracelet/x/tree/main/vt)
