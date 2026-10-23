<img src=".assets/icon.png" alt="sugar-datepicker" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-datepicker)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-datepicker)
[![Packagist Version](https://img.shields.io/packagist/v/sugarcraft/sugar-datepicker?label=packagist)](https://packagist.org/packages/sugarcraft/sugar-datepicker)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarDatepicker

> Formerly published as `sugarcraft/sugar-calendar` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Calendar\` → `SugarCraft\DatePicker\`. <!-- @rename-keep -->

sugar-datepicker — an interactive date picker component for terminal UIs, for PHP 8.3+.

## Features

- **Month/year navigation** — programmatic `GoTo*` steps; the view window is
  clamped to the calendar floor (January 0001) and to optional `min`/`max` bounds
- **Keyboard handling** — `handleKey()` for arrow keys, Home/End, Enter to
  select, Esc to clear (works in both single and range modes)
- **Date selection** — select/clear a single date, or a start/end range
- **Min/max date constraints** — navigation and selection refuse to leave the
  allowed window
- **TEA integration** — a `Model` wrapper implementing the sugar-core
  `Model` contract (`init()`/`update(Msg)`/`view()`), recording picker events
  into an optional `EventStore`
- **Today highlight** — show the reference "today" distinctly; `withoutToday()`
  drops the pinned reference again
- **Selected date and range styling** — five SGR style setters, validated
- **Pure renderer** — outputs ANSI strings, no external TUI framework needed

## Install

```bash
composer require sugarcraft/sugar-datepicker
```

## Quick Start

```php
use SugarCraft\DatePicker\DatePicker;

$picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
$picker = $picker->handleKey(DatePicker::KEY_ENTER);  // select the cursor date

echo $picker->View();  // render calendar
$selected = $picker->SelectedDate();  // 2026-05-01
```

Or drive it as a TEA model:

```php
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\Msg\KeyType;
use SugarCraft\DatePicker\Model;

$model = Model::new(new \DateTimeImmutable('2026-05-15'));
[$model, $cmd] = $model->update(new KeyMsg(KeyType::Right));
[$model, $cmd] = $model->update(new KeyMsg(KeyType::Enter));  // selects the date
echo $model->view();
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
```

Steps never throw and never wrap: the view position is clamped at the calendar
floor (January 0001 — earlier years cannot be represented as `Y-m-d` grid
dates) and, when set, at the `min`/`max` bound months. Stepping at a clamp
boundary is a no-op. The cursor index is re-clamped into the new month on
every navigation step, so navigating to a shorter month lands on its last day.

## Selection

Single mode (default) — `Enter` (via `handleKey` or the `Model`) selects the
date under the cursor; `Esc` clears the selection:

```php
$picker = $picker->handleKey(DatePicker::KEY_ENTER);  // SelectDate() under the hood
$picker = $picker->handleKey(DatePicker::KEY_ESCAPE); // ClearDate() under the hood
$picker = $picker->ClearDate();                       // programmatic equivalent
$picker = $picker->ToggleSelection();                 // select-or-clear in one call
```

If the cursor rests on an empty lead-in/trailing grid cell, Enter falls back to
the first of the viewed month (the cursor only moves within the current grid;
arrow keys do not wrap into neighbouring months).

Range mode — the first Enter sets the start, the second the end (normalized so
start ≤ end), a third restarts:

```php
$picker = $picker->withRangeMode(true);
$picker = $picker->handleKey(DatePicker::KEY_ENTER); // rangeStart
// ... move cursor ...
$picker = $picker->handleKey(DatePicker::KEY_ENTER); // rangeEnd (complete)
$picker = $picker->rangeStart();
$picker = $picker->rangeEnd();
```

## Min/max bounds

```php
$picker = $picker
    ->withMinDate(new \DateTimeImmutable('2026-03-10'))
    ->withMaxDate(new \DateTimeImmutable('2026-05-20'));

$picker->inBounds(new \DateTimeImmutable('2026-04-01')); // true
$picker = $picker->withoutMinDate();  // lift one bound again
$picker = $picker->withoutMaxDate();
```

Bounds are compared at midnight (time-of-day is ignored). Setting a bound
pulls the currently viewed month into the window and re-clamps the cursor.
Selecting a date outside the window is refused (the receiver is returned
unchanged); an existing selection is never retroactively cleared. Setting
`minDate` after `maxDate` (or `maxDate` before `minDate`) throws an
`InvalidArgumentException`.

## Today anchor

```php
$picker = $picker->withToday(new \DateTimeImmutable('2026-05-15')); // pin "today"
$picker = $picker->withoutToday();                                  // no today marker
```

`GoToToday()` navigates to the pinned date and has no effect while no today is
set.

## Rendering

```php
echo $picker->View();                 // default 21 columns, no week numbers
echo $picker->View(30, true);         // 30 columns with ISO week-number column
```

`View()` takes a column `width` and a `showWeekNumbers` flag. Widths outside
15–63 are silently clamped into that range (not rejected).

## Styling

Five style setters accept bare SGR digit strings (e.g. `'7'`, `'1;31'`,
`'38;5;226'`); anything containing escape sequences or dangling delimiters is
rejected with an `InvalidArgumentException`:

```php
$picker = $picker
    ->WithHeaderStyle('1;36')
    ->WithTodayStyle('1;33')
    ->WithSelectedStyle('1;32')
    ->WithCursorStyle('7')
    ->WithRangeStyle('4;35');
```

## Keyboard handling

`handleKey()` maps the eight `KEY_*` constants — `left`, `right`, `up`, `down`,
`home`, `end`, `enter`, `esc` — onto cursor movement, selection and clearing.
Unrecognized keys are ignored (the receiver is returned unchanged). The `Model`
wrapper translates sugar-core `KeyMsg` messages into these strings, so a TEA
application never calls `handleKey()` directly.

Honest limitations: there is no month-wrap on arrow keys, no page-up/page-down
month jumps, and no typeahead year entry — navigation between months and years
goes through the `GoTo*` methods.

## Internationalization

Day abbreviations and month names resolve through the shared sugar-core i18n
lookup (`Lang::t`): keys `day.0`–`day.6` (Sunday-first) and `month.1`–`month.12`
live in `lang/en.php`, which is the source of truth. Only English ships today;
additional locales are added as `lang/<code>.php` files carrying the same key
set.

## Shared foundations

- **[sugar-cellbuffer](https://github.com/sugarcraft/sugar-cellbuffer)** — month grid
  rendered as a 7-column × N-row `Buffer`; events placed into target cells;
  `Buffer::toAnsi()` produces canonical ANSI SGR output.
- **[sugar-core](https://github.com/sugarcraft/sugar-core)** — `Util\Width` is the
  canonical display-width oracle (the picker delegates its cell placement
  widths to it); `I18n\Lang` powers the translations above.
- **[sugar-testing](https://github.com/sugarcraft/sugar-testing)** — golden-file
  snapshot tests via `assertGoldenAnsi()` pin canonical month layouts.

## License

MIT

## Credits & inspiration

Design antecedent: [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker); SugarCraft is developed as a native PHP project.
