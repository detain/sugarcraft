<img src=".assets/icon.png" alt="candy-ansi-inspect" width="160" align="right">

# CandyAnsiInspect

> Formerly published as `sugarcraft/sugar-spark` (renamed in 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Spark\` → `SugarCraft\AnsiInspect\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-ansi-inspect)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-ansi-inspect)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-ansi-inspect?label=packagist)](https://packagist.org/packages/sugarcraft/candy-ansi-inspect)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

![demo](.vhs/inspect.gif)

candy-ansi-inspect — an ANSI escape-sequence inspector for PHP 8.3+, modeled
on the Go tool [charmbracelet/sequin](https://github.com/charmbracelet/sequin).
Pipe styled output through it and each escape becomes a labelled line.

```sh
composer require sugarcraft/candy-ansi-inspect   # binary lands in vendor/bin/candy-ansi-inspect
```

The CLI examples below type the bare command; from a project root that means
`vendor/bin/candy-ansi-inspect`, or put `vendor/bin` on your `PATH` (a
`composer global require` installs it likewise).

A self-contained phar is the other door: `tools/build-phar.php` seals the app
plus its published `vendor/` into one file (`php -d phar.readonly=0
tools/build-phar.php --out=dist/candy-ansi-inspect.phar`; the build refuses a
linked-mode vendor or a dirty tree, so release artifacts always ship tested
bytes, and the latest-channel CI build skips the source-tree gate only in its
`--vendor=monorepo` dev mode). A downloaded archive — even renamed to drop the
suffix — boots through its own frozen autoload and answers `--version` with
the stamp it was built under; a checkout reports `dev-master` rather than
inventing a semver for unreleased code.

## CLI

```sh
$ printf '\e[31mhello\e[0m world\n' | candy-ansi-inspect
ESC[31m  SGR foreground red
hello
ESC[0m  SGR reset
 world
LF  C0 LF (line feed)
```

```sh
$ printf '\e]0;new title\e\\' | candy-ansi-inspect
ESC]0;new titleBEL  set window title to "new title"
```

```sh
$ printf '\e[?2026h' | candy-ansi-inspect
ESC[?2026h  enable synchronized output
```

```sh
$ printf '\e[31merror: disk full\e[0m\n' > capture.ansi
$ candy-ansi-inspect capture.ansi
ESC[31m  SGR foreground red
error: disk full
ESC[0m  SGR reset
LF  C0 LF (line feed)
```

A saved capture works exactly like a pipe: pass the file as the argument and the
same report comes out.

Every transcript above is byte-exact and safe to scroll: the display column
prints named tokens (`BEL`, `LF`, `ESC`) wherever a raw control byte was sent,
so the samples diff cleanly against `od` output and no sequence in a captured
file can beep, retitle your window, or move your cursor while you read the
report.

A pipe is reported live: stdin is read chunk by chunk and each segment's line is
written as soon as the sequence completes, so `tail -f log | candy-ansi-inspect`
emits per sequence instead of waiting for the writer to close — and the bytes of
that live report are the same bytes the file argument produces.

Exec mode runs the program instead of reading its capture: `candy-ansi-inspect
-- cmd [args…]` starts `cmd` on a fresh pseudo-terminal (sugar-pty does all the
PTY work) and reports every sequence it prints the instant it completes, then
exits with the child's status. Everything after `--` belongs to the command —
including its own flags.

`--json` renders the same report as a parseable JSON array — one object per
segment with `type` (`text` or `sequence`), raw `content` bytes, the human
`description`, and the segment's `byteOffset` in the source stream. JSON is
never live-streamed: a partial array is not parseable,
so `--json` on a pipe collects the segments and prints the array when the
source ends; the flag works before or after the file operand, and `--` ends
flag parsing — the exec report stays line-mode.

`--show-offsets` prefixes every line-mode report with the segment's byte
position as `@<hex>` — the position column sequin prints. It is what lets you
locate a line inside a multi-megabyte capture: the offset is the true input
position, so it stays exact even for the sequences whose reported bytes are
normalised (an ST-terminated OSC reports one terminator byte but consumed
two). Offsets count from zero across the whole source — file, pipe, or the
child's output stream under `-- cmd` — and a normal segment's offset plus its
reported bytes lands exactly on the next segment's offset.

An empty source is a valid report of nothing: an empty file, or a command that
printed nothing, yields an empty report, so `cmd | candy-ansi-inspect` stays
chainable when `cmd` has no output. The exit-status contract, arm by arm, for
scripters:

| Situation                                            | Prints   | Stream | Exit status             |
|------------------------------------------------------|----------|--------|-------------------------|
| Readable file argument or piped stdin                | report   | stdout | **0**                   |
| `--help` or `-h` (an explicit request)               | usage    | stdout | **0**                   |
| `--version` (archive stamp, or `dev-master` in source) | version line | stdout | **0**               |
| Exec mode (`-- cmd [args…]`)                         | report   | stdout | the child's exit status |
| No source named: no operand *and* stdin is a terminal | usage   | stderr | **1**                   |
| More than one source named (contract: exactly one)   | usage    | stderr | **1**                   |
| Operand is not a readable regular file               | refusal  | stderr | **1**                   |

"`-h`/`--help` prints on stdout" is checked before any path is considered, so
the flag can never be swallowed by the file gate. Only a terminal stdin counts
as "no source" — a pipe is always a source, empty or not. Exactly one source is
the contract: naming two files is refused with usage rather than reporting the
first and silently dropping the rest (flags may appear on either side of the
one operand). The file gate asks
"is this a readable regular file", not "can we fake an empty report": bad paths
exit **1** with the refusal on stderr, never a PHP warning followed by a
misleading zero. The refusal
string's wording is
`cannot read: {path}`, and it fires for every path that cannot be read as a
regular file — not-a-file, a directory, and not-readable alike. Names that
actually carry a scheme-like `://` get a second hint line, so the exact
stderr for a wrapper argument is byte-for-byte:

```text
candy-ansi-inspect: cannot read: data://text/plain
  (stream wrappers such as php:// or data:// are not accepted)
```

A typo'd or plain-unreadable path prints only the first line — the wrapper
hint would send you hunting for a scheme that is not there. The same refusal
covers every non-regular operand: process substitution (`<(cmd)`), FIFOs and
other special files are not readable regular files either — pipe them on
stdin instead of naming them.

## Library

```php
use SugarCraft\AnsiInspect\Inspector;

foreach (Inspector::parse("\e[1;31mboom\e[0m") as $segment) {
    echo $segment->describe(), "\n";
}
```

```text
ESC[1;31m  SGR bold, foreground red
boom
ESC[0m  SGR reset
```

`report()` renders exactly those lines as one string — the same text the
CLI prints:

```php
echo Inspector::report("\e[1;31mboom\e[0m");   // the three lines above
```

Pass `true` as the second argument to get the `--show-offsets` column
(`report($input, true)` prints `@0  …`, `@7  …`, `@b  …`), and every segment
parsed by the library carries the same position through `$segment->byteOffset()`.

`reportAsJson()` is the machine-readable view: one object per segment with
`type` (`sequence` or `text`), raw `content` bytes, the `description`, and
the input-stream `byteOffset`:

```php
echo Inspector::reportAsJson("\e[1;31mboom");  // pretty JSON:
```

```text
{ "type": "sequence", "content": "\u001b[1;31m", "description": "ESC[1;31m  SGR bold, foreground red", "byteOffset": 0 },
{ "type": "text",     "content": "boom",         "description": "boom",                               "byteOffset": 7 }
```

For incremental sources — reading a log or a live pipe chunk by chunk —
`StreamingInspector` keeps parser state across chunks. `feed()` returns the
segments a chunk completes (half-sequences are held until their next bytes
arrive); `finish()` flushes the tail, so a dangling `ESC` or an unterminated
sequence still arrives as its own segment:

```php
use SugarCraft\AnsiInspect\StreamingInspector;

$handle = popen('printf "a\\e[31" | ', 'r');    // any byte source works
$streaming = new StreamingInspector();
while (!feof($handle)) {                        // feof, not `!== false`:
    foreach ($streaming->feed((string) fread($handle, 8192)) as $segment) {
        echo $segment->describe(), "\n";        //   fread returns "" at EOF
    }                                           // 'a' arrives as its run ends
}
foreach ($streaming->finish() as $segment) {
    echo $segment->describe(), "\n";            // the dangling ESC[31 arrives here
}
pclose($handle);
```

```text
a
ESC[31  truncated CSI (unterminated)
```

## What it decodes

- **SGR** — foreground / background / underline colour (16, 256, 24-bit
  truecolor, 58/59) +
  bold / italic / underline (all `4:N` styles) / blink / reverse / conceal /
  strikethrough / faint / framed / encircled / overlined / super- and
  subscript, plus the matching off codes. Label vocabulary is spelled one
  way throughout: prose labels take British `colour`, while `256-color` and
  `truecolor` stay in their X11/xterm term-of-art spelling (they are the
  names of the modes, not prose).
- **CSI** — cursor moves, erase, scroll region (DECSTBM), scroll up/down,
  insert/delete line/char, tab forward/backward, HVP/VPR/HPR absolute
  position, ECH, window manipulation (XTWINOPS), DECSCUSR cursor shape,
  DECRQM mode query, DECRPM mode reply, DSR status/CPR request, DA1/DA2/DA3
  device attributes, XTVERSION query, kitty keyboard query/push/pop,
  **mouse event reports: SGR (1006), urxvt (1015) and X10** — button,
  coordinates, press/release/wheel and modifier bits, never "delete lines".
- **CSI ~ keys** — Home / Insert / End / Delete / PgUp / PgDn / F1-F12 /
  bracketed paste markers.
- **DEC private modes** — cursor visibility, cursor-keys application (1),
  origin (6), mouse modes (1000/1002/1003/1005/1006/1015), focus reporting
  (1004), focused-output (2031), alt screen (47/1047/1049),
  bracketed paste (2004), **synchronized output (2026)**, **unicode
  grapheme mode (2027)**.
- **OSC** — title (0/2), icon (1), palette (4), cwd (7), hyperlink (8, id and
  URI split), iTerm2 (9), taskbar progress (9;4), terminal colour set or
  **query** (`10/11/12;?`), clipboard (52), palette register reset (104),
  reset terminal colour (110/111/112), shell integration (133), iTerm2
  private sequences (1337).
- **DCS** — XTVERSION reply (`>|<term> <ver>`), DECRQSS request, DECRPSS,
  tmux passthrough, sixel.
- **APC** — CandyZone markers (`candyzone:S/E:<id>`), kitty graphics <!-- @rename-keep legacy wire token / historical log -->
  (`G…`).
- **SS3** — F1-F4 / cursor / Home / End.
- **2-byte ESC** — DECSC / DECRC / keypad mode / index / reverse-index /
  next line (NEL) / reset, plus the 7-bit spellings of the SOS / PM / ST
  controls (`ESC X`, `ESC ^`, `ESC \`).

Anything unrecognised falls back to a generic `CSI/OSC/...` descriptor: no
sequence that reaches the segmenter is dropped without a report. Bytes can
still vanish further upstream, inside the parser's state table — the
byte-fidelity notes below enumerate exactly that territory, deviation by
deviation.

## Divergences from sequin

This tool ports charmbracelet/sequin's core behaviour, not its flag surface.
A sequin user reading this README should know exactly what differs:

- **`-- cmd` replaces `-cmd`.** Exec mode runs the child on a fresh
  pseudo-terminal and reports the raw bytes it prints (see CLI).
- **The offset column is opt-in.** sequin prints each sequence's byte
  position by default; here `--show-offsets` (or `$segment->byteOffset()`
  from the library) adds it on request, keeping the default transcript
  byte-stable for scripted diffing.
- **No `--raw` inline-highlight mode.** The report's first column always
  shows the sent bytes — tokenised into `ESC`/`BEL`-style names, never
  replayed — so sequin's "print the input unhighlighted" toggle has nothing
  here to switch off. Colouring of the report itself is deliberately absent:
  an ANSI-coloured inspector would have to escape its own output, and the
  point of this tool is a byte-trustworthy transcript.
- **No theming.** sequin reads `SEQUIN_THEME` (and Lipgloss palettes) for its
  highlight colours. There are no colours to theme — vocabulary lives in the
  labels themselves.
- **No `--list`.** The cheat-sheet surface sequin prints is this README's
  "What it decodes" section plus the locale files; a second in-binary roster
  would drift from the docs it exists to mirror.
- **No `listen` mode.** Accepting connections on a port turns a forensic
  tool into a network-facing daemon — port binding and ACLs on a machine
  that is about to *render untrusted byte streams* — and sequin's own
  listener ships no authentication. That is a security-review-sized feature
  the fleet has no precedent for, so it stays out of scope (recorded 2026-11
  with finding 023's deferral; revisit only behind an explicit owner ruling).

## Localization

Seventeen locale files ship in `lang/`, and every message the tool prints
itself — the usage line, the unreadable-source refusal, the stream-wrapper
note appended to it, and the exec-mode PTY refusal — routes through
`Lang::t()`. The bin entry calls `T::setLocale(T::detect())`, so locale
*selection* is live: detection walks `LC_ALL` → `LC_MESSAGES` → `LANG` and
the first usable variable wins (when you set `LANG`, make sure the others
are unset). Values are normalised — `fr_FR.UTF-8` becomes `fr-fr`, which
resolves through the base-language lookup to `fr`; anything unknown falls
back to `en`. So `LC_ALL=de.UTF-8 candy-ansi-inspect` answers German. The
decoded labels inside a report are English-built in `Inspector`, so report
bytes never depend on the caller's locale.

## Test

```sh
cd candy-ansi-inspect && composer install && vendor/bin/phpunit
```

## Byte-fidelity contract

Reported bytes are the bytes that were sent, in four respects:

- Parameters keep the separator the sender used (`ESC[4;3m` never comes back
  as `ESC[4:3m`) and an omitted parameter keeps its empty slot — in a CSI
  **and** in a DCS prelude, which travel through the same parameter arrays.
- A dispatched CSI replays its own window tail verbatim, so stacked private
  markers, mid-body DEL bytes and the 8-bit `0x9B` spelling survive even
  though the parser's single slots collapse them out of the callback fields.
- A string's `ESC \` terminator is reported once, inside the sequence it
  closed; an abandoned `ESC O` leaves the text behind it alone; and a
  sequence the stream cut short still arrives, as a `truncated …` segment
  carrying its raw bytes.
- `raw()` returns the bytes themselves, while the report's display column
  tokenises them instead — control bytes arrive as named escapes (`ESC`,
  `BEL`, `RI`, `\x8B`) so a payload can never forge a segment line or replay
  controls at the terminal.

Every deviation from exact byte equality that is known is pinned by a
named test rather than hidden — anything *else* that loses a byte is a bug:

1. An OSC is re-emitted with a BEL terminator (`ESC ] … BEL`) even when the input
   closed it with `ESC \`, because that is the spelling terminals accept
   everywhere. (`testOscTerminatedByStIsNormalizedToBelWithoutGhost`)
2. A re-emitted DCS prelude is lossy: its final byte is dropped and an
   intermediate byte comes back ahead of the parameters, so `ESC P 1 $ r ST`
   arrives as `ESC P $ 1 ST`. The parameters themselves keep their own
   separators. A BEL-terminated DCS dispatches only after its body has fully
   arrived, so that spelling replays the window verbatim instead (finding
   016's cured leg — pinned both ways).
   (`testDcsParametersKeepTheirOwnSeparators`,
   `testDcsPreludeRebuildHoistsTheIntermediateAheadOfTheParameters`,
   `testBelTerminatedDcsReplaysVerbatimWhileItsStSpellingKeepsTheTemplate`)
3. A sequence is capped at 32 parameters *inside the parser*: past the cap the
   separator is dropped and digits keep accumulating into the last slot. The
   window-tail rebuild (finding 016) makes this invisible in a dispatched CSI —
   the wire bytes arrive verbatim — but a DCS prelude still rebuilds through the
   parser fields, so the merged value survives there.
   (`testParameterCapIsInvisibleInTheVerbatimCsiReplay`)
4. A parameter value accumulates only up to 65535 inside the parser; like item 3
   this is no longer observable in a CSI's verbatim replay (`ESC[99999m` arrives
   as sent) and survives only through the DCS-prelude template.
5. A string payload stops at 64 KiB, so a longer OSC arrives cut at exactly that
   length with its terminator still attached.
6. Inside an **OSC** payload only, the C0 bytes sugar-ansi-parser maps to `None`
   (`0x00`–`0x06`, `0x08`–`0x17`, `0x19`, `0x1C`–`0x1F`) never reach the buffer:
   `ESC]0;a<EOT>b BEL` re-emits as `ESC]0;ab BEL`. The same byte does survive in a
   DCS, SOS, PM or APC payload. (4–6:
   `testIgnoredControlBytesAndParserCapsArriveAsTheParserRewroteThem`)
7. A cancel discards the sequence in flight: an illegal parameter byte (`-`),
   CAN/SUB while the parser sits in a control-sequence state (a CSI state, or a
   DCS prelude before its final byte), and the anywhere-ESC edge all abandon the
   sequence — and no segment is ever invented for the never-dispatched sequence
   itself. The abandoned bytes still reach the report whenever a later byte can
   observe them: the next dispatch re-reads the byte window and re-emits the
   leading residue ahead of itself as a `truncated … — cancelled` segment
   (`ESC[31 ESC m` reports the 4-byte `ESC[31` residue plus the `ESC m`
   dispatch — every byte accounted), and a cancelled prelude followed by a
   truncated tail replays inside that tail (`ESC[31;-2m` + `ESC[3` arrives as
   one 11-byte `truncated CSI`). The documented floor is a cancel whose residue
   is never observed before end of stream: `ESC[31;-2mX` reaches the segmenter
   as only `mX`, and `ESC[31 CAN mX` as CAN + `mX` — CAN's `execute` callback
   flushes the byte window while an illegal byte leaves no callback at all, and
   a Ground-state window at EOF is dropped. Over-report for as long as the
   stream continues; enumerate-and-pin the survivors. Executed bytes that are
   NOT cancels keep the window: `ESC[1; CR` at end of stream reports CR *and*
   the truncated `ESC[1;`, and a C1 control cancel replays the prelude ahead
   of its own segment. A CAN/SUB inside a
   *string payload* state is item 11, not this one.
   (`testCancelledSequenceIsLostExactlyAsTheParserLosesIt`,
   `testEscCancelledSequenceIsReportedAsCancelledResidue`,
   `testMidSequenceC0AndC1ExecuteKeepTheirPrelude`)
8. A truncated UTF-8 rune at end of stream is dropped, because that is not an
   escape-sequence state for `Parser::flush()` to report.
   (`testTruncatedUtf8TailIsDroppedExactlyAsTheParserDropsIt`)
9. A **string family** opened by an 8-bit C1 introducer (`0x9D` OSC, `0x98`
   SOS, `0x9E` PM, `0x9F` APC) is re-emitted in its 7-bit `ESC` spelling with a
   normalised terminator: the dispatch callback hands the handler only the
   payload, and string segments rebuild from that template. A CSI opened by
   `0x9B` — and a DCS by `0x90` whose body ends on BEL — is the exception since
   the window-tail rebuild (finding 016): the verbatim C1 bytes arrive, and an
   unterminated tail always did.
   (`testEightBitCsiIntroducerReplaysItsVerbatimByte`,
   `testEightBitSosAndPmCarryTheirPayloadThroughTheSevenBitReplay`)
10. An **invalid UTF-8 byte mid-stream** is dropped along with its position:
    a stray lead (`a\xffb` → text `ab`), a lead interrupted by a non-continuation
    byte (the rune dies, the interrupting byte survives: `a\xc3(b` → `a(b`), and a
    second lead arriving before the first rune completes (`a\xe2\xe2Ab` → `aAb`)
    all vanish with no segment. The C1 controls sugar-ansi-parser routes through
    `Action::Execute` — `0x80`–`0x8F`, `0x91`–`0x97`, `0x99`, `0x9A` and `0x9C`
    (the anywhere-table roster pinned byte-for-byte by `C1ClassificationSweepTest`)
    — do survive, as `C1 …` sequence segments; arriving mid-sequence
    they additionally replay the abandoned prelude ahead of themselves as a
    `truncated … — cancelled` segment — the loss is confined
    to bytes the UTF-8 window rejects. Item 8 is only its end-of-stream shadow;
    surfacing these as reported elements needs a sugar-ansi-parser callback (the parser's
    `replaceMalformed` flag is not enough — it never re-checks a ground-state byte
    it ignores, and a synthesised U+FFFD would be indistinguishable from a
    legitimately sent one).
    (`testMidStreamInvalidUtf8BytesAreDroppedExactlyAsTheParserDropsThem`)
11. CAN/SUB inside a **string payload** state — an OSC body, a DCS body after its
   final byte, or an SOS/PM/APC string — terminates that sequence: the payload
   collected so far is dispatched (so it reaches this handler as a segment, unlike
   item 7's vanished sequence) and the cancel byte itself is consumed as the
   terminator, never echoed. `ESC]0;a CAN b BEL` therefore arrives as a truncated
   `ESC]0;a BEL` segment, the text `b`, and the ground-state BEL echo — the CAN
   byte appears nowhere in the report. This dispatch-on-cancel behaviour landed
   in sugar-ansi-parser `35dda61c0`
   (2026-10-23); before it the entire in-flight sequence vanished instead — one
   more byte accounted for, one byte (the cancel) still not.
   (`testCancelByteInsideStringStateDispatchesTheTruncatedPayload`)

The first two are this inspector's own re-emission choices. Items 3–8, 10 and 11 are
sugar-ansi-parser rewriting or dropping the bytes before this lib ever sees a dispatch — recorded in
`CALIBER_LEARNINGS.md`, and each transformation is byte-identical to the behaviour
before this contract was written, so they are fidelity items for sugar-ansi-parser, not
for this inspector. Item 9 is the same fold in the other direction: sugar-ansi-parser
loses which spelling opened the sequence, so the inspector's re-emission can only
pick one. What *is* new here is what the inspector reports afterwards:
before this branch a truncated tail simply vanished, which is why item 7's
cancelled prelude can now reappear inside one.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
