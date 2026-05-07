<img src=".assets/icon.png" alt="sugar-calendar" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-calendar)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-calendar)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcore/sugar-calendar?label=packagist)](https://packagist.org/packages/sugarcore/sugar-calendar)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.1-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarCalendar

PHP port of [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker) — interactive date picker component for terminal UIs. Inspired by the jQuery Datepicker widget.

## Features

- **Month/year navigation** — prev/next buttons, year select
- **Keyboard navigation** — arrow keys, Enter to select, Esc to close
- **Date selection** — select/clear a date, visual cursor
- **Today highlight** — show current date distinctly
- **Selected date styling** — clear visual indicator
- **Pure renderer** — outputs ANSI strings, no external TUI framework needed

## Install

```bash
composer require sugarcraft/sugar-calendar
```

## Quick Start

```php
use SugarCraft\Calendar\Model;
use SugarCraft\Calendar\DatePicker;

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

## License

[MIT](LICENSE)
