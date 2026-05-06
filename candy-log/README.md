<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=candy-log)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=candy-log)
[![Packagist Version](https://img.shields.io/packagist/v/candycore/candy-log?label=packagist)](https://packagist.org/packages/candycore/candy-log)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# CandyLog

PHP port of [charmbracelet/log](https://github.com/charmbracelet/log) — a minimal, colorful leveled logging library.

## Features

- **Leveled logging** — `Debug`, `Info`, `Warn`, `Error`, `Fatal` levels
- **Colorful human-readable output** — terminal-styled by default (TTY detection)
- **Multiple formatters** — `TextFormatter` (default), `JSONFormatter`, `LogfmtFormatter`
- **Structured key/value pairs** — pass arbitrary context with every log call
- **Sub-loggers** — `With(...)` creates a child logger with persistent fields
- **Customizable** — prefix, timestamp format, report caller, styles
- **stdlog adapter** — wrap in `Log\StandardLogAdapter` for `*log.Logger` interface compatibility

## Install

```bash
composer require candycore/candy-log
```

## Quick Start

```php
use CandyCore\Log\Logger;
use CandyCore\Log\Level;

$log = Logger::new();
$log->info('Starting oven', ['degree' => 375]);
$log->warn('Almost ready', ['batch' => 2]);
$log->error('Temperature too low', ['err' => 'underheated']);
```

## Levels

```php
Logger::debug('debug message');
Logger::info('info message');
Logger::warn('warn message');
Logger::error('error message');
Logger::fatal('fatal message'); // calls exit(1)
Logger::print('always prints');
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
use CandyCore\Log\Formatter\TextFormatter;
use CandyCore\Log\Formatter\JsonFormatter;
use CandyCore\Log\Formatter\LogfmtFormatter;

$log = Logger::new(formatter: new JsonFormatter());
```

## Styling

Styles are applied automatically in TTY environments. Override via `Logger::styles()`:

```php
use CandyCore\Sprinkles\Style;
$log = Logger::new();
$styles = $log->styles();
$styles->levels[Level::Error] = Style::new()->foreground('red')->bold();
$log->setStyles($styles);
```

## License

[MIT](LICENSE)
