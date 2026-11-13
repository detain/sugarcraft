# SugarAnsiParser

> Formerly published as `sugarcraft/candy-ansi` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Ansi\` → `SugarCraft\AnsiParser\`. <!-- @rename-keep -->

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-ansi-parser)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-ansi-parser)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

The badges above track the upstream monorepo, [`detain/sugarcraft`](https://github.com/detain/sugarcraft), where this library's CI and coverage run; the split package you install from Packagist is [`github.com/sugarcraft/sugar-ansi-parser`](https://github.com/sugarcraft/sugar-ansi-parser) — its issue and source URLs are the `homepage`/`support` fields in `composer.json`.

## Install

```sh
composer require sugarcraft/sugar-ansi-parser
```

PHP `^8.3`. `ext-mbstring` is a hard composer requirement; at runtime it is
consulted only by the `$replaceMalformed = true` UTF-8 mode (see
[Limits & hostile input](#limits--hostile-input)).

ECMA-48 VT500 ANSI parser state machine — the shared byte-stream interpreter extracted from `sugar-vterm`. Feeds raw bytes through the Paul-Williams state machine, dispatching abstract `Handler` events. Handles partial input naturally; multi-byte UTF-8 runes arrive at the handler as complete **codepoints** (the parser's unit is a rune, not a grapheme cluster — clustering, where a base character plus combining marks must stay together, is the handler's concern).

The lib stops at abstract events: it recognizes bytes and hands them over. Cell grids, screen state, and cluster assembly are **rendering** — that lives in `sugar-vterm` and `sugar-cellbuffer`, not here.

## Status

🟢 Working. The VT500 state machine plus the abstract `Handler`, `CsiHandler`, and `OscHandler` boundaries live here as the shared source of truth. The `src/Codec/` decoders are the canonical extraction home for inspector-grade sequence semantics; `candy-ansi-inspect` already consumes the DCS one — see [Decoders](#decoders-codec).

**Consumers** — `sugar-vterm`, `candy-ansi-inspect`, `candy-codeshot`, `sugar-pty`, `candy-vcr`.

**Coverage (unmapped finals)** — `CsiHandler` covers the **renderer's working CSI set** — CUU/CUD/CUF/CUB, CUP/HVP, SGR, ED/EL, DECSTBM, DECSET/DECRST, TBC, CBT/CHT, SU/SD, IL/DL, ICH/DCH, REP, SCO save/restore, CR/LF — deliberately not every ECMA-48 final: HPA (`G`), VPA (`d`), ECH (`X`), DSR (`n`), DECSCUSR (`SP q`) and DA (`c`) have no method. That set is what `sugar-vterm` implements directly instead of forking its own parser.

**Implementing `CsiHandler`** — the interface is not just cursor and color callbacks: it also requires `printable(string $rune)` (one complete UTF-8 codepoint per call — see [Handler interface](#handler-interface)) and `gridRows()`/`gridCols(): int`. The adapter consults `gridRows()` for the DECSTBM bottom-margin default when that param is omitted, and SCO save/restore clamping is the handler's obligation against the grid it reports.

**Sieve & `$fallback` escape hatch** — the `HandlerAdapter` that bridges `Handler` events onto `CsiHandler`/`OscHandler` is a deliberate **sieve**: beyond those unmapped finals it also drops the whole ESC family, DCS, SOS/PM/APC payloads, and every execute except HT/LF/CR/BS (BEL, VT, FF, and the C1 band stop there too). Pass the optional third constructor argument — `new HandlerAdapter($csi, $osc, $fallback)` — and a fallback `Handler` recovers everything the sieve did not consume (the SCO-gated `CSI ? s` / `CSI ? u` discard is considered consumed and stays dropped on purpose). For code that must not accidentally build the silent-drop shape, `HandlerAdapter::wired($csi, $osc, $fallback)` is the named constructor with the fallback as a **required** argument, and `withFallback($fallback)` returns a *new* adapter with recovery wired in — the original is untouched.

**OSC 8** — the adapter accepts both the canonical `8;params;uri` form and the backtick-terminated OSC 8 form `8;uri` + `` ` ``; a malformed `8;…` payload closes the current link rather than being dropped. `OscHandlerImpl` ships here as a ready-made `OscHandler` — it stores the window title and tracks the current hyperlink pair — wired as `new HandlerAdapter($csi, new OscHandlerImpl())`.

**Known drift surface** — `sugar-vterm` keeps near-copies of two sink classes (`Vterm\Parser\DebugHandler`, `Vterm\Parser\OscHandlerImpl`); the shared truth here is the state machine and the interfaces, and those copies are a known drift surface.

Two implementation paths, both supported: the 7-method `Handler` interface is the implement-anywhere sink (every parser event, nothing interpreted — `DebugHandler` shows the full shape), while renderer-shaped consumers implement `CsiHandler` + `OscHandler` and receive filtered events through `HandlerAdapter`. If you need the full byte-level truth *and* the typed callbacks, wire your own `Handler` and forward to the adapter from it (or forward from the adapter to it as `$fallback`).

**Recording double (testing)** — `SugarCraft\AnsiParser\Support\RecordingCsiHandler` is a recording `CsiHandler` test double that ships in `src/` so consumers autoload it instead of hand-rolling anonymous doubles (which drift silently when the interface grows — this class is `final … implements CsiHandler`, so interface growth breaks it AT COMPILE TIME). Every call — queries `gridRows()`/`gridCols()` included — logs a `['type' => method, 'detail' => payload]` row in the `DebugHandler` vocabulary; the ctor answers the grid from `(rows: 25, cols: 80)` by default, `filter()`/`resetLog()` mirror `DebugHandler`, and passing `maxRows:` keeps only the most recent N rows while counting every cap drop in a public `$dropped` (cap drops are signalable, same law as `stringOverflowed()`).

**Null sink** — `NullHandler` is the shipped `Handler` that consumes everything and does nothing, with seven bare counter accessors (`printCount()` … `sosPmApcCount()`) so even a silent sink stays observable. It deliberately does NOT implement `BulkPrintHandler` — a null sink always wants the byte-level per-char truth, so `printCount()` counts runes, never runs.

**Bulk print (opt-in)** — implement `BulkPrintHandler` (a capability interface extending `Handler`, resolved once in the parser's constructor) and each contiguous Ground-state printable-ASCII run arrives as ONE `printRun(string $run)` call instead of one `printChar()` per byte — same events, same order, only the grouping changes. Multi-byte runes still arrive per-char. `HandlerAdapter` implements it as a loop over its own per-char leg, so a `CsiHandler` behind the adapter still observes exactly one `printable()` per rune.

## Quickstart

```php
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\AnsiParser\Parser\DebugHandler;

$handler = new DebugHandler();
$parser  = new Parser($handler);

// parseComplete() feeds then flushes end-of-stream: an in-flight OSC/DCS/
// SOS/PM/APC string still dispatches with the payload collected so far (and
// an incomplete UTF-8 rune becomes U+FFFD in $replaceMalformed mode). It
// cannot recover an in-flight CSI/ESC sequence or a half-read DCS prelude —
// those end the stream undispatched (see Limits & hostile input).
// For streaming/chunked input, use feed() + flush() separately.
$parser->parseComplete("hello\x1b[31mworld\x1b[0m");

// $handler->log contains every parse action, each one
// ['type' => ..., 'detail' => ...]; the first csi entry (from \x1b[31m) is
// ['type' => 'csi', 'detail' => ['final' => 109, 'params' => [31], 'prefix' => 0, 'intermediate' => 0]]
// and the second (from \x1b[0m) is
// ['type' => 'csi', 'detail' => ['final' => 109, 'params' => [0], 'prefix' => 0, 'intermediate' => 0]].
// $params is a list<int> in which -1 marks a default (omitted or empty)
// slot. Finals and prefixes are raw byte values (ord() of the character).
```

A fuller walkthrough lives in two examples: [`examples/debug.php`](examples/debug.php) walks one stream per `Handler` event family (print/CSI, execute, ESC, OSC, OSC 8 raw payloads, DCS, APC, C1 introducers), and [`examples/adapter.php`](examples/adapter.php) drives `HandlerAdapter` itself — the DECSET multi-mode fan-out, the OSC 8 open/close decode, and the sieve with its `$fallback` escape hatch.

## Handler interface

Implement `SugarCraft\AnsiParser\Parser\Handler` to consume parse events:

```php
interface Handler
{
    public function printChar(string $rune): void;           // one codepoint (UTF-8 rune)
    public function execute(int $byte): void;                 // C0/C1 control char
    public function csiDispatch(int $final, array $params, int $prefix, int $intermediate): void;
    public function escDispatch(int $final, int $intermediate): void;
    public function oscDispatch(string $data): void;
    public function dcsDispatch(int $final, array $params, int $prefix, int $intermediate, string $data): void;
    public function sosPmApcDispatch(string $kind, string $data): void;
}
```

## Subparameters and multi-mode dispatch

ECMA-48 separates parameter *slots* with `;` and *subparameters* with `:` —
`CSI 4:3 m` (curly underline) is one slot with a subparameter, not two
parameters, and `CSI ? 1000 ; 1006 h` sets **two** modes. The parser exposes
both faithfully:

- `Parser::subparams()` returns, during and after each `csiDispatch` (and
  `dcsDispatch`) until the next sequence's params start accumulating, or until
  `flush()`/`reset()`, whichever comes first, a `list<bool>` aligned with `$params`: `true` at index *i*
  when the value at
  *i* was followed by a `:` (the slot continues). A leading `:` marks the
  implicit default at index 0. This follows the upstream Go design's
  (`charmbracelet/x/ansi`) `Param.HasMore`
  side-channel, so SGR consumers can implement `58:2::148:199:255`-style
  colon forms without re-splitting the byte stream, and `Parser::groupSubparameters($params, $flags)`
  reconstitutes the nested `list<list<int>>` grouping in one call. SGR 58/59
  (underline colour set/unset) and the ambiguous `21` are pure handler
  concerns — this library guarantees the parameter structure they need,
  including lossless round-trips of what `sugar-core`'s
  `Util\Color::toUnderline()` emits (`58;2;r;g;b`, `58;5;n`).
- `SubparamsAwareHandler` is the **push** route: a `Handler` that implements this
  one-method capability (`setSubparams(array $subparams): void`) receives the
  flags from the parser immediately *before* each `csiDispatch` and
  `dcsDispatch`, so it never has to hold a reference to the parser that is
  dispatching it. Implementing it is optional and `Handler`'s signatures are
  unchanged — a handler that does not implement it is dispatched exactly as
  before. Both routes report the identical list; DCS is included because its
  prelude carries a real parameter string (`DCS 1;2:3q`), while ESC, OSC and
  SOS/PM/APC get no push because none of them has one.
- `HandlerAdapter` dispatches **every** parameter of `CSI h/l` to
  `CsiHandler::decset()/decrst()`, one call per mode, preserving the prefix —
  `CSI ? 1000 ; 1006 h` therefore sets both modes. The adapter's consumer —
  `sugar-vterm`'s renderer handler — can therefore attribute a later mouse
  byte sequence to the encoding that enabled it instead of assuming SGR;
  `candy-vcr`'s `MouseModeTracker` mirrors the same semantics on the raw
  `Handler` path. `candy-codeshot`'s `SgrStateHandler` is not an adapter
  consumer: it implements `SubparamsAwareHandler` and takes SGR
  subparameters from the parser on the raw route, with no mode handling.

The parser flags colon structure but does not interpret it; a handler that
neither implements `SubparamsAwareHandler` nor reads `subparams()` keeps its
old (semicolon-split) view of `$params`.

## How the machine works

`Parser` is the Paul-Williams VT500 state machine over raw bytes. It has 15
states and 10 actions (the `State` / `Action` enums; `State` order is
load-bearing because the transition table is indexed by value):

- **Ground** — printable text and C0 controls.
- **Escape → EscapeIntermediate** — after `ESC`; two-byte finals dispatch via
  `escDispatch()`. `ESC [`/`]`/`P`/`X`/`^`/`_` open the families below.
- **CsiEntry → CsiParam → CsiIntermediate** — CSI parameters (`;` and `:`
  are param bytes, `< = > ?` are prefixes, 0x20-0x2F intermediates) until the
  0x40-0x7E final dispatches `csiDispatch()`. Slot trivia, disclosed: the
  prefix and intermediate slots are single bytes that OVERWRITE on repeat —
  the *first* private prefix byte wins (`CSI ? < 25 h` keeps `?`, the `<`
  vanishes, and DCS prelude behaves the same), while a repeated intermediate
  keeps the *last* byte. Once in CsiIntermediate, a digit or another prefix
  byte (`0x30-0x3F`) drops the **whole sequence** to Ground with no dispatch,
  and the bytes after it then **echo** as ordinary printable text (`ESC [ SP
  5 m` prints `m` — the byte that triggered the drop is itself consumed by
  the transition) — a quirk inherited byte-identically from the
  charmbracelet table that diverges BEHAVIOURALLY from the Paul-Williams
  figure (`csi_ignore` swallows until the final) and from DEC. The
  reference-table parity is the claim, not behavioural silence: the quirk is
  disclosed here, not accidental.
- **DcsEntry → DcsParam → DcsIntermediate → DcsString** — the DCS prelude,
  then the passthrough payload until ST; `dcsDispatch()` carries the prelude
  fields plus the data.
- **OscString** — OSC payload until BEL or ST; bytes ≥ 0x80 stay in the
  payload (no rune escapes out of the string).
- **SosString / PmString / ApcString** — `ESC X` / `ESC ^` / `ESC _` payloads
  until ST, dispatched via `sosPmApcDispatch()`.
- **Utf8** — continuation-byte collector; entered on a lead byte (0xC2-0xF4)
  and emitting one `printChar()` per completed rune.

The transition table is generated once as a 4096-byte string — 16 rows ×
256 codes indexed `state << 8 | byte`; 15 rows hold live states and row 15 is
padding for the 4-bit state mask — with each entry packing
`(action << 4) | nextState` into one byte. Anywhere-rules: CAN/SUB (0x18 /
0x1A) execute and abort in-flight escape/CSI sequences; **inside a string
state** they dispatch the payload collected so far before aborting to Ground
— a cancelled OSC/DCS/SOS/PM/APC never vanishes unlisted (fidelity contract;
the cancel byte itself is consumed as the terminator, not echoed; the DCS
body carries no truncated flag beyond the `final === 0` no-final-byte
sentinel);
`ESC` clears and re-syncs, dispatching the interrupted string
so `ESC \` is a terminator rather than a lost byte; the *different-type* C1
bleed guard (cross-type introducer discards the stale payload, same-type one
deliberately keeps it) applies **inside SOS/PM/APC strings only**, and there
only to the four string-family introducers 0x98-0x9F — every other byte
≥ 0x80 accumulates into the payload. The DEC figure aborts or executes C1
controls in those strings — and charmbracelet's anywhere edges hand
0x9B/0x90/0x99/0x9A to them too — but here those bytes are payload as well:
the three states absorb every byte ≥ 0x80 except their own terminators
(0x1B/0x18/0x1A/0x9C, which dispatch per the fidelity contract above) and the
four re-introducers. That is a deliberate, disclosed parity deviation with
the OSC/DCS 0x20-0xFF payload handling, not a silent one. OSC and DCS payloads carry no such
guard: the table maps OscString/DcsString 0x20-0xFF to `Put`, so introducers,
C1 controls and high bytes all stay in the payload, and only ESC, BEL (OSC),
ST and the CAN/SUB cancels above end those strings. Bytes with no mapped
transition follow the `None → Ground` default row and are dropped — the
[Limits & hostile input](#limits--hostile-input) section names the exact
bands this covers (and the C1 band it does *not*: those bytes execute).

## Limits & hostile input

The parser is positioned for untrusted byte streams; the bounds are the
product:

- **Payload cap:** DCS/OSC/SOS/PM/APC payload stops accumulating at **64 KiB**
  (default; constructor arg 3, `$maxStringBuffer`, changes it — any positive
  int, so smaller values tighten the cap — by explicit consumer choice;
  sugar-vterm keeps the default). Past the cap, payload bytes are dropped —
  both the per-byte path and the bulk sweep — and the drop is **signalable**:
  `Parser::stringOverflowed()` read from inside `oscDispatch()`/
  `dcsDispatch()`/`sosPmApcDispatch()` reports whether THAT dispatch's
  payload was truncated (the window is the same contract as `subparams()`:
  consumed by the dispatch that carries it, discarded unread when the
  prelude is abandoned without dispatch — cross-type C1 cancel, `clear()`).
  The complement, not the same signal: `Codec\Iterm1337`'s File view reports
  `payload truncated` only when fewer bytes arrive than declared (a payload
  LONGER than declared passes unflagged, as the check is one-way;
  `Codec\KittyApc` has no size declaration to cross-check and simply
  describes a short payload as-is) — decoders receive the parser-side
  truncation truth only where their owner wires the accessor. Chunked
  protocols are still the real answer.
- **Parameter caps:** at most **32 slots**; a `:` or `;` arriving at the cap
  is dropped and following digits merge into the 32nd slot, and each value
  clamps at **65535**. Past the cap the colon-structure flags stay aligned
  with `$params` but grouping stops being faithful.
- **Single-byte prefix/intermediate slots:** `collect()`/`prefix()` overwrite,
  so the *first* prefix byte survives (later ones never reach `prefix()`) and
  a multi-intermediate CSI (`CSI 0x2F 0x21 m`) keeps only the last byte —
  known parity deviations, disclosed here rather than silently lossy.
- **OSC payloads drop internal C0:** bytes 0x00-0x06 and 0x08-0x17, plus
  0x19 and 0x1C-0x1F arriving *inside* an OSC string are discarded without
  entering the payload (reference-table fidelity); a payload can therefore
  differ from the raw bytes that were sent. BEL (0x07) is not part of the
  drop band — it terminates the string and dispatches it.
- **Codec and handler accessors hand back raw wire text:** `OscHandler`
  implementations receive `hyperlink($uri, $id)` and `title()` payloads
  exactly as dispatched, and the decoders expose `Iterm1337::name()` and
  `args()` (whose `rawurldecode` pass can re-synthesize control bytes from
  percent-encoded input) and `KittyApc::control()` unvalidated. None of these
  sanitize — validate or reject before display or re-emission; the
  escape-and-clip discipline described under [Decoders](#decoders-codec) is
  confined to `describe()` and does not extend to the raw accessors.
- **A UTF-8 lead mid-sequence abandons that sequence:** a byte 0xC2-0xF4
  arriving while an escape/CSI (or its ESC-feed twin) is in flight is taken
  by the anywhere-table's Utf8 edge — the completed rune PRINTS (or is
  replaced under `$replaceMalformed`) and the machine lands back in Ground,
  while the in-flight sequence never dispatches; the next introducer's
  `Clear` edge resets the slots, so later sequences are unaffected.
  DEC/xterm would ignore the lead and continue collecting the sequence;
  charmbracelet's table abandons it and the parity here is with the table
  (finding sugar-ansi-parser-015, disclosed deliberately). Pinned behaviorally
  by `ParserTest::testUtf8LeadMidSequenceAbandonsIt`.
- **UTF-8 is not validated by default:** with `$replaceMalformed = false`
  (the default, and what sugar-vterm passes), structurally-completed rune
  byte-sequences are forwarded **verbatim** to `printChar()` — overlong,
  surrogate and >U+10FFFF encodings included. A handler relying on the
  interface's "one rune" contract should pass `$replaceMalformed = true`
  (constructor arg 2) for U+FFFD replacement instead; that mode calls
  `mb_check_encoding()` (the lib already requires ext-mbstring via composer
  regardless, because the flag must work when flipped). Dropped with **no**
  replacement in either mode: lone continuation bytes 0xA0-0xBF and invalid
  lead bytes 0xC0, 0xC1 and 0xF5-0xFF — the state table owns the raw byte
  space before UTF-8 is consulted, so those bytes never assemble a rune.
  *Incomplete* runes split by mode: a sequence interrupted mid-rune, and a
  partial rune abandoned at end-of-stream (`flush()`/`reset()`), produce
  nothing at all under the default and one U+FFFD each under
  `$replaceMalformed = true`. The C1 band 0x80-0x9F is NOT dropped text —
  lone C1 bytes execute as controls or introduce DCS/CSI/SOS/OSC/PM/APC
  sequences like anywhere else; their UTF-8-encoded forms (C2 80-C2 9F) are
  legal text — see the assistive notes below.
- **`reset()` is not silent:** it flushes first, so an in-flight
  OSC/DCS/SOS/PM/APC **dispatches** to the handler before state clears. The
  abandon verb is `discard()`: no dispatch, no replacement-character emission
  even in `$replaceMalformed` mode (a pending rune's emission would itself be
  output) — the machine just lands in Ground and the next sequence starts
  clean. The flush recovers *string* states only: an in-flight CSI/ESC
  sequence and a half-read DCS prelude end undispatched either way.
- **`DebugHandler` has no cap:** its public `$log` grows one row per dispatched
  action, forever; it is a development mirror, not a production sink.

Test breadth is hand-built edge pins (`ParserOverflowTest`,
`Utf8PolicyTest`, `DcsTruncationTest` and friends); there is no continuous
fuzzing in CI.

## Decoders (Codec)

`SugarCraft\AnsiParser\Codec` ships three **pure value decoders** — typed,
label-safe views over the fields `Handler` already delivers, with no parser
state of their own. All three escape and clip attacker-controlled bytes
before any terminal label (`describe()`).

| Class | Entry point | Reads |
|---|---|---|
| `Dcs` | `Dcs::parse(int $final, array $params = [], int $prefix = 0, int $intermediate = 0, string $data = '')` — pass `dcsDispatch()`'s five arguments through | `kind()` reports `sixel` / `xtversion` / `decrqss` / `decrpss` / `tmux` / `truncated` / `dcs`; `final()`, `params()`, `prefix()`, `intermediate()`, `data()`, `isSixel()`, `isTruncated()`, `describe()` |
| `Iterm1337` | `Iterm1337::parse(string $data)` (the `oscDispatch()` payload; returns `null` when the payload is not 1337 — `malformed()` describes a rejected-as-mine payload, never a not-mine one) and `Iterm1337::fromDcs(int $final, string $data)` for the tmux `ESC P tmux; …` envelope — **out-of-band payloads only**: this lib's parser terminates DCS at `0x1B`, so parser-dispatched data never contains the doubled-ESC introducer; the tmux arm fires only for caller-assembled or externally-produced payloads (finding sugar-ansi-parser-016) | OSC 1337 `File=name=…;size=…;inline=1:‹base64›` plus the control forms; `command()`, `isFile()`, `isPop()`, `args()`, `arg()`, `name()`, `declaredSize()`, `inline()`, `width()`, `height()`, `preserveAspectRatio()`, `type()`, `payloadBase64()`, `payloadLength()`, `estimatedBytes()`, `malformed()` (never throws — malformed input is recorded, parsed fields still exposed), `describe()` |
| `KittyApc` | `KittyApc::parse(string $data)` (the `sosPmApcDispatch()` payload with `kind === 'apc'`; returns `null` when the frame does not open with `G` — that is "not mine", and `malformed()` then marks a `G`-opened frame that is damaged) | kitty graphics frames `Gkey=value,…;payload` (no space after the `G`; `parse()` is byte-literal): `action()`, `imageId()`, `format()`, `control()`, `get()`, `sourceWidth()`/`sourceHeight()`, `columns()`/`rows()`, `x()`/`y()`/`z()`, `more()`, `isChunked()`, `width()`, `height()`, `payload()`, `payloadSize()`, `describe()` |

**Consumer status, honestly measured at this release:** `candy-ansi-inspect`
imports `Codec\Dcs` — its `Inspector::describeDcsParts()` classifies DCS
through the shared decoder (`Inspector::describeDcs()` remains only as a
payload-string compatibility shim). `Codec\Iterm1337` and `Codec\KittyApc`
have **zero repo consumers today**: the inspector still inlines its own
byte-count-only kitty label, and nothing else decodes 1337 payloads. The
classes are public API and the canonical home for this decoding, and the
inspect-side adoption is tracked as such.

## Notes for assistive / text-bridge consumers

If you re-voice or re-mix the event stream (screen-reader bridges, transcript
generators, plain-text exports):

- `printChar()` delivers **codepoints, not graphemes** — combining marks
  arrive as separate events after their base. Buffer and cluster with
  `grapheme_str_split()`/ICU before reading aloud, or every accent is a word.
- U+FFFD appears **only** in `$replaceMalformed` mode, and only for encodings
  that completed their byte count yet failed validity; dropped bytes produce
  *no* character at all, so a reconstructed transcript may be silently
  shorter than the sender's intent (see Limits above for the exact bands).
- Hyperlinks pair: an OSC 8 event opens a span and one with an empty URI
  closes it. Through `HandlerAdapter` a malformed `8;…` (no second `;`, no
  backtick terminator) closes the current link rather than being dropped; reading
  the raw `oscDispatch()` stream instead, treat every `8;`-prefixed payload
  as link-state and end the span on empty/malformed payloads yourself.
  `OscHandlerImpl` exposes the current `hyperlinkUri()`/`hyperlinkId()` (raw, unvalidated).
- Lone BEL, SO/SI and C1 bytes (0x80-0x9F) never reach `printable()`/
  `printChar()` through `HandlerAdapter` — consume the raw `Handler` (or wire
  it as the adapter's `$fallback`) if those signals matter to your bridge.
  But UTF-8-encoded C1 (`C2 80`-`C2 9F`) is *legal text*: the parser delivers
  those runes to `printChar()` in both modes and the adapter forwards them —
  a consumer re-emitting printable text must reject or replace U+0080-U+009F
  itself.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
