<img src=".assets/icon.png" alt="sugar-spark" width="160" align="right">

# SugarSpark

![demo](.vhs/inspect.gif)

PHP port of [charmbracelet/sequin](https://github.com/charmbracelet/sequin) —
an ANSI escape-sequence inspector. Pipe styled output through it and each
escape becomes a labelled line.

```sh
composer require candycore/sugar-spark
```

## CLI

```sh
$ printf '\e[31mhello\e[0m world\n' | sugarspark
ESC[31m  SGR foreground red
hello
ESC[0m   SGR reset
 world
```

```sh
$ printf '\e]0;new title\e\\' | sugarspark
ESC]0;new title  set window title to "new title"
```

```sh
$ printf '\e[?2026h' | sugarspark
ESC[?2026h  enable synchronized output
```

## Library

```php
use CandyCore\Spark\Inspector;

foreach (Inspector::parse("\e[1;31mboom\e[0m") as $segment) {
    echo $segment->describe(), "\n";
}

// One-shot report:
echo Inspector::report($capturedTerminalOutput);
```

## What it decodes

- **SGR** — foreground / background (16, 256, 24-bit truecolor) +
  bold / italic / underline / blink / reverse / strikethrough / faint.
- **CSI** — cursor moves, erase, scroll region (DECSTBM), scroll up/down,
  insert/delete line/char, tab forward/backward, DECSCUSR cursor shape,
  DECRQM mode query, DECRPM mode reply, request cursor position,
  XTVERSION query, kitty keyboard query/push/pop.
- **CSI ~ keys** — Home / End / Delete / PgUp / PgDn / F1-F12 / bracketed
  paste markers.
- **DEC private modes** — cursor visibility, mouse modes (1000/1002/1003/
  1006/1015), focus reporting (1004), alt screen (47/1047/1049),
  bracketed paste (2004), **synchronized output (2026)**, **unicode
  grapheme mode (2027)**.
- **OSC** — title (0/2), icon (1), palette (4), cwd (7), hyperlink (8),
  iTerm2 (9), taskbar progress (9;4), terminal colour set (10/11/12),
  clipboard (52), reset terminal colour (110/111/112).
- **DCS** — XTVERSION reply (`>|<term> <ver>`), DECRPSS, sixel.
- **APC** — CandyZone markers (`candyzone:S/E:<id>`), kitty graphics
  (`G…`).
- **SS3** — F1-F4 / cursor / Home / End.
- **2-byte ESC** — DECSC / DECRC / keypad mode / index / reverse-index /
  reset.

Anything unrecognised falls back to a generic `CSI/OSC/...` descriptor —
nothing is silently swallowed.

## Test

```sh
cd sugar-spark && composer install && vendor/bin/phpunit
```
