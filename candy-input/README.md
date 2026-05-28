# CandyInput

Terminal escape sequence decoder for keyboard (legacy + Kitty progressive keyboard protocol) and mouse (SGR 1006). Provides the `InputDriver` interface and `EscapeDecoder` implementation.

## Overview

`candy-input` is the missing input layer for SugarCraft — it decodes raw TTY bytes into structured `Event` objects that programs can switch on. It handles:

- **Plain ASCII keys** — letters, digits, punctuation, control codes
- **Legacy escape sequences** — F1–F12, arrow keys, Home/End/PgUp/PgDn, Insert, Delete, Backspace, Tab, Enter, Escape
- **Kitty keyboard protocol** — disambiguation flags via CSI `?u`, including key release events
- **SGR 1006 mouse** — press, release, drag, and scroll with modifier support
- **Focus events** — DECSET 1004 via `CSI I` / `CSI O`
- **Bracketed paste** — `CSI 200 ~` … `CSI 201 ~` with 1 MiB safety cap

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

## Key constants (KeyModifier)

`Shift`, `Ctrl`, `Alt`, `Super`, `Hyper`, `Meta`, `CapsLock`, `NumLock` — combine with bitwise OR.

## No upstream parallel

This is a pioneering implementation for PHP TUI — there is no direct upstream to port. It decodes the same sequences that the kernel and terminal emulators produce.

## License

MIT
