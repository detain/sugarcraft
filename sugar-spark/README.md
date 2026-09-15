<img src=".assets/icon.png" alt="sugar-spark" width="160" align="right">

# SugarSpark

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-spark)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-spark)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-spark?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-spark)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->


![demo](.vhs/inspect.gif)

PHP port of [charmbracelet/sequin](https://github.com/charmbracelet/sequin) —
an ANSI escape-sequence inspector. Pipe styled output through it and each
escape becomes a labelled line.

```sh
composer require sugarcraft/sugar-spark
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
use SugarCraft\Spark\Inspector;

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

Anything unrecognised falls back to a generic `CSI/OSC/...` descriptor — an
unrecognised sequence is never silently swallowed.

Reported bytes are the bytes that were sent: parameters keep the separator the
sender used (`ESC[4;3m` never comes back as `ESC[4:3m`, and an omitted
parameter keeps its empty slot) — in a CSI **and** in a DCS prelude, which travel
through the same parameter arrays; a string's `ESC \` terminator is reported once,
inside the sequence it closed; an abandoned `ESC O` leaves the text behind it
alone; and a sequence the stream cut short still arrives, as a `truncated …`
segment carrying its raw bytes.

Three normalisations remain in the re-emitted bytes, each pinned by a test
rather than hidden: an OSC is re-emitted with a BEL terminator; a re-emitted DCS
prelude is lossy (its final byte is dropped and an intermediate byte comes back
ahead of the parameters, so `ESC P 1 $ r ST` arrives as `ESC P $ 1 ST`) — the
parameters themselves now keep their own separators; and candy-ansi caps a
sequence at 32 parameters, past which the separator is dropped and digits keep
accumulating into the last slot, so `ESC[1;…;32;33m` arrives as the single
parameter `3233`.

Two further byte losses live in the shared candy-ansi state machine rather than
in this inspector, and are recorded in `CALIBER_LEARNINGS.md`: an illegal
parameter byte (`-`, CAN, SUB) cancels the sequence and discards the bytes
collected so far, and a truncated UTF-8 rune at end of stream is dropped because
it is not an escape-sequence state. Both are byte-identical to the behaviour
before this contract was written and each is pinned by its own test
(`testCancelledSequenceIsLostExactlyAsTheParserLosesIt`,
`testTruncatedUtf8TailIsDroppedExactlyAsTheParserDropsIt`) — both are fidelity
items for candy-ansi, not for this inspector.

## Test

```sh
cd sugar-spark && composer install && vendor/bin/phpunit
```
