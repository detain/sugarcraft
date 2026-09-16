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

Nine deviations from exact byte equality are known, and each is pinned by a
named test rather than hidden — anything *else* that loses a byte is a bug:

1. An OSC is re-emitted with a BEL terminator (`ESC ] … BEL`) even when the input
   closed it with `ESC \`, because that is the spelling terminals accept
   everywhere. (`testOscTerminatedByStIsNormalizedToBelWithoutGhost`)
2. A re-emitted DCS prelude is lossy: its final byte is dropped and an
   intermediate byte comes back ahead of the parameters, so `ESC P 1 $ r ST`
   arrives as `ESC P $ 1 ST`. The parameters themselves keep their own
   separators. (`testDcsParametersKeepTheirOwnSeparators`,
   `testDcsPreludeRebuildHoistsTheIntermediateAheadOfTheParameters`)
3. A sequence is capped at 32 parameters; past that the separator is dropped and
   digits keep accumulating into the last slot, so `ESC[1;…;32;33m` arrives as the
   single parameter `3233`. (`testParameterCapIsReportedAsTheParserSawIt`)
4. A parameter value accumulates only up to 65535, so an oversized value arrives
   clamped rather than truncated mid-digit: `ESC[99999m` is `ESC[65535m`.
5. A string payload stops at 64 KiB, so a longer OSC arrives cut at exactly that
   length with its terminator still attached.
6. Inside an **OSC** payload only, the C0 bytes candy-ansi maps to `None`
   (`0x00`–`0x06`, `0x08`–`0x17`, `0x19`, `0x1C`–`0x1F`) never reach the buffer:
   `ESC]0;a<EOT>b BEL` re-emits as `ESC]0;ab BEL`. The same byte does survive in a
   DCS, SOS, PM or APC payload. (4–6:
   `testIgnoredControlBytesAndParserCapsArriveAsTheParserRewroteThem`)
7. An illegal parameter byte (`-`, CAN, SUB) cancels the sequence and discards the
   bytes collected so far: `ESC[31;-2mX` reaches the segmenter as only `mX` — no
   segment is invented for a sequence that never dispatched. The one exception is a
   cancelled prelude followed by a truncated tail: the tail reports the raw bytes
   still in flight, so it replays the cancelled prelude too (`ESC[31;-2m` +
   `ESC[3` arrives as one 11-byte `truncated CSI`). That over-reports rather than
   under-reports, and CAN does not leak because its `execute` callback flushes the
   byte window while an illegal byte leaves no callback at all.
   (`testCancelledSequenceIsLostExactlyAsTheParserLosesIt`)
8. A truncated UTF-8 rune at end of stream is dropped, because that is not an
   escape-sequence state for `Parser::flush()` to report.
   (`testTruncatedUtf8TailIsDroppedExactlyAsTheParserDropsIt`)
9. A sequence opened by an **8-bit C1 introducer** (`0x90` DCS, `0x98` SOS,
   `0x9B` CSI, `0x9D` OSC, `0x9E` PM, `0x9F` APC) is re-emitted in its 7-bit
   `ESC` spelling: candy-ansi folds the introducer choice away before the
   dispatch callback, leaving this inspector only the parsed pieces and a
   7-bit template to rebuild from — so `\x9b31m` arrives as `ESC [ 31 m`.
   An *unterminated* tail is the exception: it replays the bytes in flight
   verbatim, keeping the raw C1 introducer.
   (`testEightBitCsiIntroducerIsReplayedInSevenBitForm`,
   `testEightBitSosAndPmCarryTheirPayloadThroughTheSevenBitReplay`)

The first two are this inspector's own re-emission choices. Items 3–8 are
candy-ansi rewriting the bytes before this lib ever sees a dispatch — recorded in
`CALIBER_LEARNINGS.md`, and each transformation is byte-identical to the behaviour
before this contract was written, so they are fidelity items for candy-ansi, not
for this inspector. Item 9 is the same fold in the other direction: candy-ansi
loses which spelling opened the sequence, so the inspector's re-emission can only
pick one. What *is* new here is what the inspector reports afterwards:
before this branch a truncated tail simply vanished, which is why item 7's
cancelled prelude can now reappear inside one.

## Test

```sh
cd sugar-spark && composer install && vendor/bin/phpunit
```
