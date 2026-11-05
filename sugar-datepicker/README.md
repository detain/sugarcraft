<img src=".assets/icon.png" alt="sugar-datepicker" width="160" align="right">

<!-- BADGES:BEGIN -->
[![CI](https://github.com/detain/sugarcraft/actions/workflows/ci.yml/badge.svg?branch=master)](https://github.com/detain/sugarcraft/actions/workflows/ci.yml)
[![codecov](https://codecov.io/gh/detain/sugarcraft/branch/master/graph/badge.svg?flag=sugar-datepicker)](https://app.codecov.io/gh/detain/sugarcraft?flags%5B0%5D=sugar-datepicker)
[![Packagist](https://img.shields.io/badge/packagist-dev--master-informational)](https://packagist.org/packages/sugarcraft/sugar-datepicker)
[![License](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%E2%89%A58.3-8892bf.svg)](https://www.php.net/)
<!-- BADGES:END -->

# SugarDatepicker

> Formerly published as `sugarcraft/sugar-calendar` (renamed 2026-10). <!-- @rename-keep -->
> Namespace changed: `SugarCraft\Calendar\` → `SugarCraft\DatePicker\`. <!-- @rename-keep -->

sugar-datepicker — an interactive date picker component for terminal UIs, for PHP 8.3+.

## Features

- **Month/year navigation** — programmatic `GoTo*` steps, and `PageUp`/`PageDown`
  month steps driven through the `Model`; the view window is clamped to the
  calendar floor (January 0001) and to optional `min`/`max` bounds
- **Keyboard handling** — `handleKey()` for arrow keys, Home/End, Enter to
  select, Esc to clear (works in both single and range modes)
- **Date selection** — select/clear a single date, or a start/end range
- **Min/max date constraints** — the month view clamps to the allowed window
  and Enter silently refuses a selection outside it (the receiver comes back
  unchanged); the cursor can still rest on out-of-bounds days, which the grid
  renders dimmed
- **Model–Update–View (TEA) integration** — a `Model` wrapper implementing the sugar-core
  `Model` contract (`init()`/`update(Msg)`/`view()`), recording picker events
  into an optional `EventStore`
- **Today highlight** — show the reference "today" distinctly; `withoutToday()`
  drops the pinned reference again
- **Selected date and range styling** — seven SGR style setters, validated
- **Pure renderer** — outputs ANSI strings, no external TUI framework needed

## Install

```bash
composer require sugarcraft/sugar-datepicker:@dev
```

No tagged release yet — the package currently ships only `dev-master` from Packagist, so consume it with the `@dev` constraint above until the first version tag lands.

## Quick Start

```php
use SugarCraft\DatePicker\DatePicker;

$picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
$picker = $picker->handleKey(DatePicker::KEY_ENTER);  // select the cursor date

echo $picker->View();  // render the month grid
$selected = $picker->SelectedDate();  // 2026-05-01
```

Or drive it as a TEA model:

```php
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
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
every navigation step: it keeps its grid position whenever that position is a
day cell of the new month, so a step into a shorter month lands on its last
day only when the old index falls past the new month's day-cell window.
The mirror edge exists at the top: an unbounded picker stepping past year 9999
enters year 10000, whose grid date fails the strict four-digit `!Y-m-d` parse
(`%04d` is only a minimum width, so the formatted year is the literal
five-digit `10000`), leaving the date unresolvable — `View()` still renders
(reporting year 10000), but `dateAtCursor()` is `null` and `SelectDate()`
refuses. This tolerated upper edge never crashes.

## Selection

Single mode (default) — `Enter` (via `handleKey` or the `Model`) selects the
date under the cursor; `Esc` clears the selection:

```php
$picker = $picker->handleKey(DatePicker::KEY_ENTER);  // SelectDate() under the hood
$picker = $picker->handleKey(DatePicker::KEY_ESCAPE); // ClearDate() under the hood
$picker = $picker->ClearDate();                       // programmatic equivalent
$picker = $picker->ToggleSelection();                 // select-or-clear in one call
```

If the cursor rests on an empty lead-in/trailing grid cell, Enter in single mode
falls back to the first of the viewed month (the cursor only moves within the
current grid; arrow keys do not wrap into neighbouring months). In range mode
the fallback does not apply: Enter on an empty cell — or on an out-of-bounds
day — is a silent no-op and records no range step.

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
unchanged); an existing selection is never retroactively cleared. Each setter
throws an `InvalidArgumentException` only when the new bound crosses the
existing one — setting `minDate` after `maxDate` (or `maxDate` before
`minDate`) is accepted as long as the resulting pair stays ordered.

## Today anchor

```php
$picker = $picker->withToday(new \DateTimeImmutable('2026-05-15')); // pin "today"
$picker = $picker->withoutToday();  // drop the pin (falls back to the real clock)
```

`withoutToday()` removes only the pinned reference — the picker then treats the
real wall-clock as "today", so the highlight keeps rendering (it is not a way to
hide the marker; set `WithTodayStyle('')` to suppress it). `GoToToday()` navigates
to the pinned date, or to the real current day when nothing is pinned — unless
that day lies outside a `min`/`max` window, in which case the view stays
clamped inside the window (see the Min/max bounds section above).

## Rendering

```php
echo $picker->View();                 // default 21 columns, no week numbers
echo $picker->View(30, true);         // 34 columns: 30-wide grid + 4-cell week column
```

`View()` takes a column `width` and a `showWeekNumbers` flag. The TOTAL width —
the `width`-wide calendar area plus the optional 4-cell ISO week column — is
clamped into `[natural grid: 21 without a week column / 25 with one, 63]` and
never rejected. The floor guarantees all seven day columns stay visible (a
narrower request would amputate the Friday/Saturday columns while leaving their
days selectable), so the rendered width is never below the natural grid.

## Styling

Seven style setters accept bare SGR digit strings (e.g. `'7'`, `'1;31'`,
`'4;35'`) up to `SGR_MAX_LEN` (32 characters); escape sequences, dangling
delimiters and the extended-color introducers `38`/`48` are rejected with an
`InvalidArgumentException`. Note the introducer `58` (underline-color) is NOT
rejected — it decodes as unrelated attributes, so avoid it. Only part of the
SGR space decodes: attributes `1,2,3,4,5,6,7,9` and colors `30-37`/`40-47`/
`90-97`; other well-formed codes (`0`, `21`-`29`, `39`, `49`, `53`) are
silently dropped, so `WithTodayStyle('39')` suppresses the today marker rather
than resetting its style:

```php
$picker = $picker
    ->WithHeaderStyle('1;36')
    ->WithDayNameStyle('1')
    ->WithTodayStyle('1;33')
    ->WithSelectedStyle('1;32')
    ->WithSelectedTodayStyle('1;33;44')
    ->WithCursorStyle('7')
    ->WithRangeStyle('4;35');
```

## Keyboard handling

`handleKey()` maps the eight `KEY_*` constants — `left`, `right`, `up`, `down`,
`home`, `end`, `enter`, `esc` (core names this 'escape') — onto cursor
movement, selection and clearing.
Unrecognized keys are ignored (the receiver is returned unchanged). The `Model`
wrapper routes only Enter and Escape through these `KEY_*` strings — its arrow
arm calls the `MoveCursor*` methods directly, and `PageUp`/`PageDown` (which
have no `KEY_*` constant) call `GoToPreviousMonth()`/`GoToNextMonth()` — so a
TEA application never needs to call `handleKey()` directly.

Honest limitations: there is no month-wrap on arrow keys (they clamp at the grid
edge and can rest on empty lead-in/trailing cells, where single-mode Enter falls
back to the first of the month but range-mode Enter does nothing), and no
typeahead year entry. Chord policy: through the `Model`, chorded Enter, Escape,
PageUp and PageDown (Ctrl or Alt held) are ignored so a host's reserved chords
are not hijacked, but the bare arrow keys and Home/End still act under any
chord. `PageUp`/`PageDown` step one
month through the `Model`; larger jumps between months and years go through the
`GoTo*` methods.

## Events

`Model::new()` takes an optional `EventStore` as its second argument. When one
is attached, every state *transition* — a value change, never a flag edge — is
recorded as a typed event the host drains with `release()` after each update:

- **`cursor_moved`** — payload `{index}` — the cursor index changed.
- **`range_started`** — payload `{date}` (`Y-m-d`) — range mode's first Enter
  armed the start (unset → set).
- **`date_selected`** — single mode: payload `{date}` when the selected day
  changed; range mode: `{date, rangeStart, rangeEnd}` when the end becomes
  non-null (the start-arming Enter is reported by `range_started`, not here).
- **`date_cleared`** — empty payload `{}` — the single-mode selection went from
  set to unset (Esc or a toggling Enter).

The recording is deliberately partial in range mode: an Esc that clears a range
and a third Enter that restarts one complete no selection, so they record
**nothing** — `date_cleared` is a single-mode event. A host that must learn
"user cancelled the range" cannot get it from the store; it has to poll
`rangeStart()`/`rangeEnd()` itself, which keeps accessor polling authoritative.

## API surface

Beyond `DatePicker` itself, the shipped public classes are:

- **`DateRange`** — an immutable start/end value object: promoted public
  readonly `$start`/`$end` plus `withStart()`/`withEnd()` clones;
  `contains($date)` (compares calendar days, timezone-safe — while the range is
  open-ended a null end admits every day from the start forward, the
  mid-selection caveat), `durationInDays(?$now = null)` (whole *elapsed* days —
  `2026-01-01` to `2026-01-03` returns `2` although the calendar span covers
  three days; optional clock seam: a null end measures against `$now`), and
  `isComplete()`.
- **`EventStore`** / **`EventStoreInterface`** — the host-facing transition
  channel: `record($type, $payload)`, `release()` (drain-and-clear),
  `hasEvents()`, `count()`, and a `__construct(?\Closure $clock = null)`
  deterministic-time seam for tests. It keeps a bounded ring of `MAX_EVENTS`
  (1000) and evicts the oldest records beyond that, so a host that never drains
  cannot grow memory without limit.
- **`Navigation::gridIndexToDate($index, $month, $year)`** — maps a raw grid
  index to a date; it throws when `month` is outside 1–12 or `year` is below 1,
  which the picker's view floor prevents from ever reaching it in normal use.
- **`Model`** — the TEA wrapper (`init()`/`update($msg)`/`view()`/
  `subscriptions()`). Its `update()` command is **always null** — a host learns
  the outcome by polling `picker()->SelectedDate()`/`rangeStart()`/`rangeEnd()`
  after each update, never from a returned command. The `EventStore` reports the
  same transitions for convenience, but not on range mode's cancel and restart
  paths (see Events above), so polling stays authoritative. Because
  the shared `Core\Model::view()` contract is argless, a framework-driven host
  renders the default 21×9 grid; call `picker()->View($width, $showWeekNumbers)`
  directly for a sized render.
- **`DatePicker` accessors** — `minDate()`, `maxDate()`, `isRangeMode()`,
  `CursorIndex()`, `ViewMonth()`, `ViewYear()`, `dateAtCursor()`, and
  `MoveToDate()` (aim the cursor at an existing value).
- **Naming** — the picker keeps upstream bubble-datepicker's mixed casing:
  PascalCase verbs and accessors (`GoTo*()`, `SetTime()`, `Move*()`,
  `SelectDate()`, `ClearDate()`, `ToggleSelection()`, `SelectedDate()`,
  `IsSelecting()`, `CursorIndex()`, `ViewMonth()`, `ViewYear()`, `View()`,
  `With*Style()`) coexist with camelCase clones and probes (`with*()`,
  `without*()`, `handleKey()`, `rangeStart()`, `rangeEnd()`, `isRangeMode()`,
  `minDate()`, `maxDate()`, `dateAtCursor()`). There is no `setTime()`,
  `setRangeMode()`, or lowercase `view()` on `DatePicker` — the shipped
  spellings are `SetTime()`, `withRangeMode()`, and `View()`; `Model::view()`
  stays lowercase because that is the sugar-core contract.

## Internationalization

Day abbreviations and month names resolve through the shared sugar-core i18n
lookup (`Lang::t`): keys `day.0`–`day.6` (Sunday-first) and `month.1`–`month.12`
live in `lang/en.php`, which is the source of truth. Only English ships today;
additional locales are added as `lang/<code>.php` files carrying the same key
set.

## Shared foundations

- **[sugar-cellbuffer](https://github.com/sugarcraft/sugar-cellbuffer)** — month grid
  rendered as a 7-column × N-row `Buffer`; the day cells are laid out by
  `DatePicker`, and `Buffer::toAnsi()` serializes them to canonical ANSI SGR output.
- **[sugar-core](https://github.com/sugarcraft/sugar-core)** — `Util\Width` is the
  canonical display-width oracle (the picker delegates its cell placement
  widths to it); `I18n\Lang` powers the translations above.
- **[sugar-testing](https://github.com/sugarcraft/sugar-testing)** — golden-file
  snapshot tests via `assertGoldenAnsi()` pin canonical month layouts.

## Examples

```sh
php examples/basic.php         # single-mode walk: navigate, move, select
php examples/constraints.php   # min/max bounds: refused and accepted Enter legs
```

Run them from the lib root; from a standalone clone of the split repo, run
`composer install` first so the sibling `sugarcraft/*` packages resolve.

## License

MIT

## Credits & inspiration

Design antecedent: [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker); SugarCraft is developed as a native PHP project.
