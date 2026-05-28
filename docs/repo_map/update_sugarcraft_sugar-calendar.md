# Overview

SugarCalendar is a 🟢 v1-ready PHP port of EthanEFung/bubble-datepicker (Go) — an interactive month-grid date picker with keyboard navigation, date range selection, locale day names, event store architecture, and pure ANSI rendering. The package occupies the date-picker niche in SugarCraft's component library as a building block for higher-level widgets (DateSelect with input field, DateTimePicker with time component).

**Biggest opportunity areas:**
1. Mouse interaction support (no external TUI framework dependency)
2. Min/max date constraints (frequently requested in upstream ecosystems)
3. Focus zones (three-tier HeaderMonth/HeaderYear/Calendar like upstream Go)
4. Event store integration with per-day rendering
5. Time picker and DateTime picker variants

**Biggest missing capabilities:**
1. Mouse interaction (keyboard-only)
2. Min/max date constraints
3. Focus zone navigation system (HeaderMonth → HeaderYear → Calendar)
4. Compound widgets (input field + dropdown calendar)
5. No CALIBER_LEARNINGS.md documented

---

# Internal Capability Summary

## Current Architecture

```
sugar-calendar/
├── src/
│   ├── DatePicker.php        — 572 lines: main component
│   ├── DateRange.php        — 60 lines: immutable range value object
│   ├── Navigation.php       — 39 lines: grid movement helper
│   ├── EventStore.php        — 39 lines: in-memory event store
│   ├── EventStoreInterface.php — 20 lines: DI-friendly interface
│   └── Lang.php              — 22 lines: i18n facade
├── lang/en.php              — English translations
└── tests/                   — 57 tests across 5 files
```

**Dependency:** `sugarcraft/candy-core` for `Ansi` helpers and `Lang` i18n facade.

## Core State Properties

```php
private int $viewMonth;              // 1-12, currently viewed month
private int $viewYear;                // currently viewed year
private ?\DateTimeImmutable $selectedDate;
private int $cursorIndex;            // 0-41, grid position (6 weeks × 7 days)
private bool $selecting;              // whether selection mode is active
private ?\DateTimeImmutable $rangeStart;
private ?\DateTimeImmutable $rangeEnd;
private bool $rangeMode;
```

## Current Features

- ✅ Month/year navigation (explicit API: `GoToPreviousMonth()`, etc.)
- ✅ Keyboard navigation (arrow keys left/right/up/down, home/end, enter/esc)
- ✅ Date selection with visual cursor (reverse video style)
- ✅ Today highlight (bold green)
- ✅ Selected date styling (bold cyan)
- ✅ Date range selection (start → end with bold magenta)
- ✅ Immutable + fluent pattern (every method returns clone)
- ✅ Pure ANSI renderer (no TUI framework dependency)
- ✅ Localization (Lang facade + 16 keys for day/month names)
- ✅ Event store architecture (DI-friendly, not wired to rendering)
- ✅ Navigation helper class (decoupled grid movement)
- ✅ Vim-style key constants defined but NOT wired in `handleKey()`

## API Surface

**Navigation:** `GoToPreviousMonth()`, `GoToNextMonth()`, `GoToPreviousYear()`, `GoToNextYear()`, `GoToToday()`, `SetTime()`
**Cursor:** `MoveCursorLeft()`, `MoveCursorRight()`, `MoveCursorUp()`, `MoveCursorDown()`
**Selection:** `SelectDate()`, `ClearDate()`, `ToggleSelection()`
**Range:** `withRangeMode()`, `rangeStart()`, `rangeEnd()`, `isRangeMode()`
**Styling:** `WithHeaderStyle()`, `WithTodayStyle()`, `WithSelectedStyle()`, `WithCursorStyle()`, `WithRangeStyle()`
**Queries:** `SelectedDate()`, `IsSelecting()`, `CursorIndex()`, `ViewMonth()`, `ViewYear()`, `dateAtCursor()`
**Rendering:** `View()` — returns ANSI-rendered calendar string

## Calendar Grid Algorithm

42-cell grid (6 weeks × 7 days). First day offset calculated via `firstDayOffset()`:
- Iterates all 42 cells
- Day number relative to month: `$dayNum = $i - $firstDow + 1`
- Empty cells for days outside current month
- Cursor clamping via `clampCursor()` after month/year navigation

## Strengths

1. **Immutable + fluent pattern** — every state method returns a clone, following SugarCraft conventions
2. **Pure ANSI rendering** — framework-independent, composable with any TUI
3. **Date range selection** — significant UX enhancement over upstream Go
4. **Localization framework** — Lang facade with completeness testing (LangCoverageTest)
5. **Event store architecture** — DI-friendly extensibility for future state mutation tracking
6. **Pure renderer** — outputs ANSI strings, no external TUI framework
7. **Decoupled Navigation helper** — enables reuse and independent testability
8. **57 tests across 5 files** — comprehensive coverage

## Weaknesses

1. **Missing CALIBER_LEARNINGS.md** — audit finding, not yet created
2. **EventStore uses `time()` instead of `microtime(true)`** — second vs sub-second precision
3. **`DateTimeImmutable::createFromFormat()` false handling** — some callers don't handle `false` case
4. **Vim keys h/j/k/l/g/G not wired** — `handleKey()` only handles arrow keys + home/end
5. **Min/max date constraints not implemented** — research doc outlines design but not built
6. **Focus zones not implemented** — flat cursor model only
7. **Event store not wired to rendering** — `buildCells()` doesn't consult EventStore for per-day styles
8. **No mouse interaction** — keyboard only
9. **No time picker** — date-only
10. **No compound widget** — input field + dropdown calendar

---

# Relevant External Repositories

| Repo | Relevance | Major applicable concepts | Priority |
|---|---|---|---|
| `EthanEFung/bubble-datepicker` | Direct upstream port source | Three-tier focus zones, vim-style navigation, lipgloss styling | Critical |
| `ratatui/ratatui` | Rust reference for Calendar widget | DateStyler trait, builder pattern, buffer diffing | High |
| `textualize/textual` | Python TUI reference for DateRangePicker | DateSelect, DateTime picker, duration picker, CSS layout | High |
| `lrstanley/bubblezone` | Mouse zone tracking | Zero-width ANSI markers, AABB hit detection, state-machine scanning | High |
| `charmbracelet/bubbletea` | Framework reference | Elm architecture, mouse support, declarative View, Cmd pattern | Medium |

---

# Feature Gap Analysis

## Critical

### 1. Min/Max Date Constraints
**Title:** Date Constraint System
**Description:** No way to limit selectable dates to a range (e.g., booking system only allows dates 30 days out)
**Why it matters:** Fundamental date picker capability for real-world applications (reservations, scheduling, date ranges for reports)
**Source repo:** `ratatui/ratatui.md` — DateStyler trait; `textualize_textual.md` — DateSelect has constraint support
**Implementation ideas:**
```php
public function WithMinDate(\DateTimeImmutable $min): self
public function WithMaxDate(\DateTimeImmutable $max): self
```
Enforcement in `dateAtCursor()` and `SelectDate()` returning null or clamping.
**Estimated complexity:** Low — state properties + validation in 2-3 methods
**Expected impact:** High — enables real-world use cases

### 2. Event Store Integration with Rendering
**Title:** Wire EventStore to Per-Day Styling
**Description:** EventStore exists but `buildCells()` doesn't use it to style days with events
**Why it matters:** Would enable calendar views with event markers (like Google Calendar dots)
**Source repo:** `ratatui/ratatui.md` — DateStyler trait for day-by-day custom styling; `textualize_textual.md` — event markers on dates
**Implementation ideas:**
- Define `DayStylerInterface` or `DateStyler` trait
- Consult EventStore in `buildCells()` for event presence
- Apply distinct style for days with events (e.g., underlined or colored)
**Estimated complexity:** Medium — requires designing styling interface + integration
**Expected impact:** Medium — enables calendar + event integration

## High Value

### 3. Mouse Interaction
**Title:** Click-to-Select Date Picker
**Description:** No mouse support — keyboard navigation only
**Why it matters:** Modern UX expects clickable calendars; upstream Go has no mouse either, but textualize Python and ratatui Rust support it
**Source repo:** `lrstanley_bubblezone.md` — zone-based mouse tracking with zero-width ANSI markers; `charmbracelet_bubbletea.md` — mouse event types (Click, Release, Wheel, Motion)
**Implementation ideas:**
- Use bubblezone's zero-width ANSI marker pattern for zone tracking
- Map mouse clicks to cursor position via coordinate calculation
- Track zone bounds via state-machine scanner over rendered output
- `candy-zone` equivalent for mouse zone hit detection
**Estimated complexity:** High — requires zone tracking architecture separate from DatePicker
**Expected impact:** High — modern UX expectation

### 4. Focus Zone Navigation
**Title:** Three-Tier Focus System
**Description:** Upstream Go has HeaderMonth → HeaderYear → Calendar focus zones navigated via Tab; sugar-calendar has flat cursor model
**Why it matters:** Upstream design enables vim-like navigation where arrows change behavior based on focus (month scroll vs year scroll vs day selection)
**Source repo:** `EthanEFung_bubble-datepicker.md` — Focus enum with FocusNone/FocusHeaderMonth/FocusHeaderYear/FocusCalendar
**Implementation ideas:**
```php
public function SetFocus(Focus $focus): self
public function Blur(): self
// Arrow behavior changes based on focus zone
```
**Estimated complexity:** Medium — requires focus state + key routing changes
**Expected impact:** Medium — power user enhancement

### 5. Vim Key Bindings
**Title:** Complete Vim Navigation
**Description:** `handleKey()` only routes arrow keys + home/end; vim keys h/j/k/l/g/G defined as constants but not wired
**Why it matters:** vim keybindings are standard for power users; upstream Go implements them fully
**Source repo:** `EthanEFung_bubble-datepicker.md` — vim-style hjkl + arrows both supported
**Implementation ideas:**
- Wire h/j/k/l/g/G in `handleKey()` matching Navigation constants
- Ensure vim keys route to same cursor movement logic as arrows
**Estimated complexity:** Low — add 6 key cases to handleKey()
**Expected impact:** Medium — power user UX

## Medium Priority

### 6. Time Picker Variant
**Title:** DateTimePicker Component
**Description:** Date-only picker; no time selection (hour/minute)
**Why it matters:** Upstream textualize Python has TimePicker, DurationPicker
**Source repo:** `textualize_textual.md` — TimePicker, DateTimePicker, DurationPicker widgets
**Implementation ideas:**
- Create `DateTimePicker` that composes DatePicker + time input
- Or separate `TimePicker` component with hour/minute grid
**Estimated complexity:** High — new component required
**Expected impact:** Medium — common use case

### 7. Compound Widget (Input + Dropdown)
**Title:** DateSelect Input Component
**Description:** Input field that reveals calendar dropdown on focus
**Why it matters:** Upstream textualize has DateSelect; web-like date input pattern
**Source repo:** `textualize_textual.md` — DateSelect combines Input + dropdown calendar
**Implementation ideas:**
- Compose DatePicker with text input in sugar-prompt style
- Reveal calendar on input focus
- Validate typed dates against constraints
**Estimated complexity:** High — composition of multiple components
**Expected impact:** Medium — common web pattern

### 8. DateRange Improvements
**Title:** Visual Range Highlighting
**Description:** Current range styling only highlights start/end; doesn't show filled range between
**Why it matters:** Better visual feedback for selected range
**Source repo:** `textualize_textual.md` — DateRangePicker shows filled range
**Implementation ideas:**
- Style every cell between rangeStart and rangeEnd with rangeStyle
- Current implementation only styles the two endpoint cells
**Estimated complexity:** Low — modify buildCells() range logic
**Expected impact:** Medium — visual UX improvement

## Low Priority

### 9. Custom Day Formatter
**Title:** Per-Day Content Customization
**Description:** No way to customize day cell content (e.g., show event count, holiday name)
**Why it matters:** Calendar apps need rich day content beyond just the number
**Source repo:** `ratatui_ratatui.md` — Calendar widget with DateStyler trait
**Implementation ideas:**
- `WithDayFormatter(callable)` that transforms day number → string
- Enables event dots, holiday labels, etc.
**Estimated complexity:** Low — add formatter callback + apply in buildCells()
**Expected impact:** Low — specialized use case

### 10. Week Number Column
**Title:** ISO Week Numbers
**Description:** No option to show ISO week numbers alongside days
**Why it matters:** International calendars use week numbers (ISO 8601)
**Source repo:** `ratatui_ratatui.md` — Calendar::Monthly has `show_month()`, `show_weekday()`
**Implementation ideas:**
- Add `WithWeekNumbers(bool)` style option
- Render ISO week number in first column
**Estimated complexity:** Low — 1 style flag + column rendering
**Expected impact:** Low — internationalization niche

---

# Algorithm / Performance Opportunities

## Current Approach vs External

### Grid Rendering Algorithm

**Current (sugar-calendar):**
```php
for ($i = 0; $i < 42; $i++) {
    $dayNum = $i - $firstDow + 1;
    if ($dayNum < 1 || $dayNum > $daysInMonth) {
        $cells[] = '  ';  // empty
        continue;
    }
    // style calculation inline
}
```

**External approach (ratatui):**
- Uses buffer-based diffing (only changed cells re-rendered)
- Widget trait pattern for custom rendering logic
- DateStyler trait enables per-day style customization without modifying core

**Why external is better:**
- Separation of rendering logic from calendar state
- Enables reusable calendar widgets with custom day styling
- Better performance for large calendars (only update changed regions)

**Tradeoffs:**
- ratatui's approach requires trait system + widget framework
- sugar-calendar's inline approach is simpler for pure PHP

**Applicability:** Medium — could add DayStylerInterface for extensibility without full framework adoption

### Event Store Precision

**Current:** Uses `time()` (second precision)
**Better:** Should use `microtime(true)` for sub-second accuracy in event sourcing

Source: `sugarcraft_sugar-calendar.md` — Known gap #2

---

# Architecture Improvements

## 1. Introduce DayStyler / DateStyler Trait

Following ratatui's DateStyler trait pattern (`docs/repo_map/ratatui_ratatui.md`):

```php
interface DayStylerInterface
{
    public function styleForDay(\DateTimeImmutable $date): string;  // returns ANSI codes
}
```

Enables:
- Custom day styling without modifying DatePicker core
- Event markers, holiday highlights, availability states
- DI-friendly composition

## 2. Focus Zone State Machine

Implement upstream's three-tier focus system (`EthanEFung_bubble-datepicker.md`):

```php
enum Focus {
    case None;
    case HeaderMonth;
    case HeaderYear;
    case Calendar;
}
```

Arrow key behavior changes based on focus zone:
- HeaderMonth: up/down → prev/next month
- HeaderYear: up/down → prev/next year  
- Calendar: up/down → week navigation

## 3. Wire EventStore to buildCells()

EventStore should influence per-day rendering:
- Days with events get distinct style
- Enables calendar-with-events use case

## 4. Better Error Handling

`DateTimeImmutable::createFromFormat()` returns `false` on failure — current code doesn't handle all cases:

```php
$firstOfMonth = \DateTimeImmutable::createFromFormat('Y-m-d', ...);
if ($firstOfMonth === false) {
    // handle gracefully — default to 1st of month
    $firstOfMonth = new \DateTimeImmutable("{$this->viewYear}-{$this->viewMonth}-01");
}
```

---

# API / Developer Experience Improvements

## 1. Complete Vim Key Bindings

`handleKey()` currently only routes arrow keys. Wire vim keys:

```php
// Currently only:
'left', 'right', 'up', 'down', 'home', 'end', 'enter', 'esc'

// Should also handle:
'h', 'j', 
'k', 'l',  
'g', 'G'
```

## 2. Builder Pattern (fluent)

Current `With*` setters are good. Follow ratatui's builder pattern more completely:

```php
DatePicker::new()
    ->withMinDate($min)
    ->withMaxDate($max)
    ->withDayFormatter($formatter)
    ->withRangeMode(true)
    ->withStyles($styles);
```

## 3. Consistent false Handling

Add comprehensive `DateTimeImmutable::createFromFormat()` false handling throughout.

## 4. Type-Safe Key Constants

```php
// Current: strings
public const KEY_LEFT = 'left';

// Better: enum or class
enum Key: string {
    case Left = 'left';
    case Right = 'right';
    case Up = 'up';
    case Down = 'down';
    case Enter = 'enter';
    case Escape = 'esc';
    case Home = 'home';
    case End = 'end';
}
```

---

# Documentation / Cookbook Opportunities

## 1. Recipe: Date Range Picker

```php
$picker = DatePicker::new()->withRangeMode(true);
// Use handleKey() for range selection
```

## 2. Recipe: Constrained Date Selection

```php
$min = new \DateTimeImmutable('+1 week');
$max = new \DateTimeImmutable('+6 months');
$picker = DatePicker::new()
    ->withMinDate($min)
    ->withMaxDate($max);
```

## 3. Recipe: Calendar with Event Markers

```php
$store = new EventStore();
$store->record('event', ['date' => '2026-06-15', 'title' => 'Deadline']);

$picker = DatePicker::new()->withEventStore($store);
```

## 4. Recipe: Custom Day Formatting

```php
$picker = DatePicker::new()->withDayFormatter(function(\DateTimeImmutable $date): string {
    if ($date->format('j') === '1') {
        return $date->format('M j');  // Show month for 1st
    }
    return $date->format('j');
});
```

---

# UX / TUI Improvements

## 1. Visual Range Fill

Currently only range endpoints are styled. Fill the entire range:

```php
// Current: only start/end styled
// Better: all days between get rangeStyle
```

## 2. Today Indicator Improvement

Consider adding a visual indicator beyond just color (e.g., `[*]` or underline).

## 3. Keyboard Shortcut Help

Show available keys when calendar is focused:

```php
// Optional footer showing:
// [←→↑↓] Navigate  [Enter] Select  [Esc] Clear  [Tab] Focus zones
```

## 4. Better Empty State

When no date selected, show placeholder or prompt in the header area.

---

# Testing / Reliability Improvements

## 1. Fix EventStore time() Precision

Source: `sugarcraft_sugar-calendar.md` Known Gap #2

```php
// Current:
'time' => time()

// Should be:
'time' => microtime(true)
```

## 2. Create CALIBER_LEARNINGS.md

Missing file was noted in `sugarcraft_sugar-calendar.md` Known Gap #1.

## 3. Add Snapshot Tests for View() Output

Current tests verify state mutations. Add golden file tests for ANSI output:

```php
public function testViewRendersCorrectly(): void
{
    $picker = DatePicker::new(new \DateTimeImmutable('2026-05-01'));
    $output = $picker->SelectDate()->View();
    
    $this->assertEquals(
        file_get_contents(__DIR__ . '/snapshots/may-2026-selected.txt'),
        $output
    );
}
```

## 4. Add Mouse Interaction Tests (when implemented)

Zone tracking needs integration tests.

## 5. Property-Based Tests for Cursor Clamping

Use PHPStan or custom property testing for edge cases:
- February leap year
- Months with 28/29/30/31 days
- Year boundary transitions

---

# Ecosystem / Integration Opportunities

## 1. Integration with sugar-prompt (Form Library)

DatePicker as a form field:

```php
use SugarCraft\Prompt\Form;
use SugarCraft\Calendar\DatePicker;

$form = new Form();
$form->addField('start_date', new DatePicker());
$form->addField('end_date', new DatePicker());
```

## 2. Integration with sugar-table

Calendar view in table:

```php
// Render events as calendar grid with table layout
```

## 3. Integration with candy-shell (Mouse Input)

Zone-based mouse tracking via candy-zone:

```php
$picker = DatePicker::new()
    ->withMouseTracking(true);
```

## 4. Integration with sugar-charts

Calendar heatmap for data visualization:

```php
// Show activity intensity per day
```

---

# Notable PRs / Issues / Discussions

## From bubbletea (Framework Reference)

**PR #1500: Declarative View API (v2)** (`docs/repo_map/pr_charmbracelet_bubbletea.md`)

Bubble Tea v2 shifted from imperative commands to declarative View properties. Key lessons:
- Declarative > imperative for terminal state
- View struct fields as single source of truth
- Eliminates race conditions between commands and rendering

**Lesson for sugar-calendar:** Consider declarative styling configuration rather than imperative `With*` methods.

**Issue #831: Textarea Performance** (`docs/repo_map/pr_charmbracelet_bubbletea.md`)

Performance dominated by `wrap()` calls. Fix: memoization cache + faster package (uniseg vs go-runewidth).

**Lesson for sugar-calendar:** Memoize expensive operations in DatePicker (width calculations, day number conversions).

**Issue #1690: Data Race onMouse** (`docs/repo_map/pr_charmbracelet_bubbletea.md`)

`lastView` read without mutex in `onMouse()` causing race condition.

**Lesson for sugar-calendar:** Any shared state between render and input loops needs mutex protection.

## From ratatui (Rust Calendar)

**DateStyler Trait** (`docs/repo_map/ratatui_ratatui.md`)

ratatui's Calendar widget uses a `DateStyler` trait enabling custom per-day styling without modifying the calendar core.

**Lesson for sugar-calendar:** Design DayStylerInterface for extensibility.

---

# Recommended Roadmap

## Immediate Wins (This Sprint)

1. **Create CALIBER_LEARNINGS.md** — document patterns and gotchas
2. **Fix EventStore time precision** — `microtime(true)` instead of `time()`
3. **Wire vim keys in handleKey()** — h/j/k/l/g/G + verify false handling
4. **Improve visual range highlighting** — fill range between start/end

## Medium-Term Improvements (Next Phase)

5. **Min/Max Date Constraints** — WithMinDate/WithMaxDate API + enforcement
6. **DayStylerInterface** — enable custom per-day styling
7. **Focus Zone State Machine** — HeaderMonth/HeaderYear/Calendar focus with Tab navigation
8. **Builder Pattern Completion** — consistent fluent API

## Major Architectural Upgrades (Future)

9. **Mouse Interaction** — zone-based hit detection via candy-zone integration
10. **DateTimePicker Variant** — compose DatePicker + TimeInput
11. **DateSelect Compound Widget** — input field + dropdown calendar
12. **Snapshot Testing Infrastructure** — golden files for View() output

## Experimental Ideas

13. **ISO Week Numbers Column** — optional week number display
14. **Web-Based DevTools** — inspector for calendar state (mirrors bubbletea proposal)
15. **Event Store → Rendering Wire** — connect EventStore to DayStyler for event markers

---

# Priority Matrix

| Opportunity | Impact | Complexity | Risk | Recommended Priority |
|---|---|---|---|---|
| CALIBER_LEARNINGS.md | Low (doc) | Low | None | Immediate |
| EventStore microtime fix | Medium (reliability) | Low | Low | Immediate |
| Vim keys wiring | Medium (UX) | Low | Low | Immediate |
| Visual range fill | Medium (UX) | Low | Low | Immediate |
| Min/Max constraints | High (feature) | Low | Low | Medium-term |
| DayStylerInterface | High (extensibility) | Medium | Low | Medium-term |
| Focus zones | High (feature) | Medium | Medium | Medium-term |
| Builder pattern | Medium (DX) | Low | None | Medium-term |
| Mouse interaction | High (UX) | High | High | Future |
| DateTimePicker | Medium (feature) | High | Medium | Future |
| DateSelect compound | Medium (feature) | High | Medium | Future |
| Snapshot tests | Medium (reliability) | Medium | Low | Medium-term |
| Week numbers | Low (feature) | Low | Low | Future |

---

# Final Strategic Assessment

SugarCalendar is a well-architected PHP port that meaningfully enhances its upstream Go source through immutable patterns, date range selection, and localization support. The pure ANSI renderer design is strategically sound — it decouples the calendar from any specific TUI framework, making it composable with both candy-core and standalone use.

**Key differentiators from upstream:**
1. Immutable + fluent architecture (every method returns clones)
2. Date range selection (not in upstream Go)
3. Localization framework with completeness testing
4. Event store architecture for extensibility
5. Pure ANSI renderer (no TUI framework dependency)

**Strategic positioning:**
- The package is 🟢 v1 ready for core date selection and navigation
- Its pure renderer approach makes it an ideal building block for higher-level widgets
- DateRange value object and EventStore architecture are strong foundations for future extension

**Critical gaps to address:**
1. **Min/max constraints** — enables real-world scheduling/booking use cases
2. **Mouse interaction** — modern UX expectation, requires zone tracking architecture
3. **Event store → rendering wire** — unlocks calendar-with-events use case
4. **CALIBER_LEARNINGS.md** — missing project documentation

**Architectural recommendation:**
Adopt ratatui's DateStyler trait pattern to decouple day styling from core DatePicker, enabling custom event markers and holiday highlights without modifying the calendar core. This is the highest-leverage architectural improvement.

**Mouse interaction** should be pursued via the bubblezone zero-width ANSI marker pattern, implemented as a separate `candy-zone` integration rather than modifying DatePicker directly. This keeps concerns separated and enables mouse tracking reuse across all sugar-bits components.

The focus zone system from upstream Go is valuable for power users but adds complexity; implement after constraints and mouse support are stable.

**Testing infrastructure** should include snapshot tests for View() output using golden files, following the `CHARM_TEST_UPDATE=1` pattern from bubbletea's experimental teatest package.

**Long-term vision:**
SugarCalendar should evolve toward a compound DateSelect widget (input + dropdown), eventually supporting DateTimePicker composition. The pure renderer approach positions it well for embedding in both candy-shell (terminal) and potential web-based contexts. Consider the textual-web model for browser-based TUI rendering as a future differentiator.
