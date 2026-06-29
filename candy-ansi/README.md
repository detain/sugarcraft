# CandyAnsi

`composer require sugarcraft/candy-ansi`

ECMA-48 VT500 ANSI parser state machine — the shared byte-stream interpreter extracted from `candy-vt`. Feeds raw bytes through the Paul-Williams state machine, dispatching abstract `Handler` events. Handles partial input naturally; multi-byte UTF-8 runes arrive at the handler as complete grapheme clusters.

Upstream: [charmbracelet/x/ansi](https://github.com/charmbracelet/x/tree/main/ansi/parser)

## Status

🟡 Initial port — extracts the state machine from candy-vt. Consumer handlers (CsiHandler, OscHandler) remain in candy-vt until their cell-grid dependencies are refactored.

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

## Packages

| Badge | Description |
|---|---|
| [![CI](https://github.com/sugarcraft/candy-ansi/actions/workflows/ci.yml/badge.svg)](https://github.com/sugarcraft/candy-ansi/actions/workflows/ci.yml) | Unit tests |
| [![codecov](https://codecov.io/gh/sugarcraft/candy-ansi/branch/master/graph/badge.svg?flag=candy-ansi)](https://app.codecov.io/gh/sugarcraft/candy-ansi) | Coverage |
