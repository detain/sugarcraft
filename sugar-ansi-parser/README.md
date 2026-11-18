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

PHP `^8.3`. `ext-mbstring` is a composer *suggestion*: at runtime it is
consulted only by the `$replaceMalformed = true` UTF-8 mode (see
[Limits & hostile input](#limits--hostile-input)) — default parsing never
touches it, and constructing with the flag but without the extension fails
loud at the constructor, not mid-stream.

ECMA-48 VT500 ANSI parser state machine — the shared byte-stream interpreter extracted from `sugar-vterm`. Feeds raw bytes through the Paul-Williams state machine, dispatching abstract `Handler` events. Handles partial input naturally; multi-byte UTF-8 runes arrive at the handler as complete **codepoints** (the parser's unit is a rune, not a grapheme cluster — clustering, where a base character plus combining marks must stay together, is the handler's concern).

The lib stops at abstract events: it recognizes bytes and hands them over. Cell grids, screen state, and cluster assembly are **rendering** — that lives in `sugar-vterm` and `sugar-cellbuffer`, not here.

## Status

🟢 Working. The VT500 state machine plus the abstract `Handler`, `CsiHandler`, and `OscHandler` boundaries live here as the shared source of truth. The `src/Codec/` decoders are the canonical extraction home for inspector-grade sequence semantics; `candy-ansi-inspect` already consumes the DCS one — see [Decoders](#decoders-codec).

**Consumers** — `sugar-vterm`, `candy-ansi-inspect`, `candy-codeshot`, `sugar-pty`, `candy-vcr`.

**Coverage (unmapped finals)** — `CsiHandler` covers the **renderer's working CSI set** — CUU/CUD/CUF/CUB, CUP/HVP, SGR, ED/EL, DECSTBM, DECSET/DECRST, TBC, CBT/CHT, SU/SD, IL/DL, ICH/DCH, REP, SCO save/restore, CR/LF — deliberately not every ECMA-48 final: HPA (`G`), VPA (`d`), ECH (`X`), DSR (`n`), DECSCUSR (`SP q`) and DA (`c`) have no method. That set is what `sugar-vterm` implements directly instead of forking its own parser.

**Implementing `CsiHandler`** — the interface is not just cursor and color callbacks: it also requires `printable(string $rune)` (one complete UTF-8 codepoint per call — see [Handler interface](#handler-interface)) and `gridRows()`/`gridCols(): int`. The adapter consults `gridRows()` for the DECSTBM bottom-margin default when that param is omitted, and SCO save/restore clamping is the handler's obligation against the grid it reports.

**Sieve & `$fallback` escape hatch** — the `HandlerAdapter` that bridges `Handler` events onto `CsiHandler`/`OscHandler` is a deliberate **sieve**: beyond those unmapped finals it also drops the whole ESC family, DCS, SOS/PM/APC payloads, and every execute except HT/LF/CR/BS (BEL, VT, FF, and the C1 band stop there too). Pass the optional third constructor argument — `new HandlerAdapter($csi, $osc, $fallback)` — and a fallback `Handler` recovers everything the sieve did not consume (the SCO-gated `CSI ? s` / `CSI ? u` discard is considered consumed and stays dropped on purpose). For code that must not accidentally build the silent-drop shape, `HandlerAdapter::wired($csi, $osc, $fallback)` is the named constructor with the fallback as a **required** argument, and `withFallback($fallback)` returns a *new* adapter with recovery wired in — the original is untouched.

**OSC 8** — the adapter accepts both the canonical `8;params;uri` form and the backtick-terminated OSC 8 form `8;uri` + `` ` ``; a malformed `8;…` payload closes the current link rather than being dropped. `RecordingOscHandler` ships here as a ready-made `OscHandler` — it stores the window title and tracks the current hyperlink pair — wired as `new HandlerAdapter($csi, new RecordingOscHandler())`.

**Known drift surface (retired 2026-11-13)** — `sugar-vterm`'s two sink-class near-copies folded: `Vterm\Parser\DebugHandler` became a deprecated class_alias onto this lib's bounded-ring `DebugHandler` (sap-20 vterm-leg), and `Vterm\Parser\OscHandlerImpl` turned out NOT to be a foldable copy — its pen-sink wiring and typed `Hyperlink` objects are vterm behavior this lib must not absorb — so it was renamed `Vterm\Parser\PenForwardingOscHandler` with the old FQN kept as a deprecated shim (sap-19 vterm-leg). The shared truth here remains the state machine and the interfaces.

Two implementation paths, both supported: the 7-method `Handler` interface is the implement-anywhere sink (every parser event, nothing interpreted — `DebugHandler` shows the full shape), while renderer-shaped consumers implement `CsiHandler` + `OscHandler` and receive filtered events through `HandlerAdapter`. If you need the full byte-level truth *and* the typed callbacks, wire your own `Handler` and forward to the adapter from it (or forward from the adapter to it as `$fallback`).

**Recording double (testing)** — `SugarCraft\AnsiParser\Support\RecordingCsiHandler` is a recording `CsiHandler` test double that ships in `src/` so consumers autoload it instead of hand-rolling anonymous doubles (which drift silently when the interface grows — this class is `final … implements CsiHandler`, so interface growth breaks it AT COMPILE TIME). Every call — queries `gridRows()`/`gridCols()` included — logs a `['type' => method, 'detail' => payload]` row in the `DebugHandler` vocabulary; the ctor answers the grid from `(rows: 25, cols: 80)` by default, `filter()`/`resetLog()` mirror `DebugHandler`, and passing `maxRows:` keeps only the most recent N rows while counting every cap drop in a public `$dropped` (cap drops are signalable, same law as `stringOverflowed()`).

**Null sink** — `NullHandler` is the shipped `Handler` that consumes everything and does nothing, with seven bare counter accessors (`printCount()` … `sosPmApcCount()`) so even a silent sink stays observable. It deliberately does NOT implement `BulkPrintHandler` — a null sink always wants the byte-level per-char truth, so `printCount()` counts runes, never runs.

**Bulk print (opt-in)** — implement `BulkPrintHandler` (a capability interface extending `Handler`, resolved once in the parser's constructor) and each contiguous Ground-state printable-ASCII run arrives as ONE `printRun(string $run)` call instead of one `printChar()` per byte — same events, same order, only the grouping changes. Multi-byte runes still arrive per-char. The split rule is the plain one: a run breaks on any byte outside printable ASCII `0x20-0x7E`, so spaces stay in-band (`'two three'` is ONE run) and any control, escape or high byte ends it. `HandlerAdapter` implements it as a loop over its own per-char leg, so a `CsiHandler` behind the adapter still observes exactly one `printable()` per rune.

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
  `mb_check_encoding()`, whose `ext-mbstring` is declared as a composer
  *suggest* — with the extension absent the constructor throws when the flag
  is requested, so flipping it is never a mid-stream surprise. Dropped with **no**
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
- **`DebugHandler` mirrors, bounded or not:** its default shape is an
  unbounded public `$log` — one row per dispatched action (test-fixture
  ergonomics on finite input). For a long-lived mirror pass
  `maxLogRows:`: a keep-newest ring that drops the oldest in batches (vterm's
  numbers: `MAX_LOG_ROWS` 100_000 with `EVICT_GRACE_ROWS` 4_096 headroom, a
  configured smaller cap scales the grace to itself), counts every eviction
  in a public `$dropped` (cap drops are signalable, like
  `stringOverflowed()`), and clears both with `resetLog()`.

Test breadth is hand-built edge pins (`ParserOverflowTest`,
`Utf8PolicyTest`, `DcsTruncationTest` and friends) plus seeded equivalence
matrices that pin the bulk fast path to the per-byte path on fixed corpora;
there is no continuous fuzzing in CI.

## Flat tokenizer and control names

Two smaller entry points sit beside the VT500 machine, for consumers that want
classified slices rather than handler callbacks.

**`AnsiTokenizer`** turns a byte stream into a `list<Token>`. Create one with
`AnsiTokenizer::new()`, call `parse($chunk)` for each read, and call
`flush()` at end of stream. An unfinished sequence at the end of a chunk is
held for the next `parse()` call. If the held bytes pass
`AnsiTokenizer::MAX_PENDING_BYTES` (64 KiB), they come back as one TEXT token
that starts at the ESC, and the tokenizer resynchronizes, so a stuck stream
cannot grow the buffer. The cap applies to the held partial only, so a large
chunk that ends in a short partial still holds that partial. The cap bounds
memory, not time. A held CSI, an ESC held on its C0 controls or intermediates,
and an unterminated OSC, DCS, APC, SOS or PM body each keep their scan position
across feeds, so each of their bytes is examined once however many feeds it
arrives in.
`Token` carries `type` (`TEXT`, `CONTROL`, `ESC`,
`CSI`, `OSC`, `DCS`, `APC`, `SOS`, `PM`), `data`, `intermediate`, `params`,
`final`, and `terminator`, and `paramInts()` reads the parameters as integers.
The field shape and type values match sugar-core's `Util\Token`. Unlike the
handler machine, a C1 byte (0x80–0x9F) is ordinary text here: only the 7-bit
`ESC` spellings open a sequence.

**`Codec\ControlNames`** names the C0 and C1 control bytes. `c0Name()` and
`c1Name()` return long labels such as `BEL (bell)`. `mnemonic()` returns the
bare abbreviation (`BEL`, `CSI`, and `DEL` for 0x7F), or `null` for a
non-control byte. The long labels come from the same table as the mnemonics.
Every C0 and C1 byte has a mnemonic here, including the 19 C1 bytes that
candy-ansi-inspect's sanitizer leaves as hex; see the migration map below.

```php
use SugarCraft\AnsiParser\AnsiTokenizer;
use SugarCraft\AnsiParser\Codec\ControlNames;
use SugarCraft\AnsiParser\Token;

$tokenizer = AnsiTokenizer::new();
foreach ($tokenizer->parse("ok\x1b[31m\x07") as $token) {
    if ($token->type === Token::CONTROL) {
        echo ControlNames::c0Name(ord($token->data)), "\n"; // BEL (bell)
    }
}
```

### Migration map (P1-14a)

What each replaced copy does, and where that behavior lives now. Nothing is
deleted in this change. Each copy is removed in the series step named below,
and its callers move in that step.

**Behavior map: sugar-core `Util\Parser`** (kept as a deprecated shim until the follow-up consumer step; the copy is not deleted in P1-14b)

| Behavior of the copy | Where it lives now |
|---|---|
| Printable and 8-bit bytes accumulate into one TEXT token | `AnsiTokenizer::parse()`, same |
| C0 controls and DEL: one CONTROL token per byte | `AnsiTokenizer::parse()`, same |
| `ESC` plus a byte: two-byte ESC token | `AnsiTokenizer::consumeEsc()`, same for two-byte finals. ESC intermediates (`ESC ( 0`, `ESC # 8`) are a declared change (below). An ESC followed by a C0 byte or a UTF-8 lead is a declared change too (below) |
| CSI: private marker, parameter bytes, intermediate bytes, final 0x40–0x7E | `AnsiTokenizer::consumeCsi()`, same, except an ESC inside the CSI and a C0 byte inside the CSI (declared changes below) |
| CSI with an invalid final: bare `ESC [` token, then resynchronize | `consumeCsi()`, same |
| OSC, DCS, APC, SOS, PM closed by BEL or ST, with `terminator` | `AnsiTokenizer::consumeStTerminated()`, same, except an ESC inside the body (declared change below) |
| Incomplete sequence held across `parse()` calls | `AnsiTokenizer::$pending`, same |
| `MAX_PENDING_BYTES` = 65536 | `AnsiTokenizer::MAX_PENDING_BYTES`, same value. `AnsiTokenizer` judges it on the held partial, not the buffer (declared change below). The `Util\Parser` shim still judges the whole buffer, as the copy did |
| `flush()` returns held bytes as one TEXT token and clears them | `AnsiTokenizer::flush()`, same |
| Token type constants and field names | `SugarCraft\AnsiParser\Token`, same values |
| `Token::paramInts()` | `SugarCraft\AnsiParser\Token::paramInts()`, same |
| Construction `new Parser()` | The `Util\Parser` shim keeps `new Parser()` and delegates to `AnsiTokenizer::new()`. Call sites move in the follow-up consumer step |
| Cap check on the whole buffer (held bytes plus the chunk) | `Util\Parser::parse()` with `AnsiTokenizer::pendingBytes()` (new, read-only), so the shim keeps the copy's rule |

**Declared behavior change (ESC inside a body).** An `ESC` inside a CSI or a
string body now ends that sequence, as the VT500 `Parser` does. A CSI cut short by
an ESC is dropped with no token, and the ESC opens the next sequence. A string cut
short by an ESC (not `ESC \`) is dispatched with an empty `terminator`, and the ESC
opens the next token. `ESC ESC` drops the first ESC. The copy in `Util\Parser`
swallowed the ESC into the body, so `ESC ] 0 ; t ESC [ A` gave one OSC token and
lost the key press. Pinned by `AnsiTokenizerTest::testEscInsideCsiAbortsTheSequenceAndRestarts`,
`testEscEscRestartsTheSequence`, `testEscInsideOscAbortsTheStringAndKeepsTheNextKey` and
`testEscInsideDcsAndApcAbortTheString`.

**Declared behavior change (ESC intermediates).** `ESC` followed by 0x20–0x2F
bytes and then a final 0x30–0x7E is one ESC token: the final goes in `data` and
the intermediates in `intermediate`, as the VT500 `Parser` dispatches it. So
`ESC ( 0` is `data` `0`, intermediate `(`, and `ESC # 8` is `data` `8`,
intermediate `#`. The copy in `Util\Parser` split these after the first byte, so
the final came back as TEXT and a DEC graphics switch printed as literal
characters. A sequence cut short by ESC or a non-final byte is dropped with no
token, as a CSI is. Pinned by `AnsiTokenizerTest::testEscIntermediateDesignationIsOneEscTokenWithNoTextLeak`,
`testEscDecalnAndS7c1tCarryTheirIntermediates` and `testEscIntermediateAbandonedByEscOrControlResyncs`.

**Declared behavior change (C0 and UTF-8 after ESC, C0 inside CSI).** The copy
treated the byte after an `ESC` as the ESC's final, and it read a C0 byte inside a
CSI as part of the CSI body. `AnsiTokenizer` does neither. A C0 byte after `ESC`
(`ESC 0x00 x`, `ESC 0x18 [ A`) is a CONTROL token that executes, and the ESC carries
on to the next byte. For `ESC 0x18`, the ESC is dropped. `ESC` followed by a UTF-8
lead (`ESC é`, bytes `1B C3 A9`) is dropped, and the rune after it is one TEXT token
with no ESC token, so an Alt+é arrives as the character rather than as an ESC with a
stray lead byte. A C0 byte inside a CSI (`ESC [ 3 BEL m`) executes first, and the
CSI is then dispatched with its parameters. The C0 byte is not inside the
parameters. DEL inside a CSI is ignored and the sequence continues. Copy output:
`ESC 0x00 x` gave ESC(0x00) + TEXT `x` and now gives CONTROL(0x00) + ESC `x`.
`ESC 0x18 [ A` gave ESC(0x18) + TEXT `[A` and now gives CONTROL(0x18) + TEXT `[A`.
`ESC 0xC3 0xA9` gave ESC(0xC3) + TEXT(0xA9) and now gives TEXT(0xC3A9). `ESC [ 3 BEL m`
gave ESC `[` + TEXT `3` + CONTROL BEL + TEXT `m` and now gives CONTROL BEL + CSI with
parameter `3` and final `m`. Pinned by `AnsiTokenizerTest::testC0AfterEscExecutesAndTheEscapeGoesOn`,
`testC0InsideCsiExecutesBeforeTheCsiIsDispatched`, `testEscBeforeAHighByteDoesNotSplitTheRune`
and `testDelInsideCsiIsIgnoredAndTheSequenceContinues`.

**Declared behavior change.** A chunk longer than 64 KiB whose tail is a short
unfinished sequence now holds that tail, as a short chunk does. The copy
judged the cap on the whole buffer, so it emitted the tail as text and
resynchronized. Checked on the copy and on this class with a chunk of 70,000 bytes
followed by `ESC [ 38;5;` (the first call), then a chunk `196m` (the second). `Util\Parser` gives TEXT
(70,000 bytes), then TEXT (`ESC [ 38;5;`), then TEXT (`196m`). `AnsiTokenizer`
gives TEXT (70,000 bytes) on the first call, then CSI with params `38;5;196`
and final `m` on the second. Pinned by
`AnsiTokenizerTest::testShortPartialAfterALargeChunkIsStillHeld`.

**Declared behavior change (`AnsiTokenizer` only).** A chunk longer than 64 KiB
whose tail is a short unfinished sequence now holds that tail on
`AnsiTokenizer`, as a short chunk does. The copy judged the cap on the whole
buffer, so it emitted the tail as text and resynchronized. `Util\Parser` keeps
the copy's rule: it judges the cap on the whole buffer and returns the held
tail as TEXT, so its callers see no change. Checked on the copy, on the shim,
and on `AnsiTokenizer` with a chunk of 70,000 bytes followed by `ESC [ 38;5;`
(the first call), then a chunk `196m` (the second). The copy and the shim give
TEXT (70,000 bytes), then TEXT (`ESC [ 38;5;`), then TEXT (`196m`).
`AnsiTokenizer` gives TEXT (70,000 bytes) on the first call, then CSI with
params `38;5;196` and final `m` on the second. The `AnsiTokenizer` behavior is
pinned by `AnsiTokenizerTest::testShortPartialAfterALargeChunkIsStillHeld`;
the shim behavior by `ParserTest::testOversizedChunkReturnsShortHeldTailAsTextLikeTheLegacyParser`
and `ParserTest::testOversizedBacklogCountsBytesHeldFromEarlierFeeds`.

**Behavior map: candy-ansi-inspect `C0C1.php`** (deleted in P4-9e)

- `C0C1::C0`, `C0C1::C1` tables, `c0Name()` and `c1Name()`, including the
  `C0 0x..` and `C1 0x..` fallbacks → `Codec\ControlNames::C0`, `C1`,
  `c0Name()`, `c1Name()`. The strings are identical. `ControlNamesTest` pins
  every label byte for byte.

**Behavior map: candy-ansi-inspect `Inspector::sanitizeLabelBytes()`** (the
mnemonic arms only, `Inspector.php` around lines 980–1001; the arms stay in the
Inspector until P4-9e)

- The 46 mnemonic arms (C0 including ESC, DEL, and the C1 bytes IND, NEL, HTS,
  RI, SS2, SS3, DCS, SOS, CSI, ST, OSC, PM, APC) → `ControlNames::mnemonic()`.
  Pinned by `ControlNamesTest::testInspectorMnemonicsAreReproduced`.
- The UTF-8-aware sweep regex and the `\xHH` default arm stay in the Inspector
  until P4-9e.
- **Divergence, not yet migrated:** the default arm renders 19 C1 bytes as
  `\xNN`: 0x80–0x83, 0x86, 0x87, 0x89–0x8C, 0x91–0x97, 0x99, 0x9A.
  `mnemonic()` names all of them (`mnemonic(0x80) === 'PAD'`). Migrating the
  Inspector changes its output for these bytes. P4-9e must either keep the hex
  form for them or change it in its own PR. Pinned by
  `ControlNamesTest::testC1BytesTheInspectorLeavesAsHexHaveMnemonicsHere`.

**Token types (sugar-core `Util\Token` → this package's `Token`)**

`Util\Token` is not aliased in P1-14b. It stays a separate class, because
`SgrState::apply*()` is public and typed to `Util\Token`. `Renderer` copies each
`SugarCraft\AnsiParser\Token` into a `Util\Token` at the boundary
(`Renderer::legacyToken()`). The alias, and the cut-over of the typed
callers, wait for the follow-up consumer step. sugar-core already requires
`sugarcraft/sugar-ansi-parser`, which has no dependencies, so there is no cycle.
The two classes are still not interchangeable. Files that name `Util\Token` and
must be checked when the alias lands:
`sugar-core/src/Renderer.php`, `sugar-core/src/SgrState.php`,
`sugar-core/tests/SgrStateTest.php`, `sugar-core/tests/Util/ParserTest.php`,
`candy-code/src/Renderer.php`, and `sugar-flexbox/tests/SgrStateDifferentialTest.php`.

**Unmapped extras (new here, no copy behind them).** `ControlNames::mnemonic()`
for the 19 C1 bytes listed above, and the public `ControlNames::C0` and
`ControlNames::C1` tables. Both exist only in this package.

### Consumers the follow-up steps must move (Util\Parser, Util\Token) and P4-9e (C0C1)

Found by `grep -rnF 'Util\Parser'` and `grep -rnF 'C0C1'` over the whole repo
(excluding `vendor/`), re-run on the P1-14b tree after `sugar-core/src/Renderer.php`
moved onto `AnsiTokenizer`, so that file no longer appears below. A line listed as
"use" is an import; "new" is a construction; "doc" is a comment or a `{@see}` link
with no code use.

Util\Parser (P1-14b):

- Production: `sugar-widgets/src/Help/Help.php:13` (use), `:421` (new);
  `candy-code/src/Renderer.php:9` (use), `:4519` and `:4557` (new);
  `sugar-core/src/SgrState.php:401` (doc). `sugar-core/src/Renderer.php` now
  imports `AnsiTokenizer` and `Util\Token` and has no `Util\Parser` reference.
- Tests: `sugar-core/tests/RendererTest.php:344` (FQN new);
  `sugar-core/tests/SgrStateTest.php:8` (use) and 26 `(new Parser())`
  calls; `sugar-core/tests/Util/ParserTest.php:7` (use) and `:15` (new);
  `sugar-widgets/tests/Help/HelpTest.php:202` (FQN new);
  `sugar-widgets/tests/Help/HelpReviewPinsTest.php:9` (use);
  `candy-code/tests/Renderer/RenderMemoTest.php:13` (use).
- Docs naming the class: `SECURITY.md:63`.

C0C1 (P4-9e):

- Production: `candy-ansi-inspect/src/AnsiHandler.php:453` (`C0C1::c0Name`) and
  `:503` (`C0C1::c1Name`).
- Tests that call the class: `candy-ansi-inspect/tests/C0C1Test.php` (the whole
  file pins the labels, and `ControlNamesTest` already pins the same strings);
  `candy-ansi-inspect/tests/SosPmTest.php:8` (use), `:41` and `:46` (calls).
- Tests that name it only in comments: `candy-ansi-inspect/tests/C1ClassificationSweepTest.php:30`
  and `candy-ansi-inspect/tests/StreamingInspectorTest.php:295-296`.

### Audit premises corrected by this map

- `candy-ansi-inspect` `C0C1.php` and `Inspector.php:980-1001` are not a
  duplicate of `Codec\LabelSanitizer`. `LabelSanitizer::clip()` escapes as
  `\xHH` and clips to a byte limit, and it stays. The duplicate target is
  `Codec\ControlNames`.
- `sugar-core` `Util/Parser.php` has callers beyond `Renderer.php`. The list
  above names every one: production callers in sugar-widgets and candy-code,
  and the tests.
- The C0C1.php deletion is P4-9e, not P1-14a. `candy-ansi-inspect` still calls
  `C0C1` at `AnsiHandler.php:453` and `:503`, and that package is outside this
  work package's lane. The plan's P1-14 entry, `UPSTREAM-PLAN.md` (section
  "P1-14"), and the trace row AUD-6 r42 (`reviews/UPSTREAM-TRACE.tsv:223`) still
  name P1-14a. The move is recorded here and in the PR body's dropped-claim
  record. Updating the trace row is a planning-tree edit, not part of this
  branch.

## Decoders (Codec)

`SugarCraft\AnsiParser\Codec` ships three **pure value decoders** — typed,
label-safe views over the fields `Handler` already delivers, with no parser
state of their own. All three escape and clip attacker-controlled bytes
before any terminal label (`describe()`). The namespace's one-way twin is
`KeySequence`: a key-name → wire-byte **encoder** (`TABLE`, `encode()`,
`names()` — 40 exact, case-sensitive names; unknown or empty names yield
`null`). Its values are the real bytes a keyboard produces, so a driven
program believes them (`Up` is `ESC [ C`, never a glyph; literal glyph
bytes ride the paste/IME path and exercise a different parser).

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
  `RecordingOscHandler` exposes the current `hyperlinkUri()`/`hyperlinkId()` (raw, unvalidated).
- Lone BEL, SO/SI and C1 bytes (0x80-0x9F) never reach `printable()`/
  `printChar()` through `HandlerAdapter` — consume the raw `Handler` (or wire
  it as the adapter's `$fallback`) if those signals matter to your bridge.
  But UTF-8-encoded C1 (`C2 80`-`C2 9F`) is *legal text*: the parser delivers
  those runes to `printChar()` in both modes and the adapter forwards them —
  a consumer re-emitting printable text must reject or replace U+0080-U+009F
  itself.

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
