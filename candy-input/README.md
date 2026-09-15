# CandyInput

Terminal escape sequence decoder for keyboard (legacy + Kitty progressive keyboard protocol) and mouse (SGR 1006). Provides the `InputDriver` interface and `EscapeDecoder` implementation.

## Overview

`candy-input` is the missing input layer for SugarCraft — it decodes raw TTY bytes into structured `Event` objects that programs can switch on. It handles:

- **Plain ASCII keys** — letters, digits, punctuation, control codes
- **Legacy escape sequences** — F1–F12, arrow keys, Home/End/PgUp/PgDn, Insert, Delete, Backspace, Tab, Enter, Escape
- **Kitty keyboard protocol** — event frames `CSI code ; mods u` (the `mods` field is the spec's `1 + bitmask`, so a bare press carries `;1` — release via the legacy `0x20` flag or the spec `:event-type` sub-param; event type 2, auto-repeat, deliberately decodes as an ordinary press)
- **SGR 1006 mouse** — press, release, drag, and scroll (incl. horizontal wheel) with modifier support
- **X10 mouse** — `CSI M` three-byte compressed reports (mode 1000), not printable-key spam
- **Focus events** — DECSET 1004 via `CSI I` / `CSI O`
- **Bracketed paste** — `CSI 200 ~` … `CSI 201 ~` with 1 MiB safety cap
- **Terminal replies** — DA1/DA2, DSR/CPR, XTWINOPS, kitty flags, DECRPM and OSC/DCS strings are drained as `TerminalReplyEvent` — never buffered, never mis-parsed as keys (see below)

## Quickstart

```php
use SugarCraft\Input\EscapeDecoder;
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;

$decoder = new EscapeDecoder();
$driver  = new StreamInputDriver(STDIN);

// Non-blocking read loop
while (true) {
    $event = $driver->read();
    if ($event === null) {
        continue; // non-blocking empty or EOF
    }

    match (true) {
        $event instanceof KeyEvent  => handleKey($event),
        $event instanceof MouseEvent => handleMouse($event),
        default                      => handleOther($event),
    };
}
```

## Installation

```sh
composer require sugarcraft/candy-input
```

## API

### EscapeDecoder

```php
$decoder = new EscapeDecoder();

// Decode a byte buffer — returns 0+ Events, buffers partial sequences
$events = $decoder->decode($bytes);

// Get unconsumed remainder after decode()
$remainder = $decoder->remainder();

// Clear the partial-sequence buffer
$decoder->reset();
```

### InputDriver

```php
interface InputDriver {
    /** Returns the next Event, or null on EOF / non-blocking empty */
    public function read(): ?Event;
}
```

## Event types

| Event | Key fields |
|---|---|
| `KeyEvent` | `key`, `modifiers`, `raw` |
| `MouseEvent` | `x`, `y`, `button`, `action`, `modifiers` |
| `FocusEvent` | `gained` |
| `PasteEvent` | `content` |
| `ResizeEvent` | `cols`, `rows` |
| `TerminalReplyEvent` | `family`, `params`, `raw`, `body`, `truncated` |

## Terminal replies — drained, not dropped

A terminal answers its queries on the **same file descriptor the user types
on**, so unsolicited reply bytes land in the middle of the keystroke stream:
a DA1 answer right after an arrow key, a cursor-position report (`ESC [ row ;
col R`) while the user holds a key, kitty keyboard flags (`ESC [ ? flags u`).
The decoder recognizes every reply family below, **structurally consumes it**
(so the buffer never stalls and later keystrokes never vanish behind it), and
surfaces it as a `TerminalReplyEvent`:

| `family` | Sequence | Meaning |
|---|---|---|
| `device-attributes` | `CSI ? Pm c` / `CSI > Pm c` | DA1 / DA2 reply |
| `cursor-position` | `CSI row ; col R` | DSR/CPR report (never a phantom `F3`) |
| `dsr-status` | `CSI 0 n` | DSR "is terminal OK" reply |
| `window-report` | `CSI Pm t` | XTWINOPS geometry/resize report |
| `kitty-flags` | `CSI ? flags u` (incl. bare `CSI ? u`) | kitty keyboard flags query/reply |
| `mode-report` | `CSI ? mode ; status $ y` (public `CSI mode ; status $ y` too) | DECRPM mode report |
| `csi-private` | any other complete `CSI ? …` / `CSI > …` | private-mode sequence, drained |
| `string` | `OSC / DCS / APC / PM … ST/BEL` | string replies (color reports, termcap, tmux echo); `body` + `truncated` carry the payload |

Hosts that ignore `TerminalReplyEvent` lose nothing — the bytes are consumed
either way, so the input stream stays in sync. Hosts that do care (terminal
probers, nested-TMUX diagnostics) get `params` and the `raw` bytes as drained —
for a `string` reply clipped at the cap, `raw` covers only the drained head and
`body` is the authoritative payload slice.

`string` payloads are bounded: a reply longer than 1 KiB — whether it arrives
in one read or across many — is surfaced exactly once with `truncated` set and
`body` clipped to 1 KiB, and the decoder then keeps swallowing the rest of that
open OSC/DCS until its terminator (even a terminator split across the chunk
boundary) rather than resuming key decoding mid-string, which would leak the
tail as keystrokes. A stream that never terminates the string is abandoned
after 64 KiB of swallowed payload so keystrokes resume; that abandon boundary
is byte-exact, so for never-terminated streams chunk size cannot change the
decoded event stream. A pathological reply that terminates only *after*
exceeding the abandon budget is the one documented divergence: bytes past the
budget may already have resumed decoding as keys by the time the real
terminator arrives — which ones, exactly, depends on where the reads fell —
so the guarantee there is "keystrokes always come back", not "oversized
replies stay wholly silent".

### Observability: drained reply vs dropped unknown

Two counters on `EscapeDecoder` tell the two silent paths apart:

```php
$decoder->drainedReplyCount();   // replies recognized and surfaced as TerminalReplyEvent
$decoder->droppedUnknownCount(); // complete sequences consumed with no event
```

A rising `drainedReplyCount()` is normal terminal chatter; a rising
`droppedUnknownCount()` means bytes were consumed but produced no event. That
includes genuinely unrecognized sequences (unknown private CSIs, unmapped SS3
finals, malformed mouse reports) **and** recognized-but-filtered ones — a kitty
event frame when the progressive protocol is disabled, or a stray `CSI 201~`
paste-end marker with no paste open — so a non-zero count is not necessarily a
terminal this decoder fails to model. `reset()` zeroes both counters along with
the buffers.

### Deferred trailing escape (opt-in)

By default a chunk that ends on a bare `ESC` resolves to an `Escape` key
immediately — the historical contract. Slow TTY reads that split a sequence
right after the escape byte then decode its remainder as literal keystrokes
(observe `ESC`+`[1;20R` as `Escape`, `[`, `1`… — and on a real terminal a
cursor-position report can absolutely arrive that way). Apps that bound reads
with their own idle timer can opt into the bubbletea-style behaviour:

```php
$decoder = new EscapeDecoder(
    options: new EscapeDecoderOptions(deferTrailingEscape: true),
);

$events = $decoder->decode("a\x1b");   // [a]; the ESC stays buffered
$events = $decoder->decode("[1;20R");  // drains as a cursor-position reply
$events = $decoder->flushDeferredEscape(); // timeout → [Escape] if one is pending
```

`flushDeferredEscape()` returns `[Escape]` and clears the buffer when the
pending remainder is exactly one `ESC` and no paste or string episode is open,
and `[]` otherwise — call it from an input-idle timer. (A paste body that ends
on a lone `ESC` leaves the same one-byte remainder as its held-back split end
marker; flushing there would invent an Escape and strand the paste, so the
guard is load-bearing.) Caveat: with the option on, a deferred `ESC` followed
by a plain byte in the *next* chunk still merges into `Alt+char`; flush before
resolving an Escape-only keystroke.

## Key constants (KeyModifier)

`Shift`, `Ctrl`, `Alt`, `Super`, `Hyper`, `Meta`, `CapsLock`, `NumLock` — combine with bitwise OR.

## No upstream parallel

This is a pioneering implementation for PHP TUI — there is no direct upstream to port. It decodes the same sequences that the kernel and terminal emulators produce.

## License

MIT
