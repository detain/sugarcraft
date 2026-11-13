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
- **Colorful human-readable output** — styled when the destination is a color-capable terminal; the per-write gate is tty-ness plus `CLICOLOR_FORCE` (exactly `1`), while `NO_COLOR` and `FORCE_COLOR` act at construction time through the color-profile probe — see [Styling](#styling)
- **Multiple formatters** — `TextFormatter` (default), `JsonFormatter`, `LogfmtFormatter`
- **Structured key/value pairs** — pass arbitrary context with every log call
- **Sub-loggers** — `with([...])` creates a child logger with persistent fields (a channel analogue: give each subsystem its own `withPrefix()`/`with()` child)
- **Per-field styling** — the `Styles` instance property `values` maps individual field names to their styles; `keys` styles the generic key and value roles
- **Threshold-friendly levels** — integer values (-4/0/4/8/12, charmbracelet/log-aligned deltas — not RFC 5424 severities) for easy threshold filtering
- **stdlog adapter** — `StandardLogAdapter` offers a Go-style call pattern (`print`/`printLn`/`fatal`/`panic`) over a sugar-log logger — a shape, not an interface: it implements no external contract (see [Stdlog adapter](#stdlog-adapter))
- **PSR-3 bridge** — `PsrBridge` implements `Psr\Log\LoggerInterface`; the eight severity methods never throw (a failed write lands as an internal notice, not an exception), while `log()` rejects unknown level strings by throwing an `InvalidArgumentException`, as PSR-3 itself prescribes
- **Record integrity** — colliding context keys are namespaced (`ctx_`), logfmt values escape `\` and `"`, control bytes are neutralised (see [Record integrity](#record-integrity))
- **Error-stream routing** — optional second stream for severe records (threshold defaults to `Error`) while keeping all other output on `STDOUT`
- **Hook system** — register callbacks per log level via `HookRegistry::onLevel()` — fired via `PsrBridge` (direct `Logger` calls never dispatch hooks)
- **Configurable log-part ordering** — `PartsOrder` DTO controls which parts appear and in what sequence

## Install

```bash
composer require sugarcraft/sugar-log
```

> **Stability:** this package requires its `sugarcraft/` siblings at
> `dev-master`. A Composer project left on the default `stable` minimum-stability
> cannot resolve them — add `"minimum-stability": "dev", "prefer-stable": true`
> to the consuming root (or pin the released `sugarcraft/*` tags once cut). The
> monorepo publishes all libs together, so sibling versions always line up.

## Quick start

```php
use SugarCraft\Log\Logger;
use SugarCraft\Log\Level;

$log = Logger::new(level: Level::Debug); // default threshold is Info; Debug opts in
$log->debug('preheat cycle', ['stage' => 1]);
$log->info('Starting oven', ['degree' => 375]);
$log->warn('Almost ready', ['batch' => 2]);
$log->error('Temperature too low', ['err' => 'underheated']);
```

Every `Logger` constructor call also takes `formatter:`/`stream:`/`prefix:` — see [Output and streams](#output-and-streams). The `err` field has sugar: `withError()` writes the same shape from a string (as-is) or a `Throwable` (rendered `Class: message`) — as a persistent field the record context may still override.

## Levels

Levels carry charmbracelet/log-aligned integer deltas, from Debug at -4
through Fatal at 12. They are deliberately *not* RFC 5424 severities, so use
`->value` for threshold comparisons. A record is emitted when its level value is at or
above the logger's `minLevel` (default `Info`, hence the `Debug` opt-in
above). The two methods that bypass the filter are `print()` and its
formatted sibling `printf()`. A PSR-3 severity string (from env, config, or
the wire) maps onto this ladder via `Level::fromPsrString()`, which returns
`null` for unknown strings:

```php
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;

Level::Debug->value; // -4
Level::Info->value;  //  0
Level::Warn->value;  //  4
Level::Error->value;  //  8
Level::Fatal->value;  // 12

$log = Logger::new(); // threshold: Info
$log->info('info message');
$log->warn('warn message');
$log->error('error message');

try {
    $log->fatal('fatal message'); // writes the line, then throws RuntimeException
} catch (RuntimeException) {
    // the port's stop-signal: Go's log.Fatal would exit(1) right here
}

$log->print('always prints'); // bypasses the level FILTER — the label still renders
```

## Formatted helpers

Every level method has a `sprintf`-style sibling — `debugf()`, `infof()`,
`warnf()`, `errorf()`, `fatalf()`, plus `printf()` for the always-print
method — all sharing the shape `(string $format, mixed $context = [], mixed ...$args)`:

```php
use SugarCraft\Log\Logger;

$log = Logger::new();
$log->infof('batch %d done', 4);                      // scalar slot two = first sprintf arg
$log->infof('%d of %d loaves', ['oven' => 1], 7, 8);  // array slot two = context fields
```

Slot two is `mixed` on purpose: an array there is the record's context fields
(the shape every unformatted method takes); anything else is the first
`sprintf` argument — so `infof('count %d', 7)` formats the way the name
promises instead of `TypeError`-ing. For context alongside real arguments,
pass the array in slot two and the arguments after it (second line above).

The five level-bound helpers consult `enabled($level)` *before* running
`sprintf`, so a call the level filter would drop pays neither the formatting
cost nor the side effects of building its arguments (a `__toString()` spy
never gets called). `enabled(Level $level)` answers publicly whether a level
passes the current `minLevel`. `printf()` skips the guard exactly like
`print()` — it always renders — but still accepts the mixed slot two.

## Static facade

`Log` is a process-shared façade over one lazily-created default `Logger` — the
package-level function style of charmbracelet/log:

```php
use SugarCraft\Log\Log;

Log::info('baking started', ['temp' => 375]);
Log::warn('timer almost up');
Log::error('power cut');
// The shared logger's threshold is Info, so Log::debug('...') is dropped
// until it is lowered: Log::default()->setMinLevel(Level::Debug);

try {
    Log::fatal('out of flour'); // writes, then throws RuntimeException
} catch (RuntimeException) {
    // handle, or exit deliberately — this is where Go's Fatal would stop
}

$fh = fopen('php://stderr', 'w');
Log::default()->setOutput($fh); // reach the shared logger itself
// setOutput is process-lifetime state — the shared logger keeps $fh until it
// is re-pointed, so closing the handle here would leave every later Log::
// call that writes to a dead resource. Tear down by re-pointing first, then close:
// Log::default()->setOutput(STDERR);
// fclose($fh);
```

Swap the shared logger wholesale with `Log::setLogger(Logger::new(...))`, and
`Log::reset()` returns to the lazily-created default.

The five severity helpers have formatted twins — `Log::debugf()`,
`Log::infof()`, `Log::warnf()`, `Log::errorf()`, `Log::fatalf()` — with the
same sprintf semantics as the `Logger` `*f()` helpers (`fatalf` writes the
line, then throws). `Log::installPanicHandler()` is undone by
`Log::removePanicHandler()`, which restores whatever exception handling
existed before the first install.

## Structured fields

```php
use SugarCraft\Log\Logger;

$log = Logger::new();
$log->info('Baking cookies', [
    'flour' => '2 cups',
    'butter' => true,
    'temp' => 375,
]);

// Child logger with persistent fields
$baker = $log->with(['user' => 'chef', 'session' => 'am']);
$baker->info('Batch started'); // also has user + session
```

## Configuration mutators

Every mutable knob comes in two forms: `with*()` returns a modified **clone**
(the original is untouched — safe to derive shared loggers from it), while
`set*()` mutates the instance in place. Both apply identical validation.

| Knob | Clone | In place |
|---|---|---|
| Persistent fields | `with(array $fields)` | — |
| Prefix | `withPrefix(string)` | `setPrefix(?string)` |
| Formatter | `withFormatter(Formatter)` | `setFormatter(Formatter)` |
| Minimum level | `withMinLevel(Level)` | `setMinLevel(Level)` |
| Parts order | `withPartsOrder(PartsOrder)` | `setPartsOrder(PartsOrder)` |
| Output stream | `withOutput(mixed)` | `setOutput(mixed)` |
| Error stream | `withErrorStream(mixed, ?Level)` | `setErrorStream(mixed, ?Level)` |
| Timestamps | — | `setReportTimestamp(bool)` |
| Caller info | — | `setReportCaller(bool)` |
| Styles | — | `setStyles(Styles)` |

When `withFormatter()`/`setFormatter()` receives a plain `TextFormatter`, the
logger's timestamp/caller settings are preserved into the adopted formatter
(other formatter types are taken as-is).

## Formatters

```php
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Formatter\JsonFormatter;
use SugarCraft\Log\Formatter\LogfmtFormatter;
use SugarCraft\Log\Logger;

$log = Logger::new(formatter: new JsonFormatter());   // structured output
$log = Logger::new(formatter: new LogfmtFormatter()); // logfmt key=value
$log = Logger::new(formatter: new TextFormatter());   // human line (the default)
```

`JsonFormatter` and `LogfmtFormatter` take an optional second constructor
argument, `$timeFormat` — a `DateTime` format string; it defaults to
`DateTimeInterface::ATOM` because machine parsers pin timestamps to that
shape, so change it deliberately, not casually. A `TextFormatter` line's
timestamp defaults to `Y/m/d H:i:s` — full date, then time (the sample output
below shows that default).
Mid-process changes exist too: `setTimeFormat()`/`withTimeFormat()`
re-seed a live `TextFormatter` through its immutable wither (class and every
other option preserved); `Json`/`Logfmt` keep the format they resolved at
construction. Inside a **text** line,
array context values render as `[a b c]`: elements joined positionally,
associative keys not shown — use JSON or logfmt when key names matter.

## Styling

Styles are applied only when the destination can render them, and two
different layers decide that — they read different variables:

- **Construction (once, per logger/formatter):** `Probe::colorProfile()` from
  `sugar-colorprofile` evaluates the detection chain — `CLICOLOR_FORCE` and
  `FORCE_COLOR` each outrank `NO_COLOR` there — to pick the styled or plain
  default. Changing the environment afterwards does not re-probe an existing
  logger.
- **Per write (the destination gate):** `ColorGate` looks at exactly two
  things: `CLICOLOR_FORCE=1` (the exact value `1`; anything else is ignored)
  and whether the stream is a TTY. It never reads `NO_COLOR` or
  `FORCE_COLOR` — plain bytes reach files and pipes by design, and a TTY user
  who exports `NO_COLOR` after construction is not plain-ified per write.

In short: `NO_COLOR` is honored at construction (and at panic-handler
install, which re-probes), never inside the per-write gate. A standalone
`new TextFormatter(useColors: true)` still emits SGR by contract — the gate
lives in the `Logger`, not in the formatter.

Override level styles via `Logger::styles()`:

> **Style ownership:** a logger built without styles owns an independent
> snapshot, so mutating `Logger::styles()` changes that logger only. The
> process-wide `Styles::default()` singleton is opt-in: explicitly installed
> via `setStyles(Styles::default())`, it couples every rider. To stay private
> anyway, pick one of three sources and install it with `setStyles()`:
>
> 1. Build a fresh `Styles`.
> 2. Start from `Styles::snapshot()` — a private copy (mutable arrays fresh,
>    `Style` value-objects shared) safe to edit without touching the process
>    default.
> 3. Hand a `Styles` to the formatter instead of the logger.

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Styles;
use SugarCraft\Style\Style;

$log = Logger::new();
$styles = new Styles();
$styles->levels[Level::Error->value] = Style::new()->foreground(Color::parse('red'))->bold();
$log->setStyles($styles);
```

### Per-field styles

`Styles::keys` maps the log parts (`time`, `level`, `prefix`, `caller`,
`message`) and the field-pair roles (`key`, `value`) to individual `Style`
objects. Part roles ship as do-nothing styles (raw bytes, no SGR); the
formatter falls back to the matching property (`$timestamp`, `$prefix`,
`$caller`, `$message`) or the level color map when a part style is unset:

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Styles;
use SugarCraft\Style\Style;

$log = Logger::new();
$styles = new Styles();
$styles->keys['time']    = Style::new()->foreground(Color::parse('cyan'));
$styles->keys['level']   = Style::new()->foreground(Color::parse('magenta')); // wins over the level map
$styles->keys['message'] = Style::new()->italic();
$styles->keys['value']   = Style::new()->foreground(Color::parse('green'));   // splits k=v into segments
$styles->values['batch'] = Style::new()->foreground(Color::parse('magenta')); // per-field override
$log->setStyles($styles);
```

### High-contrast environments

This port's level palette deliberately diverges from charmbracelet/log:
upstream paints the levels purple-63/green-86/yellow-192/red-204/magenta-134,
while the shipped defaults here re-tune to bright-black-8/blue-4/yellow-3/
bold-red-1/white-on-bold-red-7 for terminal legibility — expect a silent
visual break when migrating charm setups. The shipped defaults put `Debug`, the caller tag and generic keys on
bright-black — roughly 2.5-3:1 against the default background, marginal for
long reading sessions. Labels always carry text (color is never the only
channel), but where contrast matters, override the roles explicitly:

```php
use SugarCraft\Core\Util\Color;
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Styles;
use SugarCraft\Style\Style;

$log = Logger::new();
$styles = Styles::snapshot();
$styles->levels[Level::Debug->value] = Style::new()->foreground(Color::parse('white'));
$styles->levels[Level::Info->value]  = Style::new()->foreground(Color::parse('cyan'))->bold(); // pure blue is the other marginal one
$styles->keys['key']                 = Style::new()->foreground(Color::parse('white'));
$styles->caller                      = Style::new()->foreground(Color::parse('white'))->bold();
$log->setStyles($styles);
```

Panic reports are not covered by these styles: the default `PanicFormatter::pretty()`
also paints the backtrace and footer in bright-black (ansi 8), and no `Styles`
override reaches it. To keep a panic report high-contrast — in practice, ANSI-free —
pass an explicit formatter: `Log::installPanicHandler(PanicFormatter::plain())`. The
`pretty()`/`plain()` factories are the public surface; there is no per-role style knob.

Without a `value` style, each context pair is rendered as one `key=value` run
using `keys['key']`; setting `keys['value']` (or a per-field `values[$key]`)
renders key and value as separate styled segments around a bare `=`.

### Level text alignment

`Styles::padLevelText($label)` right-pads a level label to 5 characters for
column-aligned log output (the formatter pads the short labels it renders):

```php
use SugarCraft\Log\Styles;

Styles::padLevelText('INF');   // "INF  " — 'INF' plus two pad spaces to width 5
Styles::padLevelText('DEBUG'); // "DEBUG" (already 5 wide, returned unchanged)
```

Set the `padLevelText` property on a `Styles` instance to make
`TextFormatter` apply the padding to every rendered level label:

```php
use SugarCraft\Log\Logger;
use SugarCraft\Log\Styles;

$log = Logger::new();
$styles = new Styles();
$styles->padLevelText = true; // renders "INF  " / "DBG  " instead of "INF" / "DBG"
$log->setStyles($styles);
```

## Output and streams

By default **every level is written to `STDOUT`**. This deliberately deviates
from charmbracelet/log, which writes to `os.Stderr`; the single-stream default
is chosen for pipe-and-file ergonomics (all levels on one stream, severe
records optionally split). Under a web SAPI (php-fpm)
those constants are not defined at all: every STDIO touch resolves lazily to
`php://stdout` / `php://stderr` (never `php://output` — output buffering would
capture the notice channel) and degrades to silence when the stream cannot be
opened, rather than crashing on an undefined constant. Either knob also accepts a file path string, which is opened append-mode on assignment (each assignment opens its own handle); an unopenable path throws with the path named. Two knobs change where
records go:

```php
use SugarCraft\Log\Formatter\TextFormatter;
use SugarCraft\Log\Logger;
use SugarCraft\Log\Level;

// 1. Custom stream — send a logger anywhere (immutable: returns a new Logger)
$log = Logger::new();
// fopen('a') creates the file as 0666 & ~umask — a typical umask of 0022
// leaves world-readable 0644; set umask 0077 (or chmod after) for a 0600 log.
// The mode arithmetic is POSIX: on Windows, log ACLs come from the parent
// directory instead.
$fh  = fopen(sys_get_temp_dir() . '/sugar-log-app.log', 'a');   // or pass the path string directly, below
$log = Logger::new(stream: sys_get_temp_dir() . '/sugar-log-app.log');  // append-mode, self-opened
$out = $log->withOutput($fh);

// 2. Error-stream routing — severe records go to a second stream
$err = fopen('php://stderr', 'w');
$log = Logger::new(errorStream: $err);                              // Error+Fatal → STDERR
$log = Logger::new(errorStream: $err, errorStreamAt: Level::Warn); // Warn and above → STDERR
$one = $log->withErrorStream(null);                                // back to a single stream
```

Routing is decided per record when it is emitted; `Fatal` still throws
`RuntimeException` after writing. Constructor and `setOutput()`/
`withOutput()`/`setErrorStream()`/`withErrorStream()` reject streams that are
neither resources nor openable paths with an `InvalidArgumentException`. A record that cannot be written
(closed or unwritable handle) never throws: it is retried once after a flush
and the loss is announced as a sanitized `[sugar-log] log write failed:`
notice on STDERR — including a sanitized single-line copy of the dropped
record itself (control bytes swept, line breaks flattened, but payload text
readable, so a secret carried in a record's message surfaces on STDERR when
its intended destination fails). File streams are buffered by PHP — call
`$log->flush()` before hard-exit paths (SIGKILL, `posix_kill`, `exit()` under
a fatal framework) so nothing still in the buffer is lost.

## Record integrity

Untrusted data can never forge or reshape a record:

- **Sanitisation** — text reaching `TextFormatter` and `LogfmtFormatter` passes
  through `Sanitize::untrusted()`, stripping ESC-based injection (terminal
  repositioning, hyperlinks, image markers) before anything is written;
  `JsonFormatter` sweeps the same control bytes from message, prefix, caller
  and every context key and value before encoding (json_encode escapes C0
  bytes but passes C1 bytes through verbatim), and substitutes invalid UTF-8
  instead of letting it fail the encode.
- **logfmt escaping** — backslash and double-quote inside values are escaped
  (`\\`, `\"`), newline/CR/tab become `\n`/`\r`/`\t`, and a value is quoted
  whenever it contains whitespace, `=`, `"`, `\`, U+2028, or U+2029.
- **Reserved keys** — context keys colliding with the record envelope
  (`level`, `msg`, `time`, `caller`, `prefix`, `_encode_error`) are renamed
  with a repeated
  `ctx_` prefix in both JSON and logfmt output (`level` → `ctx_level`,
  `ctx_level` → `ctx_ctx_level`). An empty context key namespaces the same way
  (`''` → `ctx_`): decoders never see a bare `=` token or a `""` member.
  Decoded records therefore always carry the
  *real* level, time and message no matter what a context array contains.
- **Hostile shapes** — reference cycles in context terminate at a depth cap
  (`[...]`), non-finite floats become `'INF'`/`'-INF'`/`'NAN'`, a throwing
  `__toString()` is contained (the object reports its class name), and if an
  encode fails anyway the JSON fallback drops only the offending field — the
  envelope and every other entry survive, flagged by `_encode_error`.

## Panic handlers

```php
use SugarCraft\Log\Log;
use SugarCraft\Log\PanicFormatter;

// Install a panic handler that catches uncaught exceptions and fatal errors,
// restores the terminal from altscreen mode, and prints a panic report —
// styled only when STDERR is a color-capable terminal at install time (or
// CLICOLOR_FORCE=1), plain ANSI-free text otherwise.
Log::installPanicHandler();

// $showLocals/$redactPaths configure only the formatter chosen by default.
// To force them alongside your own formatter, build them into it — passing
// both throws instead of silently dropping the knobs.
Log::installPanicHandler(PanicFormatter::plain(true, ['/etc/secrets']));

// Restore terminal state manually (exit altscreen, show cursor).
// Called automatically by the panic handler, but safe to call directly.
Log::restoreTerminal();
```

The panic handler catches uncaught exceptions and fatal PHP errors (`E_ERROR`, `E_PARSE`, `E_CORE_ERROR`, `E_COMPILE_ERROR`, `E_USER_ERROR`), restores the terminal to a usable state, and prints a banner with the error class, message, and backtrace. Styling is decided once, at install time, from whether STDERR is a color-capable terminal then (NO_COLOR suppresses it unless CLICOLOR_FORCE overrides); it is not re-evaluated per report, so a report captured to a file after a TTY install still carries ANSI unless you pass `PanicFormatter::plain()` explicitly. All four banner roles (`title`, `exception`, `backtrace`, `muted`) are replaceable per instance with `withTitleStyle()`, `withExceptionStyle()`, `withBacktraceStyle()` and `withMutedStyle()`, so the crash report's colors can be retuned without subclassing the formatter. Both factories take two knobs, `$showLocals` and `$redactPaths` — the latter a list of secret substrings (paths, tokens, any literal) replaced with `[redacted]` wherever it can surface — and those knobs configure only the formatter chosen by default: combining an explicit formatter with non-default knobs throws `InvalidArgumentException` rather than silently ignoring the caller's privacy settings. The exception message is treated as untrusted input: terminal-control bytes are swept and newlines flattened, so a message that embeds user data can never forge extra report rows and no escape sequences are emitted into whatever displays the output. `redactPaths` substrings are stripped from the message as they are from frames and locals under one policy: exact-substring matching on raw bytes (case-folded on Windows, where path case carries no meaning) before the control sweep, compared against both the configured spelling and its `realpath` when that path resolves, with empty entries ignored. Re-installing replaces the previous sugar-log handler instead of stacking. Internal frames with no file/line render honestly as `?:?`, and the report ends with a neutral footer identifying sugar-log for issue filing. `showLocals` dumps call arguments only for traces that carry them — PHP's engine omits `args` from exception traces, so for plain uncaught exceptions there are no locals to show; the knob is live for manually injected frame arrays.

## PSR-3 bridge

`PsrBridge` wraps a `Logger` instance and **implements** `Psr\Log\LoggerInterface`: all nine methods (`emergency`, `alert`, `critical`, `error`, `warning`, `notice`, `info`, `debug`, `log`) are real interface methods, so the bridge satisfies a `LoggerInterface` type-hint anywhere a PSR-3 logger is expected. The eight severity methods never throw — a hostile `__toString()` or a formatter that raises is contained and announced as a sanitized notice on STDERR, and the `RuntimeException` that backs `Fatal` is contained silently (its record already went out on the wire, so there is nothing to announce). Nothing propagates into the caller. Only `log()`, which takes the PSR-3 spec's `mixed $level`, may raise: an unrecognized or non-string level is an `InvalidArgumentException`, as PSR-3 itself prescribes for that method:

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

Level mapping, in one place: `emergency`/`alert`/`critical` → `Fatal`
(the record carries the `FTL` label and routes to the error stream when one is
installed — the `errorStream` knob on the wrapped `Logger`; by default no error
stream exists and every level is written to STDOUT, see
[Output and streams](#output-and-streams)) — but the bridge swallows the
`RuntimeException` that backs `Fatal` (Go's exit is not portable here);
`error` → `Error`; `warning` → `Warn`; `notice` and `info` →
`Info`; `debug` → `Debug`.

The bridge also fires registered hooks after the write attempt (see
[Hook system](#hook-system)), enabling middleware-style interceptors. A failed
write that was announced as an internal notice still reaches the hooks — they
are part of the observability surface — but records the level filter drops, and
messages whose `__toString()` explodes before a write is ever attempted, never
fire them. A hook
that throws is contained per-handler the same way — one hostile callback can
never cancel the walk or eat the log line.

## Hook system

The hook system lets you register callbacks that fire whenever a log entry is emitted at or above a given level. Hooks receive the `Level`, PSR-3 level string, message, and context — useful for reacting to a completed write: forwarding, counters, alerts. They run *after* the record is written and receive copies, so a hook can never modify or suppress the record it observes.

> **Note:** Hooks fire **only via `PsrBridge`**: calling `$logger->info()` or any other plain `Logger` method directly never dispatches them. To use hooks, wrap your `Logger` in a `PsrBridge` and call methods on the bridge. A record the bridge's level filter drops is neither written nor dispatched to hooks.

```php
use SugarCraft\Log\Logger;
use SugarCraft\Log\Level;
use SugarCraft\Log\Hook\HookRegistry;
use SugarCraft\Log\PsrBridge;

$logger = new Logger();
$hooks = new HookRegistry();

// Register a callback for all Warn-and-above entries
$id = $hooks->onLevel(Level::Warn, function (Level $level, string $psrLevel, string $message, array $context) {
    // React to the completed write: dispatch, count, alert — never mutate.
    file_put_contents(sys_get_temp_dir() . '/sugar-log-warn.log', "[{$level->label()}] {$message}\n", FILE_APPEND);
});

// Wire the registry into the bridge — this is how hooks fire
$psr = new PsrBridge($logger, $hooks);
$psr->warning('Something is off'); // the hook above runs after the record lands

// Unregister when done ($id came from onLevel())
$hooks->remove($id);

// The registry can also be fired directly by an integration:
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

Structured implementations register through `HookRegistry::addHook(Level, Hook): int` — the object-taking twin of `onLevel()`, whose second parameter is a bare `callable` (handing a `MetricsHook` to `onLevel()` is a `TypeError`). Both return a registration id that `remove()` accepts.

## Parts order

`PartsOrder` is a config DTO that controls which log-parts appear and in what sequence when formatting. It ships with three named presets:

```php
use SugarCraft\Log\PartsOrder;

// Default: all six parts in canonical order
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
The constructor rejects unknown or duplicated parts with an `InvalidArgumentException`
naming the offenders — a typo'd `'msg'` would otherwise silently delete the
message from every rendered line. `PartsOrder` is honored only by
`TextFormatter`; JSON and logfmt ignore it (a JSON object has no member order,
a logfmt record is fixed-shape).

## Caller information

The normal way to enable caller reporting is `Logger::setReportCaller(true)`
(or the `reportCaller:` constructor argument); the logger then tags every
line it writes:

```php
use SugarCraft\Log\Logger;

$log = Logger::new(reportCaller: true);
$log->info('who called me'); // line carries a <file:line> tag
```

The column itself is computed by `CallerFormatter::find()`, which walks the
call stack (skipping at most 20 frames) and returns `"basename:line"` of the
first frame outside the log package, or `null` when the call site is not
found within that budget:

```php
use SugarCraft\Log\CallerFormatter;

$caller = CallerFormatter::find(); // e.g. "my-script.php:42"
```

## Stdlog adapter

`StandardLogAdapter` mirrors the *shape* of Go's `log.Logger` methods —
`print()`/`printf()`/`printLn()`/`fatal()`/`fatalf()`/`panic()`/`panicf()`,
plus `errorf()` as the twin of `Logger::errorf()` (Go stdlog never had an
Errorf) — over a sugar-log `Logger`. Be honest about the boundary: PHP has
no `*log.Logger` interface and no `net/http`, so the adapter implements **no
contract**; it is a call-pattern bridge (hand it to code written against
that method roster):

```php
use SugarCraft\Log\Level;
use SugarCraft\Log\Logger;
use SugarCraft\Log\StandardLogAdapter;

$logger = Logger::new(level: Level::Debug);
$stdlog = new StandardLogAdapter($logger);               // un-forced
$warnlog = new StandardLogAdapter($logger, Level::Warn); // forced above Info
$stdlog->printLn('GET /health');  // lands like Logger::print(): INF label, bypasses the minLevel filter
$stdlog->printf('open %s in %dms', 'db', 12);            // vsprintf, then the print() path
$warnlog->printLn('disk 92% full'); // forced: rides the filter as WRN instead of bypassing
try {
    $stdlog->fatalf('listen %s failed', ':80'); // writes the record, then throws
} catch (RuntimeException) {
    // Go's Fatal would exit(1) here; the port's exit is a catchable throw
}
```

The constructor's second parameter, `$forceLevel`, decides what `print()`
(and `printf()`) does: with none forced — or with `Level::Info` — the write
bypasses the `minLevel` filter exactly like `Logger::print()`; with a level
above `Info` forced, `print()` goes through the filter like any ordinary
call at that level.

Go separates panic (recoverable unwind) from Fatal (`exit(2)`); in this port
the exit *is* a throw, so `fatal()` and `panic()` (and their `*f` twins)
write the same FTL record and throw the same `FatalLoggedException` — a
`RuntimeException` — which is precisely why catch-and-recover works here.

## Sample output

Three formatters, three records, no color (what a file sink or pipe receives):

```text
2026/11/03 07:15:04 WRN bakery <baker.php:88> Almost ready batch=2 oven=convection
{"level":"ERROR","msg":"Temperature too low","time":"2026-11-03T07:15:04+00:00","caller":"oven.php:31","prefix":"bakery","err":"underheated"}
time=2026-11-03T07:15:04+00:00 level=INFO msg="Starting oven" prefix=bakery degree=375
```

## Translations

Error-path prose — the messages thrown by `Logger`, `PsrBridge`, `PartsOrder` and `Log`'s
fatal labels (`logger.fatal`,
`logger.invalid_stream`, `logger.unopenable_stream`, `psrbridge.not_string`, `psrbridge.unknown_level`,
`parts.unknown`, `parts.duplicate` and the `log.fatal_*` labels) — is resolved through `sugar-core`'s `Lang` lookup in
`en` plus 15 locales (exact → base → `en` → raw). Log content (level labels,
timestamps, field text) is never translated. Selection is the **host
application's duty**: sugar-log never changes locale on its own — set it
through `sugar-core`'s i18n entry point at process start, and the routed
messages resolve accordingly.

## License

MIT

## Credits & inspiration

Originally inspired by the Go [Charm](https://github.com/charmbracelet) ecosystem; SugarCraft is developed as a native PHP project.
