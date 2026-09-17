# CandyAnsi

`composer require sugarcraft/candy-ansi`

ECMA-48 VT500 ANSI parser state machine — the shared byte-stream interpreter extracted from `candy-vt`. Feeds raw bytes through the Paul-Williams state machine, dispatching abstract `Handler` events. Handles partial input naturally; multi-byte UTF-8 runes arrive at the handler as complete grapheme clusters.

Upstream: [charmbracelet/x/ansi](https://github.com/charmbracelet/x/tree/main/ansi/parser)

## Status

🟢 Working port. The VT500 state machine plus the abstract `Handler`, `CsiHandler`, and `OscHandler` boundaries live here and are the shared source of truth for downstream consumers (`candy-vt`, `sugar-spark`, `candy-hermit`, `candy-freeze`, `candy-pty`). `CsiHandler` now covers the full emulator CSI-final set (cursor, erase, scroll, insert/delete line & char, repeat, SCO save/restore, CR/LF) so `candy-vt` can implement this interface directly instead of forking its own parser.

## Quickstart

```php
use SugarCraft\Ansi\Parser;
use SugarCraft\Ansi\Parser\DebugHandler;

$handler = new DebugHandler();
$parser  = new Parser($handler);

// parseComplete() feeds then guarantees end-of-stream flush so trailing
// sequences (OSC/DCS/incomplete UTF-8 runes) are not silently lost.
// For streaming/chunked input, use feed() + flush() separately.
$parser->parseComplete("hello\x1b[31mworld\x1b[0m");

// $handler->log now contains every parse action:
// print 'h', 'e', 'l', 'l', 'o', csi(['31']), print 'w', 'o', 'r', 'l', 'd', csi(['0'])
```

## Handler interface

Implement `SugarCraft\Ansi\Parser\Handler` to consume parse events:

```php
interface Handler
{
    public function printChar(string $rune): void;           // grapheme cluster
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
  implicit default at index 0. This mirrors upstream's `Param.HasMore`
  side-channel, so SGR consumers can implement `58:2::148:199:255`-style
  colon forms without re-splitting the byte stream, and `Parser::groupSubparameters($params, $flags)`
  reconstitutes the nested `list<list<int>>` grouping in one call. SGR 58/59
  (underline colour set/unset) and the ambiguous `21` are pure handler
  concerns — this library guarantees the parameter structure they need,
  including lossless round-trips of what `candy-core`'s
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

Limits: the parser flags colon structure but does not interpret it; a handler
that neither implements `SubparamsAwareHandler` nor reads `subparams()` keeps
its old (semicolon-split) view of `$params`. Past the 32-parameter cap the two
lists stay equal in length but grouping stops being faithful — a `:` arriving
when the list is already full is dropped and the following digits merge.

## Packages

| Badge | Description |
|---|---|
| [![CI](https://github.com/sugarcraft/candy-ansi/actions/workflows/ci.yml/badge.svg)](https://github.com/sugarcraft/candy-ansi/actions/workflows/ci.yml) | Unit tests |
| [![codecov](https://codecov.io/gh/sugarcraft/candy-ansi/branch/master/graph/badge.svg?flag=candy-ansi)](https://app.codecov.io/gh/sugarcraft/candy-ansi) | Coverage |
