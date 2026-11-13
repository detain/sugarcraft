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
  renders dimmed (faint) whatever their today/selected state — the disabled
  cue composes onto state colours rather than being masked by them
- **Model–Update–View (TEA) integration** — a `Model` wrapper implementing the sugar-core
  `Model` contract (`init()`/`update(Msg)`/`view()`), recording picker events
  into an optional `EventStore`
- **Today highlight** — show the reference "today" distinctly; `withoutToday()`
  drops the pinned reference again
- **Selected date and range styling** — seven SGR style setters, validated
- **Pure renderer** — outputs ANSI strings, no external TUI framework needed

## Related widgets

`SugarCraft\Dashboard\Components\Select\DatePicker` (sugar-dashboard) is a
**separate** widget with its own API: a labelled form-input field for dashboard
grids — it wraps a text entry with an optional calendar popup and speaks the
dashboard `SizedItem` protocol. This package is the standalone TEA calendar:
no label chrome, no popup overlay, no dashboard dependency. Neither consumes
the other; pick by context (form field → dashboard, embedded calendar → this
one).

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

echo $picker->view();  // render the month grid
$selected = $picker->selectedDate();  // 2026-05-01
```

Or drive it as a TEA model:

```php
use SugarCraft\Core\Msg\KeyMsg;
use SugarCraft\Core\KeyType;
use SugarCraft\DatePicker\DatePicker;
use SugarCraft\DatePicker\Model;

$model = Model::new(new \DateTimeImmutable('2026-05-15'));
[$model, $cmd] = $model->update(new KeyMsg(KeyType::Right));
[$model, $cmd] = $model->update(new KeyMsg(KeyType::Enter));  // selects the date
echo $model->view();

// A fully configured picker enters the model directly (seed time and picker
// are mutually exclusive — passing both throws):
$picker = DatePicker::new(new \DateTimeImmutable('2026-05-15'))
    ->withRangeMode(true)
    ->withWidth(30);
$model = Model::new(picker: $picker);

// Mid-flight reconfiguration keeps the attached EventStore instance:
$model = $model->withPicker(fn (DatePicker $p) => $p->withRangeMode(false));
```

## Navigation

```php
// Month navigation
$picker = $picker->goToPreviousMonth();
$picker = $picker->goToNextMonth();
$picker = $picker->goToPreviousYear();
$picker = $picker->goToNextYear();
$picker = $picker->goToToday();

// Set arbitrary month
$picker = $picker->setTime(new \DateTimeImmutable('2025-12-01'));
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
five-digit `10000`), leaving the date unresolvable — `view()` still renders
(reporting year 10000), but `dateAtCursor()` is `null` and `selectDate()`
refuses. This tolerated upper edge never crashes.

## Selection

Single mode (default) — `Enter` (via `handleKey` or the `Model`) selects the
date under the cursor; `Esc` clears the selection:

```php
$picker = $picker->handleKey(DatePicker::KEY_ENTER);  // SelectDate() under the hood
$picker = $picker->handleKey(DatePicker::KEY_ESCAPE); // ClearDate() under the hood
$picker = $picker->clearDate();                       // programmatic equivalent
$picker = $picker->toggleSelection();                 // select-or-clear in one call
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
unchanged); an existing selection is never retroactively cleared — it simply
remains dimmed like any other out-of-bounds day. Each setter
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
hide the marker; set `withTodayStyle('')` to suppress it). `goToToday()` navigates
to the pinned date, or to the real current day when nothing is pinned — unless
that day lies outside a `min`/`max` window, in which case the view stays
clamped inside the window (see the Min/max bounds section above).

## Rendering

```php
echo $picker->view();                 // default 21 columns, no week numbers
echo $picker->view(30, true);         // 34 columns: 30-wide grid + 4-cell week column
```

`view()` takes a column `width` and a `showWeekNumbers` flag. The TOTAL width —
the `width`-wide calendar area plus the optional 4-cell ISO week column — is
clamped into `[natural grid: 21 without a week column / 25 with one, 63]` and
never rejected. The floor guarantees all seven day columns stay visible (a
narrower request would amputate the Friday/Saturday columns while leaving their
days selectable), so the rendered width is never below the natural grid.
Those two arguments are optional: a null argument falls back to the picker's
configured render state (`withWidth()` / `withShowWeekNumbers()`), so the
argless contract render honours the host's intent without every caller
re-passing it.

## Styling

Seven style setters accept bare SGR digit strings (e.g. `'7'`, `'1;31'`,
`'4;35'`) up to `SGR_MAX_LEN` (32 characters). The accept set and the decoder
are one enumerated truth (`SUPPORTED_SGR`): attributes `1`-`7` and `9`,
foregrounds `30`-`37`/`90`-`97`, backgrounds `40`-`47`/`100`-`107`. Anything
else — escape sequences, dangling delimiters, the extended-color introducers
`38`/`48` and `58`, the reset/off codes (`0`, `21`-`29`, `39`, `49`, `53`),
`8` (hidden) and out-of-table numbers — is refused loudly with the offending
code named, because a setter that silently dropped the code (or mis-read
`58;5;9` as blink+strike) would be a style lie. Blink `5`/`6` is accepted but
discouraged — prefer `1`/`4`/`7`; hosts with a reduced-motion lint should
avoid it:

```php
$picker = $picker
    ->withHeaderStyle('1;36')
    ->withDayNameStyle('1')
    ->withTodayStyle('1;33')
    ->withSelectedStyle('1;32')
    ->withSelectedTodayStyle('1;33;44')
    ->withCursorStyle('7')
    ->withRangeStyle('4;35');
```

There is no dedicated disabled-day style: every out-of-bounds day always
receives the built-in faint composition, layered onto whichever style the
chain above chose, and none of the seven setters influence it.

## Keyboard handling

`handleKey()` maps the `KEY_*` constants — `left`, `right`, `up`, `down`,
`home`, `end`, `enter`, and BOTH escape spellings `esc` (BC wire value) and
`escape` (the name sugar-core uses) — onto cursor
movement, selection and clearing.
Unrecognized keys are surfaced through `error_log('datepicker: unknown key …')`
and leave the receiver unchanged (loud at integration time, state-safe at
runtime — no throw). The `Model`
wrapper routes Enter, Escape, Home and End through these `KEY_*` strings — its
arrow arm calls the `MoveCursor*` methods directly, and `PageUp`/`PageDown`
(which have no `KEY_*` constant) call `goToPreviousMonth()`/`goToNextMonth()` —
so a TEA application never needs to call `handleKey()` directly.

Honest limitations: there is no month-wrap on arrow keys (they clamp at the grid
edge and can rest on empty lead-in/trailing cells, where single-mode Enter falls
back to the first of the month but range-mode Enter does nothing), and no
typeahead year entry. Chord policy: through the `Model`, the four action keys
Enter, Escape, PageUp and PageDown are host-reserved under ANY chord — Ctrl,
Alt or Shift held — so a chat host's Shift+Enter newline is never hijacked.
The arrows and Home/End are the deliberate terminal-compatibility exception:
they act bare and chorded alike. `PageUp`/`PageDown` step one
month through the `Model`; larger jumps between months and years go through the
`GoTo*` methods.

## Events

`Model::new()` takes an optional `EventStore` as its second argument. When one
is attached, every state *transition* — a value change, never a flag edge — is
recorded as a typed event the host drains with `release()` after each update.
Event kinds are `PickerEvent` constants; compare against the constants, never
hand-copied strings (a typo in a raw literal fails silently, a typo in a class
constant does not):

- **`PickerEvent::CURSOR_MOVED`** (`'cursor_moved'`) — payload `{index}` — the
  cursor index changed.
- **`PickerEvent::RANGE_STARTED`** (`'range_started'`) — payload `{date}`
  (`Y-m-d`) — range mode's first Enter armed the start (unset → set).
- **`PickerEvent::DATE_SELECTED`** (`'date_selected'`) — single mode: payload
  `{date}` when the selected day changed; range mode: `{date, rangeStart,
  rangeEnd}` when the end becomes non-null (the start-arming Enter is reported
  by `RANGE_STARTED`, not here).
- **`PickerEvent::DATE_CLEARED`** (`'date_cleared'`) — empty payload `{}` — the
  single-mode selection went from set to unset (Esc or a toggling Enter).

Recording channel (settled ruling): the TEA `Model` is the *only* emitter.
`DatePicker::handleKey()` and the imperative methods are the non-TEA surface
and record nothing — a host driving the picker directly either wraps it in a
`Model` or diffs picker states itself; that asymmetry is the contract, not an
oversight.

The recording is deliberately partial in range mode: an Esc that clears a range
and a third Enter that restarts one complete no selection, so they record
**nothing** — `date_cleared` is a single-mode event. A host that must learn
"user cancelled the range" cannot get it from the store; it has to poll
`rangeStart()`/`rangeEnd()` itself, which keeps accessor polling authoritative.

## API surface

Beyond `DatePicker` itself, the shipped public classes are:

- **`PickerEvent`** — final const-only class: the four recorded event kinds
  (`CURSOR_MOVED`, `RANGE_STARTED`, `DATE_SELECTED`, `DATE_CLEARED`) as the
  published wire vocabulary; see Events below.
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
  the outcome by polling `picker()->selectedDate()`/`rangeStart()`/`rangeEnd()`
  after each update, never from a returned command. The `EventStore` reports the
  same transitions for convenience, but not on range mode's cancel and restart
  paths (see Events above), so polling stays authoritative. The shared
  `Core\Model::view()` contract is argless, and it honours the picker's
  render state: configure width and the week-number column with
  `withWidth()`/`withShowWeekNumbers()` and every contract-driven render
  uses them; an explicit `picker()->view($width, $showWeekNumbers)` still
  wins per call (a null argument falls back to state either way).
- **`DatePicker` accessors** — `minDate()`, `maxDate()`, `isRangeMode()`,
  `cursorIndex()`, `viewMonth()`, `viewYear()`, `dateAtCursor()`, and
  `moveToDate()` (aim the cursor at an existing value).
- **Naming** — camelCase is the house API (AGENTS.md). The picker's verbs
  and accessors are declared in camelCase (`goTo*()`, `setTime()`,
  `move*()`, `selectDate()`, `clearDate()`, `toggleSelection()`,
  `selectedDate()`, `isSelecting()`, `cursorIndex()`, `viewMonth()`,
  `viewYear()`, `view()`, `with*Style()`) alongside the `with*()`/`without*()`
  builders, `handleKey()`, `rangeStart()`, `rangeEnd()`, `isRangeMode()`,
  `minDate()`, `maxDate()` and `dateAtCursor()`. They were originally
  declared PascalCase mirroring upstream bubble-datepicker; the rename keeps
  every PascalCase call site working unchanged because PHP method resolution
  is case-insensitive — the old spellings (`GoToPreviousMonth()`, `View()`,
  `With*Style()`, …) are deprecated in guidance, not in dispatch. There is
  still no `setRangeMode()`: the shipped spelling is `withRangeMode()`;
  `Model::view()` stays lowercase because that is the sugar-core contract.

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
php examples/interactive.php   # real Program loop: scripted KeyMsgs through update(), EventStore transcript
```

The third example, `examples/interactive.php`, mounts `Model` inside a
sugar-core `Program` loop and feeds it a fixed script of `KeyMsg`s — the
runnable reference for how a live keystroke travels through `update()`. It
ends by printing the drained `EventStore` transcript (`cursor_moved`,
`range_started`, `date_selected`, `date_cleared`) plus the final grid, so the
event wiring is verifiable without a TTY.

Run them from the lib root; from a standalone clone of the split repo, run
`composer install` first so the sibling `sugarcraft/*` packages resolve.

## License

MIT

## Credits & inspiration

Design antecedent: [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker); SugarCraft is developed as a native PHP project.
