# SugarCalendar: Date Picker Library Research

**Date:** 2026-05-13
**Component:** sugar-calendar (PHP 8.3+ TUI date picker)
**Upstream:** EthanEFung/bubble-datepicker (Go)
**Sources Consulted:** bubble-datepicker (Go), ratatui (Rust), textual-timepiece (Python), tkcalendar (Python), tview (Go)

---

## Executive Summary

The current sugar-calendar implementation is a direct port of the Go upstream with basic date selection. Research across Go, Rust, and Python ecosystems reveals significant opportunities for enhancement: **date range selection**, **focus-based navigation**, **event/store architecture**, **localization framework**, and **compound widget composition**.

**Priority Recommendations:**
1. 🔴 **Date Range Selection** — Most impactful missing feature (medium effort)
2. 🟡 **Focus-based Navigation** — Matches upstream Go pattern, low effort, high UX value
3. 🟡 **Event Store Architecture** — Enables calendar events and custom day styling (medium effort)
4. 🟢 **Localization Framework** — i18n-ready structure (low effort)
5. 🟢 **Compound Widgets** — DatePicker + DateSelect composition (medium effort)

---

## 1. Current Implementation Analysis

**Source:** `/home/sites/sugarcraft/sugar-calendar/src/DatePicker.php`

### Strengths
- Immutable + fluent pattern (`with*()` methods return clones)
- Pure ANSI renderer (no TUI framework dependency)
- 6×7 grid cursor navigation with clamp logic
- Clean separation of state (`viewMonth`, `viewYear`, `cursorIndex`, `selectedDate`)

### Gaps vs. Upstream & Peers
| Feature | sugar-calendar | bubble-datepicker (Go) | ratatui (Rust) | textual-timepiece (Python) |
|---------|---------------|------------------------|----------------|---------------------------|
| Date range selection | ❌ | ❌ | ❌ | ✅ `DateRangePicker` |
| Focus-based nav (header/month/year) | ❌ | ✅ | N/A display only | ✅ compound widgets |
| Event store / day styling | ❌ | ❌ | ✅ `CalendarEventStore` | ✅ |
| Localization (i18n) | ❌ (hardcoded) | ❌ (hardcoded) | N/A | ✅ via `whenever` |
| Time picker | ❌ | ❌ | ❌ | ✅ `TimePicker` |
| DateEntry (input + dropdown) | ❌ | ❌ | ❌ | ✅ `DateSelect` |
| Keyboard nav (PageUp/PageDown) | ❌ | Limited | N/A | ✅ |

---

## 2. Library Analysis

### 2.1 Go: EthanEFung/bubble-datepicker (Upstream)

**Source:** https://github.com/EthanEFung/bubble-datepicker (42 ⭐)

#### Architecture
```go
// Focus enum for header/year/calendar navigation
type Focus int
const (
    FocusNone Focus = iota
    FocusHeaderMonth
    FocusHeaderYear
    FocusCalendar
)

// Model satisfies tea.Model interface
type Model struct {
    Time    time.Time    // selected date
    KeyMap  KeyMap       // key bindings
    Styles  Styles       // lipgloss styles
    Focused Focus        // current focus target
    Selected bool        // selection state
}
```

**Key Patterns:**

1. **Focus-based navigation** — Keyboard input changes behavior based on focused region:
   - `tab` / `shift+tab` cycle through FocusHeaderMonth → FocusHeaderYear → FocusCalendar
   - Arrow keys operate differently per focus (month navigation vs. day navigation)

2. **KeyMap abstraction** — Rebindable keys:
```go
type KeyMap struct {
    Up        key.Binding
    Right     key.Binding
    Down      key.Binding
    Left      key.Binding
    FocusPrev key.Binding  // shift+tab
    FocusNext key.Binding  // tab
    Quit      key.Binding
}
```

3. **Style composition** via lipgloss:
```go
type Styles struct {
    Header       lipgloss.Style
    Date         lipgloss.Style
    HeaderText   lipgloss.Style
    Text         lipgloss.Style
    SelectedText lipgloss.Style
    FocusedText  lipgloss.Style
}
```

4. **Week iteration** via forward/backward Sunday search (not modulo arithmetic):
```go
lastSundayOfLastMonth := firstDayOfTheMonth.AddDate(0, 0, -1)
for lastSundayOfLastMonth.Weekday() != time.Sunday {
    lastSundayOfLastMonth = lastSundayOfLastMonth.AddDate(0, 0, -1)
}
```

#### Missing in Upstream
- No date range selection
- No localization (hardcoded English day/month names)
- No event marking / day styling
- No time picker integration

---

### 2.2 Rust: Ratatui Calendar Widget

**Source:** https://ratatui.rs/examples/widgets/calendar/ (19K ⭐)

#### Architecture
```rust
// DateStyler trait for custom day styling
pub trait DateStyler {
    fn style(&self, date: Date) -> Option<Style>;
}

// CalendarEventStore: simple HashMap-based DateStyler
pub struct CalendarEventStore {
    events: HashMap<Date, Style>,
}

// Monthly calendar widget
pub struct Monthly<'a, DS> {
    display_date: Date,
    events: DS,
    show_surrounding: bool,
    show_month_header: bool,
    show_weekdays_header: bool,
    default_style: Style,
    block: Option<Block<'a>>,
}
```

**Key Patterns:**

1. **Trait-based styling** — `DateStyler` trait allows any type to provide per-date styles:
```rust
pub trait DateStyler {
    fn style(&self, date: Date) -> Option<Style>;
}
```

2. **Builder pattern** for configuration:
```rust
let monthly = Monthly::new(date, event_store)
    .show_surrounding(Modifier::DIM)      // style days from other months
    .show_month_header(Modifier::BOLD)    // show "January 2023"
    .show_weekdays_header(Modifier::ITALIC) // show Su Mo Tu...
    .default_style(Style::default().bold().bg(Color::Rgb(50, 50, 50)));
```

3. **Event store for day marking**:
```rust
let mut event_store = CalendarEventStore::today(Style::default().red().bold());
event_store.add(date, Style::default().blue().italic());
```

#### Missing
- No date range selection
- No interactive selection (display-only widget)
- No keyboard navigation (just rendering)

---

### 2.3 Python: textual-timepiece (DateRangePicker)

**Source:** https://github.com/ddkasa/textual-timepiece (22 ⭐)

#### Widget Matrix

| Widget | Purpose |
|--------|---------|
| `DatePicker` | Visual picker with input + overlay |
| `DateRangePicker` | Interval selection between two dates |
| `DateTimePicker` | Date + time combined |
| `TimePicker` | Time selection in 24h clock |
| `DurationPicker` | Duration up to 99 hours |
| `DateSelect` | Entry with dropdown calendar |
| `DateInput` / `TimeInput` | Direct text input |

#### DateRangePicker Pattern
```python
# From changelog: "Allow picking the end date first"
class DateRangePicker:
    """Date range picker for picking an interval between two dates."""
```

Key behaviors:
- Start date and end date can be selected in any order
- Visual indication of selected range
- `Changed` message emitted on selection change

#### Architecture (compound widgets)
```
DatePicker
├── Input (text field showing formatted date)
├── Overlay (calendar view when activated)
└── Pickers.DatePicker (calendar component)

DateRangePicker
├── DateSelect (start date)
├── DateSelect (end date)
└── Visual range indicator
```

---

### 2.4 Python: tkcalendar

**Source:** https://github.com/j4321/tkcalendar (106 ⭐)

#### Features
```python
Calendar(
    master=None,
    year=2026, month=5, day=13,
    selectmode='day',           # 'none' or 'day'
    locale='en_US',
    firstweekday='monday',      # or 'sunday'
    weekenddays=[6, 7],         # Sat, Sun
    mindate=None,               # datetime.date
    maxdate=None,               # datetime.date
    showothermonthdays=True,
    showweeknumbers=True,
    date_pattern='y-mm-dd',     # customizable format
)
```

**Key Patterns:**

1. **min/max date constraints** — Prevent selection outside valid range:
```python
mindate = datetime.date(2020, 1, 1)
maxdate = datetime.date(2030, 12, 31)
cal = Calendar(top, mindate=mindate, maxdate=maxdate)
```

2. **Event system for day markers**:
```python
cal.calevent_create(date, 'Hello World', 'message')
cal.tag_config('reminder', background='red', foreground='yellow')
```

3. **Locale-aware formatting** via babel:
```python
date_pattern = 'm/d/yy'  # → '5/13/26'
date_pattern = 'y-mm-dd' # → '2026-05-13'
```

4. **DateEntry (dropdown compound widget)**:
```python
# Similar to HTML <input type="date">
date_entry = DateEntry(top, width=12, background='darkblue',
                       foreground='white', borderwidth=2)
date_entry.pack()
# Shows entry with dropdown calendar on click
```

---

### 2.5 Go: rivo/tview (Reference TUI Framework)

**Source:** https://github.com/rivo/tview (14K ⭐)

Not a date picker, but reference for compound widgets and focus management:
- `Focus` concept for regional keyboard handling
- `SetInputCapture` for intercepting key events
- `Form` composition patterns for date + time fields

---

## 3. Feature Comparison & Recommendations

### 3.1 Date Range Selection 🔴 HIGH PRIORITY

**Current state:** Sugar-calendar only supports single date selection.

**References:**
- textual-timepiece `DateRangePicker` — complete implementation
- tui.date-picker `createRangePicker` — TOAST UI range API

**Recommended approach for PHP:**

```php
final class DateRangePicker
{
    private ?\DateTimeImmutable $startDate = null;
    private ?\DateTimeImmutable $endDate = null;
    private bool $selectingStart = true; // or 'start' | 'end'

    public function SelectStart(): self { /* ... */ }
    public function SelectEnd(): self { /* ... */ }
    public function SelectRange(): self { /* start + end */ }
    public function ClearRange(): self { /* ... */ }

    public function startDate(): ?\DateTimeImmutable { return $this->startDate; }
    public function endDate(): ?\DateTimeImmutable { return $this->endDate; }
    public function isSelectingStart(): bool { return $this->selectingStart; }
}
```

**Effort:** Medium (3-5 hours)
**Impact:** High — enables hotel bookings, report ranges, scheduling

---

### 3.2 Focus-based Navigation 🟡 MEDIUM PRIORITY

**Current state:** Cursor moves through calendar grid only. No header/year focus.

**Reference:** bubble-datepicker `Focus` enum + `KeyMap` pattern

**Recommended approach:**

```php
enum Focus
{
    case None;
    case HeaderMonth;
    case HeaderYear;
    case Calendar;
}

final class DatePicker
{
    private Focus $focus = Focus::Calendar;

    // KeyMap for rebindable keys
    private array $keyMap = [
        'up'        => 'MoveCursorUp',
        'down'      => 'MoveCursorDown',
        'left'      => 'MoveCursorLeft',
        'right'     => 'MoveCursorRight',
        'tab'       => 'FocusNext',
        'shift+tab' => 'FocusPrev',
        'enter'     => 'SelectDate',
        'escape'    => 'ClearDate',
    ];

    public function FocusNext(): self { /* cycle HeaderMonth→HeaderYear→Calendar */ }
    public function FocusPrev(): self { /* cycle backward */ }
    // In Update(): switch on $this->focus to determine arrow key behavior
}
```

**Effort:** Low (2-3 hours)
**Impact:** Medium — enables year/month dropdowns, better keyboard UX

---

### 3.3 Event Store / Day Styling 🟡 MEDIUM PRIORITY

**Current state:** Only today/selected date have distinct styles.

**Reference:** ratatui `CalendarEventStore` + tkcalendar `calevent_create`

**Recommended approach:**

```php
interface DateStyler
{
    public function style(\DateTimeImmutable $date): ?string; // ANSI codes or null
}

final class CalendarEventStore implements DateStyler
{
    /** @var array<string, string> date => ANSI codes */
    private array $events = [];

    public function add(\DateTimeImmutable $date, string $ansiStyle): void
    {
        $key = $date->format('Y-m-d');
        $this->events[$key] = $ansiStyle;
    }

    public function style(\DateTimeImmutable $date): ?string
    {
        return $this->events[$date->format('Y-m-d')] ?? null;
    }
}

// In DatePicker:
private ?DateStyler $dateStyler = null;

public function WithDateStyler(DateStyler $stylor): self
{
    $clone = clone $this;
    $clone->dateStyler = $stylor;
    return $clone;
}
```

**Effort:** Medium (2-4 hours)
**Impact:** Medium — enables "busy days", holidays, deadlines

---

### 3.4 Localization Framework 🟢 LOW PRIORITY

**Current state:** Hardcoded `DAY_NAMES` and `MONTH_NAMES`.

**Reference:** tkcalendar locale + babel patterns

**Recommended approach:**

```php
final class DatePicker
{
    /** @var array<int, string> */
    private array $dayNames = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    /** @var array<int, string> */
    private array $monthNames = [
        1 => 'January', 2 => 'February', /* ... */
    ];

    public function WithDayNames(array $names): self
    {
        // Validate 7 elements
    }

    public function WithMonthNames(array $names): self
    {
        // Validate 12 elements
    }

    public function WithLocale(string $locale): self
    {
        // Use IntlDateFormatter or loaded translations
        // Follow LOCALES.md pattern from sugar-bits lang/
    }
}
```

**Effort:** Low (1-2 hours)
**Impact:** Medium — enables i18n (fr_FR, de_DE, ja_JP, etc.)

---

### 3.5 Compound Widgets 🟢 LOW-MEDIUM PRIORITY

**Current state:** Single `DatePicker` class renders calendar only.

**Reference:** textual `DateSelect` = Input + Overlay + DatePicker

**Recommended split:**

```
DatePicker     — pure calendar renderer (current)
DateSelect     — Input + DatePicker dropdown
DateRangeSelect — dual DateSelect (start/end)
TimePicker     — hour/minute spinner (future)
DateTimePicker — DateSelect + TimePicker (future)
```

**Effort:** Medium (4-6 hours)
**Impact:** High — matches user expectations from web/HTML5 datepicker

---

## 4. Keyboard Navigation Patterns

### 4.1 Go bubble-datepicker Keys
| Key | Calendar Focus | HeaderMonth Focus | HeaderYear Focus |
|-----|---------------|-------------------|------------------|
| ↑ / k | -7 days (LastWeek) | -1 month | -1 year |
| ↓ / j | +7 days (NextWeek) | +1 month | +1 year |
| ← / h | -1 day (Yesterday) | → FocusHeaderYear | → FocusCalendar |
| → / l | +1 day (Tomorrow) | (no-op) | (no-op) |
| tab | → FocusHeaderYear | → FocusHeaderYear | → FocusCalendar |
| shift+tab | ← FocusCalendar | ← FocusHeaderMonth | ← FocusHeaderMonth |

### 4.2 Textual DatePicker Keys (from PR #3667)
| Key | Behavior |
|-----|----------|
| ← / → | Wrap around to previous/next date (column edge) |
| ↑ / ↓ | Update calendar to prev/next week (row edge) |
| PageUp / PageDown | Previous/next month |
| Ctrl+PageUp / Ctrl+PageDown | Previous/next year |

### 4.3 Recommended PHP Sugar-Calendar KeyMap

```php
private const DEFAULT_KEY_MAP = [
    // Navigation
    'left'       => 'MoveCursorLeft',
    'right'      => 'MoveCursorRight',
    'up'         => 'MoveCursorUp',
    'down'       => 'MoveCursorDown',
    // Week navigation (Go bubble-datepicker pattern)
    // 'PGUP'      => 'GoToPreviousMonth',
    // 'PGDN'      => 'GoToNextMonth',
    // 'Ctrl+PGUP' => 'GoToPreviousYear',
    // 'Ctrl+PGDN' => 'GoToNextYear',
    // Focus cycling
    'tab'        => 'FocusNext',
    'shift+tab'  => 'FocusPrev',
    // Selection
    'enter'      => 'SelectDate',
    'space'      => 'ToggleSelection',
    'escape'     => 'ClearDate',
    // Quick nav
    't'          => 'GoToToday',
    'T'          => 'GoToToday',
];
```

---

## 5. Implementation Roadmap

### Phase 1: Quick Wins (1-2 sessions)
1. **Extract Style constants to configurables** — `WithHeaderStyle()`, `WithDayNameStyle()` already exist
2. **Add `WithDayNames()` / `WithMonthNames()`** — localization groundwork
3. **Add PageUp/PageDown for month nav** — matches web datepicker behavior
4. **Add `GoToPreviousYear()` / `GoToNextYear()`** — already exists but test coverage

### Phase 2: Focus & Keyboard (1-2 sessions)
5. **Add `Focus` enum** — HeaderMonth, HeaderYear, Calendar, None
6. **Add `KeyMap` abstraction** — rebindable keys
7. **Implement `FocusNext()` / `FocusPrev()`** — tab cycling
8. **Behavior-split arrow keys by focus** — ↑/↓ change month when on header

### Phase 3: Range Selection (2-3 sessions)
9. **Create `DateRangePicker` class** — startDate, endDate, selectingStart
10. **Add range rendering** — visual span indication between start/end
11. **Add `SelectRange()` compound method**

### Phase 4: Event Store (1-2 sessions)
12. **Define `DateStyler` interface**
13. **Implement `CalendarEventStore`** — HashMap-based styling
14. **Wire into `buildCells()`** — apply custom styles per day

### Phase 5: Compound Widgets (2-3 sessions)
15. **Create `DateSelect` class** — Input + DatePicker overlay
16. **Add `DateTimePicker`** if time support needed
17. **Add tests for all new widgets**

---

## 6. Code Citation Index

| Source | Location | Key Pattern |
|--------|----------|-------------|
| bubble-datepicker | `/bubble-datepicker/bubble-datepicker.go:L1-50` | Focus enum, KeyMap struct |
| bubble-datepicker | `/bubble-datepicker/bubble-datepicker.go:L51-80` | Styles struct with lipgloss |
| bubble-datepicker | `/bubble-datepicker/bubble-datepicker.go:L140-200` | Update() with focus-switch |
| bubble-datepicker | `/bubble-datepicker/bubble-datepicker.go:L200-280` | View() with week iteration |
| ratatui | `/ratatui-widgets/src/calendar.rs` | DateStyler trait, CalendarEventStore |
| ratatui | `/ratatui-widgets/examples/calendar.rs` | Monthly::new().builder pattern |
| textual-timepiece | `/textual_timepiece/pickers.py` | DateRangePicker, DateSelect |
| textual-timepiece | `/docs/CHANGELOG.md` | DateRangePicker evolution |
| tkcalendar | `/tkcalendar/calendar.py` | Calendar widget with mindate/maxdate |
| tkcalendar | `/tkcalendar/dateentry.py` | DateEntry dropdown compound |
| sugar-calendar | `/sugar-calendar/src/DatePicker.php:L1-50` | Current state structure |
| sugar-calendar | `/sugar-calendar/src/DatePicker.php:L130-200` | Cursor movement methods |
| sugar-calendar | `/sugar-calendar/src/DatePicker.php:L260-290` | View() rendering |

---

## 7. Risk Assessment

| Feature | Risk | Mitigation |
|---------|------|------------|
| Focus enum | Breaking API change | Add new methods, keep old ones |
| DateRangePicker | Complexity creep | Keep it in separate file, compose with DatePicker |
| EventStore | Performance (HashMap lookup per cell) | Cache computed styles, profile |
| Localization | Incomplete i18n chain | Follow existing `lang/` pattern from sugar-bits |
| Compound widgets | Multiple classes | Use consistent factory method pattern |

---

## 8. Conclusion

Sugar-calendar has a solid foundation (immutable + fluent, pure renderer) but lags behind Go/Rust/Python peers in:

1. **Date range selection** — highest-impact missing feature
2. **Focus-based keyboard navigation** — matches upstream Go, improves UX
3. **Event store architecture** — enables business-day marking, holidays
4. **Localization framework** — trivial to add, unlocks i18n

The most pragmatic next step is to add **focus-based navigation** (Phase 2) as it aligns with the upstream Go library and requires minimal refactoring, followed by **date range selection** (Phase 3) which provides the biggest UX leap.

---

*Research compiled from: bubble-datepicker (Go), ratatui (Rust), textual-timepiece (Python), tkcalendar (Python), rivo/tview (Go)*
