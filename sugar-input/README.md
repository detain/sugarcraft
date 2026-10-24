# SugarInput

> Formerly published as `sugarcraft/candy-input` (renamed 2026-10). <!-- @rename-keep -->

Terminal escape sequence decoder for keyboard (legacy + Kitty progressive keyboard protocol + xterm modifyOtherKeys) and mouse (SGR 1006/1016, urxvt 1015, X10). Provides the `InputDriver` interface and `EscapeDecoder` implementation.

## Overview

`sugar-input` is the missing input layer for SugarCraft — it decodes raw TTY bytes into structured `Event` objects that programs can switch on. It handles:

- **Plain ASCII keys** — letters, digits, punctuation, control codes
- **Legacy escape sequences** — F1–F12, arrow keys, Home/End/PgUp/PgDn, Insert, Delete, Backspace, Tab, Enter, Escape, Backtab (`CSI Z`) — including the MODIFIED numbered-key family `CSI number ; modifier ~` that terminals really emit: xterm/alacritty terminfo ship `kDC=\E[3;2~` (Shift+Delete) and wezterm ships `kf34=\E[21;5~` (Ctrl+F10), so `3;5~` decodes as Ctrl+Delete rather than vanishing
- **Kitty keyboard protocol** — event frames `CSI code ; mods u` (the `mods` field is the spec's `1 + bitmask`, so a bare press carries `;1` — release via the legacy `0x20` flag or the spec `:event-type` sub-param; event type 2, auto-repeat, deliberately decodes as an ordinary press). On release the key name gains the `Release` prefix and its first letter is upper-cased: a released `a` arrives as `ReleaseA`
- **xterm modifyOtherKeys** — wrapped keys `CSI 27 ; mods ; keysym ~`, so a modified key with no dedicated sequence is told apart from a plain press
- **Application keypad (DECKPAM)** — SS3 `ESC O p..y` (digits), `Ol` (decimal), `Om` (minus), `OM` (enter)
- **SGR mouse 1006 / 1016** — press, release, drag, and scroll (incl. horizontal wheel) with modifier support (`CSI < b ; x ; y M|m`); 1016 is byte-identical to 1006 so a stateless decoder reports its coordinate field verbatim
- **urxvt mouse 1015** — `CSI b ; x ; y M` decimal reports (no `<` introducer, so it never collides with 1006)
- **X10 mouse** — `CSI M` three-byte compressed reports (mode 1000), not printable-key spam
- **Focus events** — DECSET 1004 via `CSI I` / `CSI O`
- **Bracketed paste** — `CSI 200 ~` … `CSI 201 ~` with 1 MiB safety cap (a paste over the cap force-closes as a `PasteEvent` with `truncated` set; see [Bracketed paste](#bracketed-paste))
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
composer require sugarcraft/sugar-input
```

## API

### EscapeDecoder

```php
$decoder = new EscapeDecoder();

// Decode a byte buffer — returns 0+ Events, buffers partial sequences
$events = $decoder->decode($bytes);

// Get unconsumed remainder after decode()
$remainder = $decoder->remainder();

// Clear the partial-sequence buffer (and both counters)
$decoder->reset();
```

Feature groups toggle through `EscapeDecoderOptions`: `enableKitty`,
`enableFocus`, `enablePaste`, `enableMouse` (all on by default) and the opt-in
`deferTrailingEscape` (see [Deferred trailing escape](#deferred-trailing-escape-opt-in)).

### InputDriver

```php
interface InputDriver {
    /** Returns the next Event, or null on EOF / non-blocking empty */
    public function read(): ?Event;
}
```

### Input drivers

| Driver | Reads | Notes |
|---|---|---|
| `StreamInputDriver` | any readable stream (e.g. `STDIN`) | Non-blocking by contract: it takes over the stream's blocking flag on construction and restores the previous value in `__destruct`. Each `read()` pulls at most 8192 bytes through the internal `EscapeDecoder`; on EOF it first drains whatever the decoder still holds, and a read that produced no events re-reads once so buffered partials cannot stall the loop. |
| `ReactInputDriver` | a `React\Stream\ReadableStreamInterface` | Attaches to `data` and feeds the same decoder. `isReadable()` follows React's contract (`true` until the stream closes, not "bytes pending"); `pause()` defers emission — already-buffered events flush on `resume()`. Incoming chunks are sliced at 8192 bytes (`MAX_CHUNK_SIZE`) with a lone-trailing-`ESC` carry law so a boundary can never split a sequence's escape byte from its params. `pipe()` forwards the RAW byte stream, not events. |
| `SignalResizeDriver` | `SIGWINCH` | The production producer of `ResizeEvent`. Construction is side-effect free; the install is opt-in via `arm()`, which registers a weak-map fan-out `pcntl_signal` handler, enables `pcntl_async_signals()`, and probes current dimensions through `tput cols`/`tput lines` (capability name validated before the shell-out — see the gate in `SignalResizeDriver::CAPABILITY_PATTERN`). `read()` returns a `ResizeEvent` only after a SIGWINCH arrived. |

### Bracketed paste

A paste larger than `PasteEvent::MAX_SIZE` (1 MiB) force-closes: the emitted
`PasteEvent` carries `content` clipped to the cap, `truncated` set, and
`truncationNotice()` — a localized one-liner suitable for showing the user why
their paste looks short. The held-back tail of a split `CSI 201 ~` marker stays
in `remainder()` and is never re-surfaced as its own event.

Recovery lever: if the terminal ever loses the paste-END marker (an aborted
paste), every following keystroke is absorbed into the open paste until the 1 MiB
cap force-closes it. Calling `$decoder->reset()` (or `StreamInputDriver`
teardown with a fresh decoder) discards the open episode immediately — that is
the documented way back, no restart required.

## Event types

| Event | Key fields |
|---|---|
| `KeyEvent` | `key`, `modifiers`, `raw` |
| `MouseEvent` | `x`, `y`, `button`, `action`, `modifiers` — `button` is 0/1/2 for left/middle/right press-drag-release; scroll steps report 96 (up), 97 (down), 98 (left/wire 66), 99 (right/wire 67) |
| `FocusEvent` | `gained` |
| `PasteEvent` | `content`, `truncated`, `truncationNotice()` |
| `ResizeEvent` | `cols`, `rows` — produced by `SignalResizeDriver` (see [Input drivers](#input-drivers)) |
| `TerminalReplyEvent` | `family`, `params`, `raw`, `body`, `truncated` |

Control characters surface as `KeyEvent` pairing the lowercase letter with the
Ctrl modifier — 0x01 arrives as key `a` with `modifiers` carrying Ctrl. The
bytes with no letter to pair with — NUL (0x00) and the four separators
0x1C–0x1F (FS/GS/RS/US) — carry the RAW byte itself as the key name, so
`match ($event->key)` arms test for `"\0"` / `"\x1c"`…`"\x1f"` rather than an
invented mnemonic.

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
| `dsr-status` | `CSI 0 n` / `CSI 3 n` (any `CSI … n` final drains) | DSR ok / not-ok reply — every `n`-final frame is drained so an echoed query can never mis-parse as a key |
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
with their own idle timer can opt into the deferred-escape behaviour:

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

## Modifiers (KeyModifier)

Modifiers arrive **on events** — you read them; you do not build masks (the
constructor is private):

```php
if ($event->modifiers->includes(KeyModifier::CTRL)) { /* ... */ }
$event->modifiers->value(); // the underlying bitmask, for caching/comparison
```

The constants `SHIFT`, `ALT`, `CTRL`, `META`, `SUPER`, `HYPER` (plus
`CAPS_LOCK`, `NUM_LOCK`) document the wire bit layout the decoders map from:
`KeyModifier::fromKittyInt()` reads kitty bits 0–5, `fromXtermParam()` converts
the legacy `1 + bitmask` field, `fromSgrMouse()` extracts mouse-report bits.
`CAPS_LOCK`/`NUM_LOCK` are declared for spec completeness but are unreachable
from the wire forms decoded today. `all()` covers Shift+Alt+Ctrl only — the
bits the legacy CSI forms can express — and the named factories (`none()`,
`alt()`, `ctrl()`, `shift()`, `altShift()`, `ctrlShift()`, `altCtrl()`) exist
for producers and tests, not as a public combining API.

## Security: decoded content is untrusted

Everything this library emits is attacker-writable input. A terminal's input
buffer is not only fingers on keys: any process with permission can write bytes
into the pts slave, a remote `tmux send-keys` session injects keystrokes, and a
paste's own content is whatever the clipboard held. Treat key names,
`PasteEvent::content`, `TerminalReplyEvent::body` / `params`, and every `raw`
field as hostile bytes:

- Never echo decoded content straight back to the terminal — a crafted paste
  or reply body can carry further escape sequences and command your renderer.
- Never interpolate it into shell commands, paths, or logs without your own
  quoting and redaction discipline.
- The fleet's private-use-area arena (zone sentinels U+E000/U+E001 and the
  image-marker block from U+E002) passes through decoding byte-identical — a
  hostile paste can forge marker bytes. Frame-marked composites should sweep
  with candy-core `Sanitize::untrustedForMarkedFrames()` before rendering.

The decoder's own caps (partial sequence, 1 MiB paste, 1 KiB/64 KiB string
replies) contain parser resource use; they do not make the content trustworthy.
The fleet-wide contract lives in [`docs/TRUST-BOUNDARY.md`](../docs/TRUST-BOUNDARY.md).

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

The decoding semantics follow [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea)'s input handling; the class docblocks cite it as the reference (as do the tests, e.g. the deferred-trailing-ESC strategy). It decodes the same sequences that the kernel and terminal emulators produce.
