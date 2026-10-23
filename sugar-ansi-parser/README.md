# SugarAnsiParser

> Formerly published as `sugarcraft/candy-ansi` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Ansi\` → `SugarCraft\AnsiParser\`. <!-- @rename-keep -->

`composer require sugarcraft/sugar-ansi-parser`

ECMA-48 VT500 ANSI parser state machine — the shared byte-stream interpreter extracted from `sugar-vterm`. Feeds raw bytes through the Paul-Williams state machine, dispatching abstract `Handler` events. Handles partial input naturally; multi-byte UTF-8 runes arrive at the handler as complete **codepoints** (the parser's unit is a rune, not a grapheme cluster — clustering, where a base character plus combining marks must stay together, is the handler's concern).

## Status

🟢 Working. The VT500 state machine plus the abstract `Handler`, `CsiHandler`, and `OscHandler` boundaries live here and are the shared source of truth for downstream consumers (`sugar-vterm`, `candy-ansi-inspect`, `sugar-quickpick`, `candy-codeshot`, `sugar-pty`, `candy-vcr`). `CsiHandler` covers the **renderer's working CSI set** — CUU/CUD/CUF/CUB, CUP/HVP, SGR, ED/EL, DECSTBM, DECSET/DECRST, TBC, CBT/CHT, SU/SD, IL/DL, ICH/DCH, REP, SCO save/restore, CR/LF — deliberately not every ECMA-48 final: HPA (`G`), VPA (`d`), EDCH (`X`), DSR (`n`), SCUSR (`SP q`) and DA (`c`) have no method and `HandlerAdapter` drops them silently. That set is what `sugar-vterm` implements directly instead of forking its own parser. The `src/Codec/` decoders are the canonical extraction home for inspector-grade sequence semantics but have **no external consumers yet** — see [Decoders](#decoders-codec). Note that sugar-vterm keeps near-copies of two sink classes (`Vterm\Parser\DebugHandler`, `Vterm\Parser\OscHandlerImpl`); the shared truth here is the state machine and the interfaces, and those copies are a known drift surface.

## Quickstart

```php
use SugarCraft\AnsiParser\Parser\Parser;
use SugarCraft\AnsiParser\Parser\DebugHandler;

$handler = new DebugHandler();
$parser  = new Parser($handler);

// parseComplete() feeds then guarantees end-of-stream flush so trailing
// sequences (OSC/DCS/incomplete UTF-8 runes) are not silently lost.
// For streaming/chunked input, use feed() + flush() separately.
$parser->parseComplete("hello\x1b[31mworld\x1b[0m");

// $handler->log now contains every parse action, each one
// ['type' => ..., 'detail' => ...]; the csi entries are
// ['type' => 'csi', 'detail' => ['final' => 109, 'params' => [31], 'prefix' => 0, 'intermediate' => 0]]
// and ['final' => 109, 'params' => [0], ...] respectively — $params is a list<int>.
```

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
  `dcsDispatch`), a `list<bool>` aligned with `$params`: `true` at index *i*
  when the value at
  *i* was followed by a `:` (the slot continues). A leading `:` marks the
  implicit default at index 0. This follows the original design's `Param.HasMore`
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
  previously only the first mode was delivered, so `?1000;1006h` silently
  dropped `1006` downstream. Consumers (e.g. `candy-vcr`'s mouse-mode
  tracker) can therefore attribute a later mouse byte sequence to the
  encoding that enabled it instead of assuming SGR.

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
  0x40-0x7E final dispatches `csiDispatch()`.
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
0x1A) execute and abort in-flight strings (their payload is dropped, never
bled forward); `ESC` clears and re-syncs, dispatching the interrupted string
so `ESC \` is a terminator rather than a lost byte; a *different-type* C1
introducer mid-string discards the stale payload (title-injection bleed
guard) while a same-type one deliberately keeps it. Bytes with no mapped
transition follow the `None → Ground` default row and are dropped.

## Limits & hostile input

The parser is positioned for untrusted byte streams; the bounds are the
product:

- **Payload cap:** DCS/OSC/SOS/PM/APC payload stops accumulating at **64 KiB**
  (default; constructor arg 3, `$maxStringBuffer`, raises it by explicit
  consumer choice — sugar-vterm keeps the default).
- **Parameter caps:** at most **32 slots**; a `:` or `;` arriving at the cap
  is dropped and following digits merge into the 32nd slot, and each value
  clamps at **65535**. Past the cap the colon-structure flags stay aligned
  with `$params` but grouping stops being faithful.
- **Single-byte prefix/intermediate slots:** `collect()`/`prefix()` overwrite,
  so a multi-intermediate CSI (`CSI 0x2F 0x21 m`) keeps only the last byte —
  a known parity deviation, disclosed here rather than silently lossy.
- **UTF-8 is not validated by default:** with `$replaceMalformed = false`
  (the default, and what sugar-vterm passes), structurally-completed rune
  byte-sequences are forwarded **verbatim** to `printChar()` — overlong,
  surrogate and >U+10FFFF encodings included. A handler relying on the
  interface's "one rune" contract should pass `$replaceMalformed = true`
  (constructor arg 2) for U+FFFD replacement instead; that mode calls
  `mb_check_encoding()`, so **ext-mbstring** is declared as a requirement.
  Lone continuation bytes and invalid lead bytes never enter the rune path at
  all; they follow the default drop row.
- **`reset()` is not silent:** it flushes first, so an in-flight
  OSC/DCS/SOS/PM/APC **dispatches** to the handler before state clears. To
  abandon a stream without a dispatch there is none yet; call `reset()`
  accepting the trailing flush (end-of-stream semantics).

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
| `Iterm1337` | `Iterm1337::parse(string $oscData)` (the `oscDispatch()` payload) and `Iterm1337::fromDcs(int $final, string $data)` for the tmux `ESC P tmux; …` envelope | OSC 1337 `File=name=…;size=…;inline=1:‹base64›` plus the control forms; `command()`, `isFile()`, `isPop()`, `args()`, `arg()`, `name()`, `declaredSize()`, `inline()`, `width()`, `height()`, `preserveAspectRatio()`, `type()`, `payloadBase64()`, `payloadLength()`, `estimatedBytes()`, `malformed()` (never throws — malformed input is recorded, parsed fields still exposed), `describe()` |
| `KittyApc` | `KittyApc::parse(string $apcData)` (the `sosPmApcDispatch()` payload with `kind === 'apc'`; a frame must open with `G`) | kitty graphics frames `G key=value,…;payload`: `action()`, `imageId()`, `format()`, `control()`, `get()`, `sourceWidth()`/`sourceHeight()`, `columns()`/`rows()`, `x()`/`y()`/`z()`, `more()`, `isChunked()`, `width()`, `height()`, `payload()`, `payloadSize()`, `describe()` |

**Consumer status, honestly measured at this release:** nothing outside this
library's own `tests/Codec/` imports the Codec namespace yet. In particular
`candy-ansi-inspect` still carries these branches inline
(`Inspector::describeDcs()` and the byte-count-only kitty label); migrating
it onto the codecs is an open seam, not a landed change. The classes are
public API and the canonical home for this decoding, and the inspect-side
adoption is tracked as such.

## Packages

| Badge | Description |
|---|---|
| [![CI](https://github.com/sugarcraft/sugar-ansi-parser/actions/workflows/ci.yml/badge.svg)](https://github.com/sugarcraft/sugar-ansi-parser/actions/workflows/ci.yml) | Unit tests |
| [![codecov](https://codecov.io/gh/sugarcraft/sugar-ansi-parser/branch/master/graph/badge.svg?flag=sugar-ansi-parser)](https://app.codecov.io/gh/sugarcraft/sugar-ansi-parser) | Coverage |

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
