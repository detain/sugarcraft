# SugarInput

> Formerly published as `sugarcraft/candy-input` (renamed 2026-10). <!-- @rename-keep -->

Terminal escape sequence decoder for keyboard (legacy + Kitty progressive keyboard protocol + xterm modifyOtherKeys) and mouse (SGR 1006/1016, urxvt 1015, X10). Provides the `InputDriver` interface and `EscapeDecoder` implementation.

New here? Read [Quickstart](#quickstart), then [Terminal replies](#terminal-replies--drained-not-dropped) if you host a prober, and [Security](#security-decoded-content-is-untrusted) before shipping. Glossary: a *frame* is one wire sequence (`ESC [ 57352 ; 1u`), an *event* is its decoded product, a *reply* is a frame the terminal sends on its own, not in answer to your keystrokes.

## Overview

`sugar-input` is the missing input layer for SugarCraft — it decodes raw TTY bytes into structured `Event` objects that programs can switch on. It handles:

- **Plain ASCII keys** — letters, digits, punctuation, control codes (the 0x7F byte names `Backspace` — one raw code, no escape sequence)
- **Legacy escape sequences** — F1–F24, arrow keys, Home/End/PgUp/PgDn, Insert, Delete, Tab, Enter, Escape, Backtab (`CSI Z`) — including the MODIFIED numbered-key family `CSI number ; modifier ~` that terminals really emit: modified forms like `3;2~` (Shift+Delete) and `21;5~` (Ctrl+F10) ship in common terminfo entries, so `3;5~` decodes as Ctrl+Delete rather than vanishing
- **Kitty keyboard protocol** — event frames `CSI code ; mods u` (the `mods` field is the spec's `1 + bitmask`, so a bare press carries `;1` — release via the legacy `0x20` flag or the spec `:event-type` sub-param; event type 2, auto-repeat, deliberately decodes as an ordinary press with `isRepeat` set). Codepoints follow the spec's private-use layout: `57344`–`57454` carry the functional keys (arrows at `57350`–`57353`, the keypad block from `57399`), and codes below `57344` in `u` frames are TEXT codepoints — legacy tilde numbers like `11u` are counted drops, not F1. On release the key name gains the `Release` prefix and its first letter is upper-cased: a released `a` arrives as `ReleaseA`
- **xterm modifyOtherKeys** — wrapped keys `CSI 27 ; mods ; keysym ~`, so a modified key with no dedicated sequence is told apart from a plain press
- **Application keypad (DECKPAM)** — SS3 `ESC O p..y` (digits), `Ol` (decimal), `Om` (minus), `OM` (enter)
- **SGR mouse 1006 / 1016** — press, release, drag, and scroll (incl. horizontal wheel) with modifier support (`CSI < b ; x ; y M|m`); 1016 (pixels) frames carry colon sub-parameters and arrive byte-compatible with 1006 — the decoder keeps the first integer of each field, which for 1016 is a PIXEL coordinate. A stateless decoder cannot convert, so `x`/`y` are whatever the reporting mode measures; a host that enabled 1016 knows its own units. Non-numeric mouse parameters are refused, never cast to phantom origin clicks
- **urxvt mouse 1015** — `CSI b ; x ; y M` decimal reports (no `<` introducer, so it never collides with 1006)
- **X10 mouse** — `CSI M` three-byte compressed reports (mode 1000), not printable-key spam; payload bytes outside the printable 7-bit range are refused with one counted drop — including the UTF-8 mode-1005 coordinate extension (`≥ 0x80`), which is deliberately not modelled and would otherwise surface forged high-button clicks
- **Focus events** — DECSET 1004 via `CSI I` / `CSI O`
- **Bracketed paste** — `CSI 200 ~` … `CSI 201 ~` with 1 MiB safety cap (a paste over the cap announces one truncated `PasteEvent`, then DISCARDS the remaining body bytes until the real terminator — see [Bracketed paste](#bracketed-paste))
- **Terminal replies** — DA1/DA2, DSR/CPR, XTWINOPS, Kitty flags, DECRPM and OSC/DCS/APC/PM strings are drained as `TerminalReplyEvent` — never buffered, never mis-parsed as keys (see below)

## Quickstart

```php
use SugarCraft\Input\Driver\StreamInputDriver;
use SugarCraft\Input\Event\KeyEvent;
use SugarCraft\Input\Event\MouseEvent;
use SugarCraft\Input\Event\PasteEvent;
use SugarCraft\Input\Event\FocusEvent;
use SugarCraft\Input\Event\TerminalReplyEvent;

$driver = new StreamInputDriver(STDIN);

// Non-blocking read loop with an idle backoff — never a hot spin:
// read() returns null BOTH when more bytes may come and when the stream
// hit EOF. isEof() tells the two apart; hasPending() says the decoder is
// holding a partial sequence.
while (!$driver->isEof()) {
    $event = $driver->read();
    if ($event === null) {
        if (!$driver->hasPending()) {
            usleep(2_000); // nothing buffered — back off before polling again
        }
        continue;
    }

    match (true) {
        $event instanceof KeyEvent  => handleKey($event),
        $event instanceof MouseEvent => handleMouse($event),
        default                     => handleOther($event), // Paste/Focus/Reply/Resize
    };
}
```

Before any of this reaches the decoder, the HOST owns the terminal modes —
see [Terminal setup is the host's duty](#terminal-setup-is-the-hosts-duty).

One termination caveat: `isEof()` means "ended *and* drained", not "ended". If
the stream stops mid-sequence, the held partial keeps `isEof()` false and the
loop above spins on `read() === null` forever. For a bounded shutdown, discard
the stranded partial with `$driver->escapeDecoder()->reset()` (which clears the
remainder and lets `isEof()` answer true) or exit on a grace period of
EOF-idle polls.

To watch the decoder work on a scripted byte stream, run
`php examples/decode.php` — it feeds labeled frames (plain keys, legacy and
Kitty arrows, a Kitty release, modifyOtherKeys, SGR 1016 pixels, a forged
mouse frame, a DA reply, focus, a bracketed paste) and prints the decoded
events.

## Installation

```sh
composer require sugarcraft/sugar-input:@dev
```

The explicit `:@dev` is needed while the package has no tagged release and
your project's `minimum-stability` is `stable` — it resolves against the
default branch regardless. Use the plain form once a stable tag exists.

## Requirements

- PHP `^8.3` (PHPUnit `^10.5` for the suite).
- The decoder itself is pure — no tty, no environment reads — with one
  extension it cannot dodge: Kitty text codepoints materialize through
  `mb_chr()`, so `ext-mbstring` is a hard require (declared). It models
  bytes; everything about the terminal is the host's (below).
- `ext-pcntl` (+ `ext-posix` to self-signal in tests) for
  `SignalResizeDriver`; without pcntl, `arm()` returns `false` and detection
  stays off — never a silent half-start.
- A `tput` binary on `PATH` for the resize driver's dimension probes. When
  terminfo cannot be resolved the probe fails soft and dimensions keep their
  last known value (80×24 until a real answer arrives).
- `evenement`, `react/stream` and `react/event-loop` are hard requires
  (declared): `ReactInputDriver` emits through `evenement` and reads on a
  `React\Stream\ReadableStreamInterface` — create your own stream over the
  loop (`new ReadableResourceStream(STDIN, Loop::get())`) and pass it in;
  the constructor takes no loop argument. `react/event-loop` is the loop
  that stream runs on. The sibling `sugarcraft/sugar-core` is
  hard-required too — the `Lang` facade extends `Core\I18n\Lang`.

### Platforms

POSIX is the primary platform. Windows status per driver:

- `StreamInputDriver` — supported: its read loop falls back to a direct
  `fread` on the non-blocking stream, because `stream_select` is unreliable
  for PHP's non-socket streams on Windows. Construction throws rather than
  accept a stream it could not make non-blocking.
- `ReactInputDriver` — follows whatever the wrapped `react/stream` resource
  supports (the socket/stream rules of the ReactPHP layer).
- `SignalResizeDriver` — POSIX-only: resize detection rides SIGWINCH, which
  has no Windows equivalent here; `arm()` returns `false` without pcntl.

BSDs follow the POSIX leg. Note that this lib does not currently run
Windows CI legs in the monorepo matrix; the driver contracts above are the
code-side truth until it does.

## API

### EscapeDecoder

```php
$decoder = new EscapeDecoder();                                // pure decoder
$decoder = new EscapeDecoder(new EscapeDecoderOptions(...));   // filtered

// Decode a byte buffer — returns 0+ Events, buffers partial sequences
$events = $decoder->decode($bytes);

// Get unconsumed remainder after decode()
$remainder = $decoder->remainder();

// Resolve a held lone ESC when deferTrailingEscape is on (else [])
$events = $decoder->flushDeferredEscape();

// Observability counters (all zeroed by reset())
$decoder->drainedReplyCount();    // replies surfaced as TerminalReplyEvent
$decoder->droppedUnknownCount();  // complete sequences consumed with no event
$decoder->discardedPasteBytes();  // bytes dropped past the paste cap

// Clear every buffer, flag and counter — the documented recovery lever
$decoder->reset();
```

Feed whole reads; do NOT feed the decoder one byte per `decode()` call as a
policy. A byte-at-a-time stream makes every bare `ESC` resolve eagerly to the
`Escape` key under the default options (the deferred mode just holds it
forever until flushed). Real reads deliver whole frames; if your reader can
legitimately return 1 byte at a time, enable `deferTrailingEscape` and bound
it with an idle timer. `decode()`'s per-call work is linear in the chunk it
is handed — no leg re-slices the remaining stream per escape — so a giant
blob costs proportional time, never quadratic; the drivers' 8192-byte reads
are the recommended ceiling for latency, not for safety.

Feature groups toggle through `EscapeDecoderOptions` — five `public readonly`
bools (`enableKitty`, `enableFocus`, `enablePaste`, `enableMouse`,
`deferTrailingEscape`); a disabled family's frames become counted drops, so
construct the mix you want (named arguments make that a one-liner).

### Input drivers

| Driver | Reads | Notes |
|---|---|---|
| `StreamInputDriver` | any readable stream resource (e.g. `STDIN`) | Non-blocking by contract: takes over the stream's blocking flag on construction, restores it in `__destruct`; anything that is not a resource is refused at the constructor. Each `read()` pulls at most two 8192-byte chunks (a partial frame is retried once) through the internal `EscapeDecoder` and returns events one at a time in order. `isEof()` distinguishes "input ended" from `read()`'s shared idle-`null`; `hasPending()` reports buffered events or a partial sequence. `escapeDecoder()` exposes the owned decoder for `reset()`, `flushDeferredEscape()` and the counters. |
| `ReactInputDriver` | a `React\Stream\ReadableStreamInterface` | PUSHES events by `emit('data', Event)` — it is not an `InputDriver`. `isReadable()` follows React's contract (`true` until the stream closes, not "bytes pending"); `pause()` defers emission — already-buffered events flush on `resume()`. Incoming chunks are sliced at 8192 bytes (`MAX_CHUNK_SIZE`) with a lone-trailing-`ESC` carry law so a boundary can never split a sequence's escape byte from its params. `pipe()` forwards the RAW byte stream, not events — the React "data" event name means one event per emit here, not per chunk. `escapeDecoder()` exposes the owned decoder. |
| `SignalResizeDriver` | `SIGWINCH` | The production producer of `ResizeEvent`. Construction is side-effect free; `arm(): bool` is opt-in: it joins a weak-map fan-out, installs the process-wide handler, enables `pcntl_async_signals()`, probes current dimensions through `tput cols`/`tput lines` (capability names are refused before any shell-out unless they are a well-formed terminfo name, and stay quoted even after validation), and returns `false` where pcntl is absent. The handler itself only sets a per-instance flag — dimension probes run lazily in `read()`, so a resize-drag storm costs one probe pair, not 2×N forks from signal context. `disarm(): bool` reverses the last `arm()`: when the final armed driver leaves, the SIGWINCH disposition is handed back (see the one-disposition law below). |

One-disposition law: a process holds exactly one `SIGWINCH` handler and
`arm()` takes it. Installing your own `pcntl_signal(SIGWINCH, …)` after an
`arm()` silently blinds every armed driver (and the reverse silently kills
your handler). Responding to resizes needs no handler of your own — poll
`read()`; it returns the fresh dimensions.

### Terminal setup is the host's duty

This library never writes to the terminal. It only decodes what it is handed,
which means the bytes it models exist only if the HOST put the terminal into
the right shape first:

- **raw mode, echo off** — in canonical mode the line discipline buffers
  until Enter, converts `\n`, and ECHOES what the "attacker" injected; a TUI
  must disable ICANON/ECHO (via `stty`, termios, or `sugar-pty`) before
  driving a decoder. Arrow keys do not "work" without this step — they are
  waiting in the canonical line buffer.
- **mode negotiation** — DECSET/DECRST for mouse reporting (`?1000`/`?1003`/
  `?1006`), bracketed paste (`?2004`), focus reporting (`?1004`), and the
  Kitty keyboard flags query/enable sequence are the host's writes. The
  decoder is stateless about which modes are on; a disabled
  `EscapeDecoderOptions` family only filters parsing, it does not enable or
  disable anything on the wire.

### Bracketed paste

A paste larger than `PasteEvent::MAX_SIZE` (1 MiB) is handled in two phases:
the head is announced exactly once as a `PasteEvent` with `content` clipped
to the cap, `truncated` set, and `truncationNotice()` — a localized one-liner
suitable for showing the user why their paste looks short (`lang/en.php`;
locale resolution follows sugar-core's `I18n\Lang`). The REMAINING BODY IS
THEN DISCARDED — counted by `discardedPasteBytes()` — until the real
`CSI 201 ~` terminator arrives. Overflow bytes are never re-decoded as
keystrokes: an injected 1 MiB+ paste can end up commanding your input loop,
so the cap's force-close must consume, not reinterpret. If the terminator
never comes (an aborted or forged paste), the open episode simply stays
open — every following keystroke is absorbed rather than spoofed, and
`$decoder->reset()` is the documented way back: it discards the open episode
and zeroes buffers, flags and counters, no restart required.

Paste content is not normalized: CRLF from Windows clipboards rides through
verbatim, so split on `"\r\n"` as well as `"\n"` if you consume lines. The
held-back tail of a split `CSI 201~` marker stays in `remainder()` and is
never re-surfaced as its own event.

## Event types

| Event | Key fields |
|---|---|
| `KeyEvent` | `key`, `modifiers`, `raw`, `isRelease()`, `isRepeat` |
| `MouseEvent` | `x`, `y`, `button`, `action`, `modifiers` — `action` is `press`/`release`/`drag`/`scroll` (refused at the constructor otherwise); `button` is 0/1/2 for left/middle/right press-drag-release, and X10 releases report button 3 (`MouseEvent::BUTTON_RELEASE`) — match releases on `action`, not the button index; scroll steps report 96 (up), 97 (down), 98 (left/wire 66), 99 (right/wire 67); `x`/`y` are the units the reporting mode measures (cells under 1000/1006, pixels under 1016) |
| `FocusEvent` | `gained`, `raw` (`?string` — the `CSI I`/`CSI O` frame; null on host-synthesized events) |
| `PasteEvent` | `content`, `truncated`, `truncationNotice(): ?string` |
| `ResizeEvent` | `cols`, `rows` — produced by `SignalResizeDriver` (see [Input drivers](#input-drivers)) |
| `TerminalReplyEvent` | `family`, `params` (head-clamped to `MAX_PARAMS`, overflow counted in `paramsDropped`), `raw`, `body`, `truncated` |

Control characters surface as `KeyEvent` pairing a character with the Ctrl
modifier — 0x01 arrives as key `a` with `modifiers` carrying Ctrl, and the
four separators 0x1C–0x1F (FS/GS/RS/US) pair their punctuation twins the same
way: `\`, `]`, `^`, `_` under Ctrl. NUL (0x00) arrives as key `Space` with
Ctrl (keyboards type the byte as Ctrl+Space; the layout's Ctrl+@ twin reaches
the same byte). One vocabulary, stated and enforced: a bare 0x00–0x1F press
never surfaces as a raw control byte in `$key`, so a
`match ($event->key)` arm tests names, never `"\0"`; the wire bytes stay
intact in `raw`. Alt-modified presses of the symbolic control keys share it:
`ESC` + `0x09`/`0x0A`/`0x0D`/`0x7F`/`0x08` name
`Tab`/`Enter`/`Enter`/`Backspace`/`Backspace`
(with Alt), not raw bytes (a decoder quirk fixed long ago — if you matched
on `"\r"`, match on `'Enter'` plus Alt now). Under Alt a key name is the byte
itself — letters keep their character (uppercase arrives lower-cased), and
every other byte stays raw: Alt + `0x01` is the raw `"\x01"` with Alt, not the
`a` a Ctrl press produces.

### Key names (the complete vocabulary)

Names are stable strings; the match arms below are the whole space a decoder
can produce. Anything a release carries gains the `Release` prefix, with the
first letter upper-cased where the name begins with an ASCII letter — digits
and punctuation keep their character, so a released `0` arrives as `Release0`,
while a released `a` arrives as `ReleaseA` (`ReleaseArrowUp`, `ReleaseEnter`
are already initial-capital). Compare `isRelease()` rather than parsing the
prefix.

| Family | Names |
|---|---|
| Text | every printable character (`a`… `Z`, digits, punctuation, Unicode runes as the character itself), `Space` (Kitty `32u` only — legacy 0x20 and modifyOtherKeys keysym 32 surface as the literal ` `) |
| Editing/whitespace | `Tab`, `Enter`, `Backspace`, `Escape`, `Backtab`, `Insert`, `Delete`, `Home`, `End`, `PageUp`, `PageDown` |
| Arrows | `ArrowUp`, `ArrowDown`, `ArrowLeft`, `ArrowRight` |
| Function | `F1`–`F24` (legacy families), up to `F35` via Kitty |
| Keypad | `KP0`–`KP9`, `KPDecimal`, `KPDivide`, `KPMultiply`, `KPSubtract`, `KPAdd`, `KPEnter`, `KPEqual`, `KPSeparator`, `KPBegin`, `KPLeft`, `KPRight`, `KPUp`, `KPDown`, `KPPageUp`, `KPPageDown`, `KPHome`, `KPEnd`, `KPInsert`, `KPDelete` |
| Locks/navigation state | `CapsLock`, `ScrollLock`, `NumLock`, `PrintScreen`, `Pause`, `Menu` |
| Media (Kitty) | `MediaPlay`…`MediaRecord`, `LowerVolume`, `RaiseVolume`, `MuteVolume` |
| Modifier-as-key (Kitty) | `LeftShift`, `RightShift`, `LeftCtrl`, `RightCtrl`, `LeftAlt`, `RightAlt`, `LeftSuper`, `RightSuper`, `LeftHyper`, `RightHyper`, `LeftMeta`, `RightMeta`, `IsoLevel3Shift`, `IsoLevel5Shift` |
| Ctrl-paired | lowercase letter with the Ctrl modifier (`0x01` → `a`+Ctrl) |
| Ctrl-paired symbols | 0x00 → `Space` with Ctrl; 0x1C–0x1F → `\`, `]`, `^`, `_` with Ctrl (no bare control byte ever reaches `$key` — the raw wire byte stays in `raw`) |

Shift+letter is protocol-dependent — code accordingly: legacy terminals send
the capital directly (`Shift+a` → key `"A"`, NO `SHIFT` bit), the Kitty
protocol sends key `"a"` WITH the `SHIFT` bit. A match arm on `"A"` silently
dies the moment Kitty negotiation turns on; match `mb_strtolower($key)` plus
`$modifiers->includes(KeyModifier::SHIFT)` if you need case-independent
handling of both worlds.

Modifier state arrives **on events** — you read them; you do not build masks
(the `KeyModifier` constructor is private):

```php
if ($event->modifiers->includes(KeyModifier::CTRL)) { /* ... */ }
$event->modifiers->value(); // the underlying bitmask, for caching/comparison
$event->modifiers->equals($other); // exact equality between two KeyModifier values
```

The constants `SHIFT`(1), `ALT`(2), `CTRL`(4), `META`(8), `SUPER`(16),
`HYPER`(32) (plus `CAPS_LOCK`(64), `NUM_LOCK`(128)) are internal **storage
bits** in bubbletea's order — never wire values. The Kitty WIRE layout is a
different map (`Super`=8, `Hyper`=16, `Meta`=32), and the translation lives
exclusively in `KeyModifier::fromKittyInt()`, `fromXtermParam()` and
`fromSgrMouse()`. `fromKittyInt()` reads wire bits 0–5 plus the lock-state
bits 64/128 — which ride only the explicit `:event-type` sub-param form: in
the legacy form wire bit 32 doubles as the release flag (spec `meta` and the
legacy release flag collide at `0x20`), so it is masked off there and never
arrives as `META`. `fromXtermParam()` converts the legacy `1 + bitmask`
field, `fromSgrMouse()` extracts mouse-report bits. `shiftAltCtrl()`
(formerly `all()`, alias kept one release)
covers Shift+Alt+Ctrl only — the bits the legacy CSI forms can express — and
the named factories (`none()`, `alt()`, `ctrl()`, `shift()`, `altShift()`,
`ctrlShift()`, `altCtrl()`) exist for producers and tests, not as a public
combining API. The xterm legacy `+8` modifier slot is named `META` in this
library's mapping (the spec calls that bit "meta"; ctlseqs reserves "super"
for different reporting) — treat legacy `;9A` as Meta+ArrowUp and rely on the
Kitty path for true Super.

Modifier-field strictness is protocol-split. The Kitty path de-bases
leniently: an empty or `;0` field lands as modifier-none via
`max(0, mods − 1)`. The tilde `number ; 0 ~` and modifyOtherKeys
`27 ; 0 ; keysym ~` forms refuse a `0` field as a counted drop —
`fromXtermParam()` is 1-based, and a 0 there would compute `-1` and fabricate
every bit.

Reserved combinations (the legacy wire cannot distinguish these — do not
design keymaps against the distinction): `Ctrl+I` IS `Tab`, `Ctrl+J`/`Ctrl+M`
ARE `Enter`, `Ctrl+[` IS `Escape`, `Ctrl+@`/`Ctrl+Space` is NUL (it arrives as
`Space` with Ctrl),
and Alt via the `ESC` prefix collapses with Meta/Escape-then-key on legacy
terminals. The Kitty protocol disambiguates all of them; negotiating it is
the host's duty (above).

## Terminal replies — drained, not dropped

A terminal answers its queries on the **same file descriptor the user types
on**, so unsolicited reply bytes land in the middle of the keystroke stream:
a DA1 answer right after an arrow key, a cursor-position report (`ESC [ row ;
col R`) while the user holds a key, Kitty keyboard flags (`ESC [ ? flags u`).
The decoder recognizes every reply family below, **structurally consumes it**
(so the buffer never stalls and later keystrokes never vanish behind it), and
surfaces it as a `TerminalReplyEvent`:

| `family` | Sequence | Meaning |
|---|---|---|
| `device-attributes` | `CSI ? Pm c` / `CSI > Pm c` (bare `CSI Pm c` drains too) | DA1 / DA2 reply |
| `cursor-position` | `CSI row ; col R` | DSR/CPR report (never a phantom `F3`) |
| `dsr-status` | `CSI 0 n` / `CSI 3 n` (any `CSI … n` final drains) | DSR ok / not-ok reply — every `n`-final frame is drained so an echoed query can never mis-parse as a key |
| `window-report` | `CSI Pm t` | XTWINOPS geometry/resize report |
| `kitty-flags` | `CSI ? flags u` (incl. bare `CSI ? u`) | Kitty keyboard flags query/reply |
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
after 64 KiB of swallowed payload so keystrokes resume; the abandon folds the
still-in-hand body past the boundary into ONE counted drop rather than
replaying it as a flood of printable keys. Which noise bytes that drop covers
depends on where the reads fell; what cannot shift with chunk size is the
control behaviour: one drain, one counted abandon, keystrokes resuming
afterwards. Between the announce and the real terminator up to 64
KiB of further traffic — including genuine keystrokes — is silently absorbed;
that is the deliberate safety direction (a stuck prober swallows keys rather
than a forged string forging them). A pathological reply that terminates only
*after* exceeding the abandon budget is the one documented divergence: bytes
past the budget may already have resumed decoding as keys by the time the real
terminator arrives — which ones, exactly, depends on where the reads fell —
so the guarantee there is "keystrokes always come back", not "oversized
replies stay wholly silent".

### Observability: drained reply vs dropped unknown

Three counters on `EscapeDecoder` tell the silent paths apart:

```php
$decoder->drainedReplyCount();    // replies recognized and surfaced as TerminalReplyEvent
$decoder->droppedUnknownCount();  // complete sequences consumed with no event (incl. overflowed CSI)
$decoder->discardedPasteBytes();  // paste body bytes dropped past the 1 MiB cap
```

A rising `drainedReplyCount()` is normal terminal chatter; a rising
`droppedUnknownCount()` means bytes were consumed but produced no event. That
includes genuinely unrecognized sequences — private CSIs whose lead parameter
byte routes nowhere (`<` with mouse reporting off, `=`, `!`), unmapped SS3
finals, malformed or non-numeric mouse reports, and Kitty `u`-frames carrying an
unnamed codepoint (a control-range number such as `11u`, or a lone surrogate).
It **also** covers recognized-but-filtered frames — a Kitty event frame when the
progressive protocol is disabled, a disabled family's mouse report, or a stray
`CSI 201~` paste-end marker with no paste open. What never lands in this counter
is a complete `?`- or `>`-prefixed private report: those drain as
`TerminalReplyEvent`s. So a non-zero count does not necessarily mean this
terminal sends frames the decoder fails to model. `reset()` zeroes all
counters along with the buffers.

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

## Documented gaps

Complete-by-design omissions, pinned by tests so they stay known:

- Parameterized focus forms (`CSI 1;3I`) are dropped, not decoded — matches
  xterm, which never sends them.
- 8-bit C1 controls (`0x80`–`0x9F`) are not treated as sequence starts; they
  surface as raw printable keys. Modern emulators transmit 7-bit CSIs.
- The mouse motion-tracking distinction between modes 1003 and 1005 is a
  host-side mode choice; every `CSI < … M|m` frame decodes identically.

## Security: decoded content is untrusted

Everything this library emits is attacker-writable input. A terminal's input
buffer is not only fingers on keys: any process with permission can write bytes
into the pts slave, a remote `tmux send-keys` session injects keystrokes, and a
paste's own content is whatever the clipboard held. Treat key names,
`PasteEvent::content`, `TerminalReplyEvent::body` / `params`, and every `raw`
field as hostile bytes:

- Never echo decoded content straight back to the terminal — a crafted paste
  or reply body can carry further escape sequences and command your renderer.
  That includes OSC-8 hyperlinks: echoed content carrying `ESC ] 8 ; … \a`
  becomes a clickable URL in modern emulators, so strip or escape OSC-8 (and
  every other introducer) before display — sugar-core's `Sanitize` surface
  is the fleet's shared tool for this.
- Never interpolate it into shell commands, paths, or logs without your own
  quoting and redaction discipline.
- **Bracketed paste fences are forgeable.** `CSI 200~`/`CSI 201~` are just
  bytes: an attacker who can write the pts can open a paste window, hide
  anything inside it, or close one you did not start. Use paste framing for
  convenience (bulk text without keymap side effects), never as an
  authenticity boundary between "typed" and "pasted".
- **Caps never reinterpret.** A sequence that overflows its cap is refused
  with one counted drop (`droppedUnknownCount()`); paste bytes past the cap
  are discarded and counted by `discardedPasteBytes()` — the paste-only
  counter. Neither is ever pushed back into the stream for a second parse:
  an oversized frame cannot surface as synthesized keys, and an oversized
  paste body cannot masquerade as keystrokes after its announce.
- The fleet's private-use-area arena (zone sentinels U+E000/U+E001 and the
  image-marker block from U+E002) passes through decoding byte-identical — a
  hostile paste can forge marker bytes. Frame-marked composites should sweep
  with sugar-core's `SugarCraft\Core\Util\Sanitize::untrustedForMarkedFrames()`
  before rendering. (This library deliberately keeps the bytes: it decodes,
  it does not clean — the consumer that renders owns the sweep. sugar-core's
  `InputReader` is an example of a consumer that strips: it runs paste payloads
  through `Sanitize::untrustedForMarkedFrames()` when its paste sanitization
  is on.)

The decoder's own caps (partial sequence, 1 MiB paste, 1 KiB/64 KiB string
replies) contain parser resource use; they do not make the content trustworthy.
The fleet-wide contract lives in
[`docs/TRUST-BOUNDARY.md`](https://github.com/detain/sugarcraft/blob/master/docs/TRUST-BOUNDARY.md).

## Two decoders in the fleet

`sugar-input` is the byte-level decoder that `sugar-readline`, `sugar-pty` and
the input-facing apps consume. The fleet's other lineage is sugar-core's
`InputReader`: a stateful byte-stream parser that every sugar-core `Program`
runs on its stdin path — `candy-code` included, which carries no reader of its
own. The two are NOT guaranteed to agree on every
exotic frame, and neither patches the other's gaps at runtime. Pick ONE decoder
for a program's whole input path; do not pipe bytes through both. The
private-use-area law differs on purpose (above): this library passes PUA bytes
through, while sugar-core's `InputReader` sweeps them from paste payloads at
its own boundary. Convergence of
the two readers is tracked as a fleet finding, not a promise this README makes.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.

The decoding semantics follow [charmbracelet/bubbletea](https://github.com/charmbracelet/bubbletea)'s input handling; the class docblocks cite it as the reference (as do the tests, e.g. the deferred-trailing-ESC strategy). It decodes the same sequences that the kernel and terminal emulators produce.
