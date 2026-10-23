<img src=".assets/icon.png" alt="sugar-log" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-log)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-log)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-log?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-log)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarLog

> Formerly published as `sugarcraft/candy-log` (renamed 2026-10). <!-- @rename-keep -->

sugar-log — a minimal, colorful leveled logging library for PHP 8.3+.

## Features

- **Leveled logging** — `Debug`, `Info`, `Warn`, `Error`, `Fatal` levels
- **Colorful human-readable output** — terminal-styled by default (Probe-driven: respects `NO_COLOR` / `FORCE_COLOR`)
- **Multiple formatters** — `TextFormatter` (default), `JsonFormatter`, `LogfmtFormatter`
- **Structured key/value pairs** — pass arbitrary context with every log call
- **Sub-loggers** — `with([...])` creates a child logger with persistent fields
- **Per-field styling** — `Styles::keys` maps field names to their ANSI styles
- **syslog-aligned levels** — integer values (-4/0/4/8/12) for easy threshold filtering
- **stdlog adapter** — wrap in `Log\StandardLogAdapter` for `*log.Logger` interface compatibility
- **PSR-3 bridge** — `PsrBridge` implements `Psr\Log\LoggerInterface` and satisfies type-hints anywhere a PSR-3 logger is expected
- **Record integrity** — colliding context keys are namespaced (`ctx_`), logfmt values escape `\` and `"`, control bytes are neutralised (see [Record integrity](#record-integrity))
- **Error-stream routing** — optional second stream for severe records (threshold defaults to `Error`) without moving everything else off `STDOUT`
- **Hook system** — register callbacks per log level via `HookRegistry::onLevel()`
- **Configurable log-part ordering** — `PartsOrder` DTO controls which parts appear and in what sequence

## Install

```bash
composer require sugarcraft/sugar-log
```

## Quick Start

```php
use SugarCraft\Log\Logger;
use SugarCraft\Log\Level;

$log = Logger::new();
$log->info('Starting oven', ['degree' => 375]);
$log->warn('Almost ready', ['batch' => 2]);
$log->error('Temperature too low', ['err' => 'underheated']);
```

## Levels

Levels are syslog-aligned integers — use `->value` for threshold comparisons:

```php
Level::Debug->value; // -4
Level::Info->value;  //  0
Level::Warn->value;  //  4
Level::Error->value;  //  8
Level::Fatal->value;  // 12

$log->info('info message');
$log->warn('warn message');
$log->error('error message');
$log->fatal('fatal message'); // throws RuntimeException
$log->print('always prints');   // bypasses the level FILTER — the label still renders
```

## Static Facade

`Log` is a process-shared façade over one lazily-created default `Logger` — the
package-level function style of charmbracelet/log:

```php
use SugarCraft\Log\Log;

Log::debug('compiling plan');
Log::info('baking started', ['temp' => 375]);
Log::warn('timer almost up');
Log::error('power cut');
Log::fatal('out of flour'); // writes, then throws RuntimeException

Log::default()->setOutput($fh); // reach the shared logger itself
```

Swap the shared logger wholesale with `Log::setLogger(Logger::new(...))`, and
`Log::reset()` returns to the lazily-created default.

## Structured Fields

```php
$log->info('Baking cookies', [
    'flour' => '2 cups',
    'butter' => true,
    'temp' => 375,
]);

// Child logger with persistent fields
$baker = $log->with(['user' => 'chef', 'session' => 'am']);
$baker->info('Batch started'); // also has user + session
```

## Formatters

```php
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;

$log = Logger::new(formatter: new JsonFormatter());
```

## Styling

Styles are applied automatically when the terminal supports color output.
Color is determined by `sugar-colorprofile`'s Probe — it respects the `NO_COLOR`
and `FORCE_COLOR` environment variables.

Override level styles via `Logger::styles()`:

> **Shared singleton:** `Logger::styles()` returns the process-wide
> `Styles::default()` instance. Mutating it changes every logger that was not
> given its own `Styles`. For per-logger styling, build a fresh `Styles`
> (or hand one to the formatter) and install it with `setStyles()`:

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Level;
use SugarCraft\Log\Styles;
use SugarCraft\Style\Style;

$styles = new Styles();
$styles->levels[Level::Error->value] = Style::new()->foreground(Color::parse('red'))->bold();
$log->setStyles($styles);
```

### Per-field styles

`Styles::keys` maps the log parts (`time`, `level`, `prefix`, `caller`,
`message`) and the field-pair roles (`key`, `value`) to individual `Style`
objects. Part roles ship as do-nothing styles (raw bytes, no SGR); the
formatter falls back to the matching property (`$timestamp`, `$prefix`,
`$caller`, `$message`) or the level colour map when a part style is unset:

```php
$styles = new Styles();
$styles->keys['time']    = Style::new()->foreground(Color::parse('cyan'));
$styles->keys['level']   = Style::new()->foreground(Color::parse('magenta')); // wins over the level map
$styles->keys['message'] = Style::new()->italic();
$styles->keys['value']   = Style::new()->foreground(Color::parse('green'));   // splits k=v into segments
$styles->values['batch'] = Style::new()->foreground(Color::parse('magenta')); // per-field override
$log->setStyles($styles);
```

Without a `value` style, each context pair is rendered as one `key=value` run
using `keys['key']`; setting `keys['value']` (or a per-field `values[$key]`)
renders key and value as separate styled segments around a bare `=`.

### Level text alignment

`Styles::padLevelText($label)` right-pads a level label to 5 characters for
column-aligned log output:

```php
Styles::padLevelText('INFO');  // "INFO "
Styles::padLevelText('DEBUG'); // "DEBUG"
```

Set `Styles::$padLevelText = true` to make `TextFormatter` apply it to every
rendered level label:

```php
$styles = new Styles();
$styles->padLevelText = true; // renders "INF  " / "DEBUG" instead of "INF" / "DEBUG"
$log->setStyles($styles);
```

## Output and streams

By default **every level is written to `STDOUT`**. This deliberately deviates
from charmbracelet/log, which writes to `os.Stderr`; the single-stream default
is kept because existing consumers rely on it. Two knobs change where records
go:

```php
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Level;

// 1. Custom stream — send a logger anywhere (immutable: returns a new Logger)
$fh  = fopen('/tmp/app.log', 'a');
$out = $log->withOutput($fh);

// 2. Error-stream routing — severe records go to a second stream
$err = fopen('php://stderr', 'w');
$log = Logger::new(errorStream: $err);                              // Error+Fatal → STDERR
$log = Logger::new(errorStream: $err, errorStreamAt: Level::Warn); // Warn and above → STDERR
$one = $log->withErrorStream(null);                                // back to a single stream
```

Routing is decided per record when it is emitted; `Fatal` still throws
`RuntimeException` after writing. Constructor and `setOutput()`/
`withOutput()`/`setErrorStream()`/`withErrorStream()` reject non-resource
streams with an `InvalidArgumentException`.

## Record integrity

Untrusted data can never forge or reshape a record:

- **Sanitisation** — text reaching `TextFormatter` and `LogfmtFormatter` passes
  through `Sanitize::untrusted()`, stripping ESC-based injection (terminal
  repositioning, hyperlinks, image markers) before anything is written; JSON
  output is escaped by `json_encode` and falls back to a minimal safe envelope
  if a value cannot be encoded.
- **logfmt escaping** — backslash and double-quote inside values are escaped
  (`\\`, `\"`), newline/CR/tab become `\n`/`\r`/`\t`, and a value is quoted
  whenever it contains whitespace, `=`, `"`, or `\`.
- **Reserved keys** — context keys colliding with the record envelope
  (`level`, `msg`, `time`, `caller`, `prefix`) are renamed with a repeated
  `ctx_` prefix in both JSON and logfmt output (`level` → `ctx_level`,
  `ctx_level` → `ctx_ctx_level`). Decoded records therefore always carry the
  *real* level, time and message no matter what a context array contains.

## Panic Handlers

```php
use SugarCraft\Log\Log;

// Install a panic handler that catches uncaught exceptions and fatal errors,
// restores the terminal from altscreen mode, and prints a styled panic report.
Log::installPanicHandler();

// Restore terminal state manually (exit altscreen, show cursor).
// Called automatically by the panic handler, but safe to call directly.
Log::restoreTerminal();
```

The panic handler catches uncaught exceptions and fatal PHP errors (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`), restores the terminal to a usable state, and prints a colorized banner with the error class, message, and backtrace. Internal frames with no file/line render honestly as `?:?`, and the report ends with a neutral footer identifying sugar-log for issue filing.

## PSR-3 Bridge

`PsrBridge` wraps a `Logger` instance and **implements** `Psr\Log\LoggerInterface`: all nine methods (`emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, `debug`, `log`) are real interface methods, so the bridge satisfies a `LoggerInterface` type-hint anywhere a PSR-3 logger is expected. `log()` accepts the PSR-3 spec's `mixed $level` — unknown or non-string levels fall back to `Info`:

```php
use SugarCraft\Log\Logger;
use SugarCraft\Log\PsrBridge;
use Psr\Log\LogLevel;

$logger = new Logger();
$psr = new PsrBridge($logger);

// All PSR-3 methods available
$psr->warning('Something is off', ['detail' => 'temperature rising']);
$psr->log(LogLevel::ERROR, 'Operation failed', ['code' => 500]);
```

The bridge also fires registered hooks before forwarding each message to the underlying logger, enabling middleware-style interceptors.

## Hook System

The hook system lets you register callbacks that fire whenever a log entry is emitted at or above a given level. Hooks receive the `Level`, PSR-3 level string, message, and context — useful for dispatching to external services, enriching context, or filtering.

> **Note:** Hooks fire **only via `PsrBridge`** — they do not fire when calling `Logger->info()` etc. directly. For the plain `Logger` API, hooks are not invoked. To use hooks, wrap your `Logger` in a `PsrBridge` and call methods on the bridge.

```php
use SugarCraft\Log\Logger;
use SugarCraft\Log\Level;
use SugarCraft\Log\Hook\HookRegistry;

$logger = new Logger();
$hooks = new HookRegistry();

// Register a callback for all Warn-and-above entries
$id = $hooks->onLevel(Level::Warn, function (Level $level, string $psrLevel, string $message, array $context) {
    // Dispatch to external service, enrich context, etc.
    file_put_contents('/tmp/warn.log', "[{$level->label()}] {$message}\n", FILE_APPEND);
});

// Pass hooks to the PsrBridge, or fire them manually
$hooks->fire(Level::Warn, 'warning', 'Something is off', []);
```

`HookRegistry::onLevel(Level, callable)` returns a registration ID. `HookRegistry::fire(Level, psrLevel, message, context)` dispatches to all handlers whose minimum level is met, and `remove($id)` unregisters a handler (unknown ids are ignored).

The `Hook` interface is also available for structured implementations:

```php
use SugarCraft\Log\Hook\Hook;
use SugarCraft\Log\Level;

final class MetricsHook implements Hook
{
    public function onLevel(Level $level, string $psrLevel, string $message, array $context): void
    {
        // Ship metrics to your observability platform
    }
}
```

## Parts Order

`PartsOrder` is a config DTO that controls which log-parts appear and in what sequence when formatting. It ships with three named presets:

```php
use SugarCraft\Log\PartsOrder;

// Default: timestamp level prefix? caller? message fields?
PartsOrder::default();   // [timestamp, level, prefix, caller, message, fields]

// Syslog-friendly: omits prefix and caller
PartsOrder::syslog();     // [timestamp, level, message, fields]

// Message-first: message comes before level and timestamp
PartsOrder::messageFirst(); // [message, level, timestamp, fields]

// Custom ordering
$order = new PartsOrder([PartsOrder::PART_MESSAGE, PartsOrder::PART_LEVEL, PartsOrder::PART_FIELDS]);

// Query whether a part is included
$order->has(PartsOrder::PART_CALLER); // false for syslog(), true for default()
```

Named part constants: `PART_TIMESTAMP`, `PART_LEVEL`, `PART_PREFIX`, `PART_CALLER`, `PART_MESSAGE`, `PART_FIELDS`.

## Caller Information

`CallerFormatter::find()` walks the call stack and returns `"file:line"` of the first frame outside the log package — the true call site:

```php
use SugarCraft\Log\CallerFormatter;

$caller = CallerFormatter::find(); // e.g. "my-script.php:42"
```

Used internally by formatters when `$reportCaller` is enabled on the `Logger`.

## Translations

Error-path prose — the two messages thrown by `Logger` (`logger.fatal`,
`logger.invalid_stream`) — is resolved through `sugar-core`'s `Lang` lookup in
`en` plus 15 locales (exact → base → `en` → raw). Log content (level labels,
timestamps, field text) is never translated.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
