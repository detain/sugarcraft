<img src=".assets/icon.png" alt="candy-log" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-log)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-log)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/candy-log?label=packagist)](https://packagist.org/packages/sugarcraft/candy-log)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyLog

PHP port of [charmbracelet/log](https://github.com/charmbracelet/log) — a minimal, colorful leveled logging library.

## Features

- **Leveled logging** — `Debug`, `Info`, `Warn`, `Error`, `Fatal` levels
- **Colorful human-readable output** — terminal-styled by default (Probe-driven: respects `NO_COLOR` / `FORCE_COLOR`)
- **Multiple formatters** — `TextFormatter` (default), `JSONFormatter`, `LogfmtFormatter`
- **Structured key/value pairs** — pass arbitrary context with every log call
- **Sub-loggers** — `with([...])` creates a child logger with persistent fields
- **Per-field styling** — `Styles::keys` maps field names to their ANSI styles
- **syslog-aligned levels** — integer values (-4/0/4/8/12) for easy threshold filtering
- **stdlog adapter** — wrap in `Log\StandardLogAdapter` for `*log.Logger` interface compatibility

## Install

```bash
composer require sugarcraft/candy-log
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
$log->print('always prints');   // no level prefix
```

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
Color is determined by `candy-palette`'s Probe — it respects the `NO_COLOR`
and `FORCE_COLOR` environment variables.

Override level styles via `Logger::styles()`:

```php
use SugarCraft\Sprinkles\Style;
$log = Logger::new();
$styles = $log->styles();
$styles->levels[Level::Error] = Style::new()->foreground('red')->bold();
$log->setStyles($styles);
```

### Per-field styles

`Styles::keys` maps field names (`time`, `level`, `prefix`, `caller`,
`message`, `key`, `value`) to individual `Style` objects:

```php
$styles = $log->styles();
$styles->keys['time']   = Style::new()->foreground('cyan');
$styles->keys['caller'] = Style::new()->foreground('grey');
$log->setStyles($styles);
```

### Level text alignment

`Styles::padLevelText($label)` right-pads a level label to 5 characters for
column-aligned log output:

```php
Styles::padLevelText('INFO');  // "INFO "
Styles::padLevelText('DEBUG'); // "DEBUG"
```

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

The panic handler catches uncaught exceptions and fatal errors (E_ERROR, E_PARSE), restores the terminal to a usable state, and prints a colorized banner with the exception class, message, and backtrace.

## License

[MIT](LICENSE)
