<img src=".assets/icon.png" alt="sugar-datepicker" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-datepicker)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-datepicker)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-datepicker?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-datepicker)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarDatepicker

> Formerly published as `sugarcraft/sugar-calendar` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Calendar\` → `SugarCraft\DatePicker\`. <!-- @rename-keep -->

sugar-datepicker — an interactive date picker component for terminal UIs, for PHP 8.3+.

## Features

- **Month/year navigation** — prev/next buttons, year select
- **Keyboard navigation** — arrow keys, Enter to select, Esc to close
- **Date selection** — select/clear a date, visual cursor
- **Today highlight** — show current date distinctly
- **Selected date styling** — clear visual indicator
- **Pure renderer** — outputs ANSI strings, no external TUI framework needed

## Install

```bash
composer require sugarcraft/sugar-datepicker
```

## Quick Start

```php
use SugarCraft\DatePicker\Model;
use SugarCraft\DatePicker\DatePicker;

$picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
$picker = $picker->SelectDate();  // enter "select mode"

echo $picker->View();  // render calendar
```

## Navigation

```php
// Month navigation
$picker = $picker->GoToPreviousMonth();
$picker = $picker->GoToNextMonth();
$picker = $picker->GoToPreviousYear();
$picker = $picker->GoToNextYear();
$picker = $picker->GoToToday();

// Set arbitrary month
$picker = $picker->SetTime(new \DateTimeImmutable('2025-12-01'));

// Selection
$picker = $picker->SelectDate();   // confirm selection
$picker = $picker->ClearDate();    // clear selection
```

## Keyboard Handling

Handle keyboard input by calling the appropriate move methods:

```php
if ($key === 'left')  $picker = $picker->MoveCursorLeft();
if ($key === 'right') $picker = $picker->MoveCursorRight();
if ($key === 'up')    $picker = $picker->MoveCursorUp();
if ($key === 'down')  $picker = $picker->MoveCursorDown();
if ($key === 'enter') $picker = $picker->SelectDate();
if ($key === 'esc')   $picker = $picker->ClearDate();
```

## Shared foundations

- **[sugar-cellbuffer](https://github.com/sugarcraft/sugar-cellbuffer)** — month grid
  rendered as a 7-column × N-row `Buffer`; events placed into target cells;
  `Buffer::toAnsi()` produces canonical ANSI SGR output.
- **[sugar-testing](https://github.com/sugarcraft/sugar-testing)** — golden-file
  snapshot tests via `assertGoldenAnsi()` pin canonical month layouts.

## License

[MIT](LICENSE)

## Credits & inspiration

Design antecedent: [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker); SugarCraft is developed as a native PHP project.
