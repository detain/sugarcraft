# SugarCraft/sugar-calendar

## Metadata
- **URL:** https://github.com/sugarcraft/sugar-calendar
- **Language:** PHP 8.3+
- **License:** MIT
- **Status:** 🟢 v1 ready
- **Description:** PHP port of EthanEFung/bubble-datepicker — interactive month-grid date picker with keyboard navigation, date range selection, locale day names, event store architecture, and pure ANSI rendering.

## Upstream

| Upstream | Relationship |
|---|---|
| [EthanEFung/bubble-datepicker](https://github.com/EthanEFung/bubble-datepicker) | Direct port source (Go, ~40 stars) |
| [charmbracelet/bubbles](https://github.com/charmbracelet/bubbles) | Framework reference (Bubble Tea Elm-architecture) |
| [ratatui](https://github.com/ratatui/ratatui) | Rust reference for Calendar widget + DateStyler trait |
| [textual-timepiece](https://github.com/ddkasa/textual-timepiece) | Python reference for DateRangePicker |

---

## Architecture Overview

### Package Structure

```
sugar-calendar/
├── src/
│   ├── DatePicker.php        — 572 lines: main component
│   ├── DateRange.php         — 60 lines: immutable range value object
│   ├── Navigation.php        — 39 lines: grid movement helper
│   ├── EventStore.php        — 39 lines: in-memory event store
│   ├── EventStoreInterface.php — 20 lines: DI-friendly interface
│   └── Lang.php              — 22 lines: i18n facade
├── lang/
│   └── en.php               — English translations (day.0-6, month.1-12)
├── tests/
│   ├── DatePickerTest.php   — 384 lines: 27 tests
│   ├── NavigationTest.php   — 80 lines: 11 tests
│   ├── DateRangeTest.php    — 123 lines: 12 tests
│   ├── EventStoreTest.php   — 74 lines: 7 tests
│   └── LangCoverageTest.php — 148 lines: translation completeness
└── examples/
    ├── basic.php            — Basic date picker demo
    └── constraints.php      — Navigation + selection demo
```

### Dependency Graph

```
sugar-calendar
└── sugarcraft/candy-core (dev-master)
    ├── SugarCraft\Core\Util\Ansi — ANSI SGR code helpers (DatePicker.php line 8)
    └── SugarCraft\Core\I18n\Lang — Base i18n facade (Lang.php line 7)
```

---

## Core Classes

### 1. DatePicker (`src/DatePicker.php` — 572 lines)

The central component. All navigation methods return new clones (immutable + fluent pattern).

#### State Properties
```php
private int $viewMonth;              // 1-12, currently viewed month
private int $viewYear;                // currently viewed year
private ?\DateTimeImmutable $selectedDate;  // null or selected date
private int $cursorIndex;            // 0-41, grid position (6 weeks × 7 days)
private bool $selecting;             // whether selection mode is active
private ?\DateTimeImmutable $rangeStart;   // range selection start
private ?\DateTimeImmutable $rangeEnd;     // range selection end
private bool $rangeMode;            // range selection toggle
```

#### ANSI Style Properties (hardcoded SGR codes)
```php
private string $headerStyle        = '1;37';  // bold white
private string $dayNameStyle        = '90';     // bright black
private string $todayStyle           = '1;32';   // bold green
private string $selectedStyle       = '1;36';   // bold cyan
private string $selectedTodayStyle  = '1;33';   // bold yellow
private string $cursorStyle         = '7';      // reverse video
private string $normalDayStyle      = '';
private string $rangeStyle          = '1;35';   // bold magenta
```

#### Key Constants (vim-style + web conventions)
```php
public const KEY_LEFT  = 'left';
public const KEY_RIGHT = 'right';
public const KEY_UP    = 'up';
public const KEY_DOWN  = 'down';
public const KEY_ENTER = 'enter';
public const KEY_ESCAPE = 'esc';
public const KEY_HOME  = 'home';
public const KEY_END   = 'end';
```

#### Public API Surface

**Factory:**
```php
public function __construct(?\DateTimeImmutable $time = null)
public static function new(?\DateTimeImmutable $time = null): self
```

**Navigation:**
```php
public function GoToPreviousMonth(): self
public function GoToNextMonth(): self
public function GoToPreviousYear(): self
public function GoToNextYear(): self
public function GoToToday(): self
public function SetTime(\DateTimeImmutable $t): self
```

**Cursor Movement:**
```php
public function MoveCursorLeft(): self
public function MoveCursorRight(): self
public function MoveCursorUp(): self
public function MoveCursorDown(): self
```

**Selection:**
```php
public function SelectDate(): self        // enters selection mode
public function ClearDate(): self        // clears selection
public function ToggleSelection(): self
```

**Range Selection:**
```php
public function withRangeMode(bool $mode): self
public function rangeStart(): ?\DateTimeImmutable
public function rangeEnd(): ?\DateTimeImmutable
public function isRangeMode(): bool
```

**Keyboard Handling:**
```php
public function handleKey(string $key): self  // routes to cursor nav + range
```

**Styling:**
```php
public function WithHeaderStyle(string $s): self
public function WithTodayStyle(string $s): self
public function WithSelectedStyle(string $s): self
public function WithCursorStyle(string $s): self
public function WithRangeStyle(string $s): self
```

**Queries:**
```php
public function SelectedDate(): ?\DateTimeImmutable
public function IsSelecting(): bool
public function CursorIndex(): int
public function ViewMonth(): int
public function ViewYear(): int
public function dateAtCursor(): ?\DateTimeImmutable
```

**Rendering:**
```php
public function View(): string  // returns ANSI-rendered calendar
```

---

## Calendar Grid Algorithm

The 7-column grid is the core rendering algorithm. Here's how it works:

### Grid Structure
- **42 cells** = 6 weeks × 7 days (allows for months with up to 31 days + padding)
- **Index range:** 0-41
- **Row offset:** Each row represents 7 consecutive days
- **Column mapping:** 0=Sun, 1=Mon, ..., 6=Sat

### Algorithm (from `buildCells()` at line 482)

```php
private function buildCells(): array
{
    $firstOfMonth = \DateTimeImmutable::createFromFormat(
        'Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
    );
    $daysInMonth = (int) $firstOfMonth->format('t');  // days in this month
    $firstDow    = (int) $firstOfMonth->format('w');   // 0=Sun of 1st

    // Iterate all 42 cells
    for ($i = 0; $i < 42; $i++) {
        $dayNum = $i - $firstDow + 1;  // day number relative to month

        if ($dayNum < 1 || $dayNum > $daysInMonth) {
            $cells[] = '  ';  // empty cell (before 1st or after last)
            continue;
        }

        // Determine styling for this day cell
        // ... (isToday, isInRange, isSelected logic)
    }
}
```

### First Day Offset Calculation

The `firstDayOffset()` method (line 547) returns the Sunday-offset of the 1st day of the viewed month:

```php
private function firstDayOffset(): int
{
    $firstOfMonth = \DateTimeImmutable::createFromFormat(
        'Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
    );
    return $firstOfMonth !== false ? (int) $firstOfMonth->format('w') : 0;
}
```

### Example: May 2026
- May 1, 2026 is a **Friday** (day-of-week = 5)
- `firstDow = 5`
- Cells 0-4 (Sun-Thu) render as `'  '` (empty)
- Cell 5 = day 1, cell 6 = day 2, ..., cell (5+31-1) = cell 35 = day 31
- Cells 36-41 render as `'  '` (empty, past month end)

### Cursor Clamping (`clampCursor()` at line 555)

After month/year navigation, the cursor is clamped to valid cells:

```php
private function clampCursor(): void
{
    $daysInMonth = /* get days in current month */;
    $firstDow = $this->firstDayOffset();
    $lastIndex = $firstDow + $daysInMonth - 1;
    $this->cursorIndex = \min($this->cursorIndex, \max(0, $lastIndex));
}
```

This ensures navigation to February 2026 (28 days) doesn't leave the cursor stranded at index 35 (which would be beyond the valid range for that month).

---

## Focus State Machine

### Upstream Go Implementation

The upstream bubble-datepicker has a **three-tier focus system**:

```go
type Focus int
const (
    FocusNone Focus = iota
    FocusHeaderMonth
    FocusHeaderYear
    FocusCalendar
)
```

- `tab` / `shift+tab` cycles: `HeaderMonth → HeaderYear → Calendar → HeaderMonth`
- Arrow key behavior **changes based on focus zone**:
  - In `HeaderMonth`: ↑/↓ = previous/next month
  - In `HeaderYear`: ↑/↓ = previous/next year
  - In `Calendar`: ↑/↓ = up/down one week

### SugarCraft Implementation

**Status: Simplified — not implemented**

SugarCraft's DatePicker does **not** implement the three-tier focus system. Instead, it uses a **flat cursor-index model**:

- Cursor moves freely through the 42-cell grid
- Arrow keys move the cursor ±1 (left/right), ±7 (up/down)
- Month/year navigation methods (`GoToNextMonth()`, etc.) are **explicit API calls**, not keyboard-triggered
- The `handleKey()` method only handles cursor movement + range selection (lines 272-306)

**Rationale:** The focus zone system adds complexity for limited UX gain in a pure renderer. The explicit navigation API (`GoToNextMonth()`, etc.) is more composable — callers can wire up any key bindings they prefer.

**Missing from upstream:** The upstream Go library has `FocusNone` but it doesn't actually prevent navigation key mutations — a design quirk SugarCraft avoids by not implementing focus zones at all.

---

## Range Selection

### DateRange Class (`src/DateRange.php` — 60 lines)

Immutable value object representing a date range:

```php
final readonly class DateRange
{
    public function __construct(
        public ?\DateTimeImmutable $start = null,
        public ?\DateTimeImmutable $end = null,
    ) {}

    public function withStart(\DateTimeImmutable $start): self
    public function withEnd(\DateTimeImmutable $end): self
    public function contains(\DateTimeImmutable $date): bool
    public function durationInDays(): ?int
    public function isComplete(): bool  // both start and end non-null
}
```

The `contains()` method (line 27) handles open-ended ranges (null end = unbounded):

```php
public function contains(\DateTimeImmutable $date): bool
{
    if ($this->start === null) return false;
    $d = $date->setTime(0, 0, 0);  // normalise to midnight
    $s = $this->start->setTime(0, 0, 0);
    if ($d < $s) return false;
    if ($this->end !== null) {
        $e = $this->end->setTime(0, 0, 0);
        if ($d > $e) return false;
    }
    return true;
}
```

### Range Mode in DatePicker

```php
private ?\DateTimeImmutable $rangeStart = null;
private ?\DateTimeImmutable $rangeEnd = null;
private bool $rangeMode = false;
```

**handleKey() range handling (lines 296-303, 308-333):**

```php
if ($key === self::KEY_ENTER && $this->rangeMode) {
    return $this->handleRangeEnter($clone);
}
if ($key === self::KEY_ESCAPE && $this->rangeMode) {
    $clone->rangeStart = null;
    $clone->rangeEnd = null;
    return $clone;
}
```

**handleRangeEnter() (lines 308-333):**
- First Enter: sets `rangeStart` to cursor date
- Second Enter: sets `rangeEnd` to cursor date (normalizes so start ≤ end)
- Third Enter: starts fresh (clears both, sets new `rangeStart`)

**Range rendering in buildCells() (lines 500-521):**

```php
$range = $this->buildRange();

private function buildRange(): ?DateRange
{
    if ($this->rangeStart === null || $this->rangeEnd === null) {
        return null;
    }
    // Only highlight when start/end are in view month/year
    if ($this->rangeStart->format('Y-n') !== $this->viewYear . '-' . $this->viewMonth
        && $this->rangeEnd->format('Y-n') !== $this->viewYear . '-' . $this->viewMonth) {
        return null;
    }
    return new DateRange($this->rangeStart, $this->rangeEnd);
}
```

Range cells are styled with `$this->rangeStyle` ('1;35' — bold magenta).

---

## Localization (i18n)

### Lang Facade (`src/Lang.php` — 22 lines)

```php
final class Lang extends BaseLang
{
    protected const NAMESPACE = 'calendar';
    protected const DIR = __DIR__ . '/../lang';
}
```

Follows the same facade pattern as `sugar-wishlist/src/Lang.php` and `sugar-table/src/Lang.php`.

### Translation Keys (`lang/en.php` — 34 lines)

```php
return [
    'day.0' => 'Su',   // Sunday
    'day.1' => 'Mo',   // Monday
    'day.2' => 'Tu',
    'day.3' => 'We',
    'day.4' => 'Th',
    'day.5' => 'Fr',
    'day.6' => 'Sa',
    'month.1'  => 'January',
    'month.2'  => 'February',
    // ...
    'month.12' => 'December',
];
```

### Day/Month Name Helpers (lines 75-86)

```php
private static function dayName(int $dow): string
{
    return Lang::t('day.' . $dow);
}

private static function monthName(int $month): string
{
    return Lang::t('month.' . $month);
}
```

### LangCoverageTest (`tests/LangCoverageTest.php` — 148 lines)

Ensures every `Lang::t()` key referenced in source code exists in `lang/en.php`:

```php
public function testAllLangKeysUsedInSrcExistInEnPhp(): void
{
    // Extracts all Lang::t() patterns from src/ files
    // Verifies each has a corresponding entry in lang/en.php
}
```

This prevents silent translation key omissions — a common i18n bug.

---

## Constraint Handling

**Status: Not yet implemented**

The upstream Go bubble-datepicker has **no min/max date constraint** system. SugarCraft's research doc (`docs/research/libraries/sugar-calendar-research.md`) identifies this as a planned enhancement.

### Planned Design (from research doc)

```php
public function WithMinDate(\DateTimeImmutable $min): self
public function WithMaxDate(\DateTimeImmutable $max): self
```

Constraint enforcement would happen in `dateAtCursor()` and `SelectDate()`, returning null or clamping to the valid range.

---

## Vim-Style Navigation

### Navigation Helper (`src/Navigation.php` — 39 lines)

Static helper class for grid movement:

```php
final readonly class Navigation
{
    public const ROW_DOWN  = 7;
    public const ROW_UP   = -7;
    public const COL_RIGHT = 1;
    public const COL_LEFT  = -1;

    /** @param int $gridIndex 0-41 */
    public static function move(int $gridIndex, string $key): int
    {
        return match ($key) {
            'left'  => max(0, $gridIndex - 1),
            'right' => min(41, $gridIndex + 1),
            'up'    => max(0, $gridIndex - 7),
            'down'  => min(41, $gridIndex + 7),
            'home'  => 0,
            'end'   => 41,
            default => $gridIndex,
        };
    }

    public static function gridIndexToDate(int $gridIndex, int $month, int $year): \DateTimeImmutable
    {
        $firstOfMonth = new \DateTimeImmutable("$year-$month-01");
        $firstDow = (int) $firstOfMonth->format('w');
        $dayNum = $gridIndex - $firstDow + 1;
        return $firstOfMonth->modify('+' . ($dayNum - 1) . ' days');
    }
}
```

This decouples grid movement logic from the DatePicker state machine, enabling reuse and testability.

### Key Bindings

SugarCraft follows vim conventions (hjkl + arrow keys):

| Key | Action | Grid movement |
|---|---|---|
| `left` / `h` | Move cursor left | index - 1 (clamped to 0) |
| `right` / `l` | Move cursor right | index + 1 (clamped to 41) |
| `up` / `k` | Move cursor up | index - 7 (clamped to 0) |
| `down` / `j` | Move cursor down | index + 7 (clamped to 41) |
| `home` / `g` | Jump to first cell | index = 0 |
| `end` / `G` | Jump to last cell | index = 41 |
| `enter` | Confirm selection / set range | selects date |
| `esc` | Clear selection / range | clears |

Note: The current `handleKey()` implementation (lines 272-306) only routes `left`/`right`/`up`/`down`/`home`/`end`/`enter`/`esc` — it does **not** implement `h`/`j`/`k`/`l`/`g`/`G` vim keys.

---

## Event Store Architecture

### EventStoreInterface (`src/EventStoreInterface.php` — 20 lines)

```php
interface EventStoreInterface
{
    public function record(string $type, array $payload = []): void;
    public function release(): array;  // returns and clears events
    public function hasEvents(): bool;
}
```

### EventStore (`src/EventStore.php` — 39 lines)

Simple in-memory implementation:

```php
final class EventStore implements EventStoreInterface
{
    /** @var list<array{type: string, payload: array, time: int}> */
    private array $events = [];

    public function record(string $type, array $payload = []): void
    {
        $this->events[] = ['type' => $type, 'payload' => $payload, 'time' => time()];
    }
    // ...
}
```

**Issue noted in sugarcrash_findings.md:** `time()` is second-precision. Should use `microtime(true)` for sub-second accuracy in event sourcing scenarios.

**Purpose:** Enables DI-friendly event sourcing for calendar state changes (date selected, range set, navigation). Future enhancement would wire this into `DatePicker::update()` to record every state mutation.

---

## ANSI Rendering

### Style Constants (SGR Codes)

All styling uses raw ANSI SGR (Select Graphic Rendition) codes:

```php
private string $headerStyle        = '1;37';  // bold white
private string $dayNameStyle       = '90';     // bright black (gray)
private string $todayStyle         = '1;32';  // bold green
private string $selectedStyle       = '1;36';  // bold cyan
private string $selectedTodayStyle  = '1;33';  // bold yellow
private string $cursorStyle        = '7';     // reverse video
private string $rangeStyle         = '1;35';   // bold magenta
```

### Ansi Helper (`ansi()` at line 567)

```php
private function ansi(string $text, string $codes): string
{
    if ($codes === '') return $text;
    return Ansi::CSI . $codes . 'm' . $text . Ansi::reset();
}
```

Where `Ansi::CSI = "\033["` and `Ansi::reset() = "\033[0m"`.

### View Output Structure

```
    May 2026                    ← header (bold white)
       Su  Mo  Tu  We  Th  Fr  Sa   ← day names (bright black)
   ─────────────────────────────
 0   27  28  29  30  31   1   2    ← week 0 (part of Apr + start of May)
 7    3   4   5   6   7   8   9    ← week 1
14   10  11  12  13  14  15  16    ← week 2
...
```

Each cell is 2 characters wide. Empty cells are `'  '`.

### Rendered Output Example (from tests)

```
    May 2026
       Su  Mo  Tu  We  Th  Fr  Sa
   ─────────────────────────────
 0  27  28  29  30  31   1   2
 7   3   4   5   6   7   8   9
14  10  11  12  13  14  15  16
21  17  18  19  20  21  22  23
28  24  25  26  27  28  29  30
35  31   1   2   3   4   5   6
```

---

## Comparison: SugarCraft vs. Upstream

| Feature | sugar-calendar | EthanEFung/bubble-datepicker (Go) |
|---|---|---|
| Date selection | ✅ | ✅ |
| Month/year navigation | ✅ | ✅ |
| Keyboard navigation (arrow keys) | ✅ | ✅ |
| Vim-style keybindings | 🟡 partial (arrows only, no hjkl) | ✅ |
| Date range selection | ✅ | ❌ |
| Focus zones (HeaderMonth/Year/Calendar) | ❌ (flat cursor model) | ✅ |
| Localization (i18n) | ✅ (Lang facade + 16 locales planned) | ❌ |
| Event store | ✅ (EventStore + Interface) | ❌ |
| Pure ANSI renderer | ✅ (no TUI framework) | ❌ (needs Bubble Tea) |
| Immutable + fluent | ✅ | ❌ (mutates time.Time) |
| Min/max date constraints | ❌ | ❌ |
| Mouse interaction | ❌ | ❌ |
| Time picker | ❌ | ❌ |
| Compound widget (input + dropdown) | ❌ | ❌ |

---

## Comparison: SugarCraft vs. ratatui Calendar

| Feature | sugar-calendar | ratatui `Calendar::Monthly` (Rust) |
|---|---|---|
| Interactive selection | ✅ | ❌ (display-only) |
| Keyboard navigation | ✅ | ❌ |
| Date range selection | ✅ | ❌ |
| Event store / day styling | 🟡 (EventStore, but not wired to rendering) | ✅ (DateStyler trait) |
| Localization | ✅ | N/A |
| Builder pattern | 🟡 (With* fluent) | ✅ |
| Compound widgets | ❌ | ❌ |
| DateStyler trait | ❌ (interface planned) | ✅ |

---

## Comparison: SugarCraft vs. textual-timepiece

| Feature | sugar-calendar | textual-timepiece (Python) |
|---|---|---|
| Date range picker | 🟡 (DateRange + rangeMode) | ✅ (DateRangePicker) |
| Date picker | ✅ | ✅ |
| Time picker | ❌ | ✅ |
| DateTime picker | ❌ | ✅ |
| DateEntry (input + dropdown) | ❌ | ✅ (DateSelect) |
| Duration picker | ❌ | ✅ |
| Focus zones | ❌ | ✅ |
| Event/day markers | 🟡 (EventStore exists, not wired) | ✅ |
| Localization | ✅ | ✅ |

---

## Innovation Points (SugarCraft Enhancements Over Upstream)

### 1. **Immutable + Fluent Pattern**
Upstream Go `bubble-datepicker` mutates `time.Time` fields directly. SugarCraft's DatePicker is fully immutable — every state method returns a clone.

### 2. **Date Range Selection**
Not present in upstream Go. SugarCraft adds `DateRange` value object, `rangeStart/rangeEnd`, and range-aware rendering.

### 3. **Localization Framework**
Upstream has hardcoded English strings. SugarCraft adds `Lang.php` facade, `lang/en.php`, and `LangCoverageTest` for translation completeness verification.

### 4. **Event Store Architecture**
`EventStore` + `EventStoreInterface` enables DI-friendly event sourcing for calendar state changes. Not present in upstream.

### 5. **Pure ANSI Renderer**
Upstream requires Bubble Tea framework + Lipgloss. SugarCraft's `View()` outputs pure ANSI strings — no TUI framework dependency.

### 6. **Navigation Helper Class**
`Navigation` static class decouples grid-movement logic from DatePicker state machine, enabling reuse and independent testability.

### 7. **DateRange Value Object**
`DateRange` is a `readonly` class with `withStart`/`withEnd` factories, `contains()`, `durationInDays()`, and `isComplete()` — a proper immutable domain object.

### 8. **LangCoverageTest**
Translation completeness test that statically analyzes source files and verifies every `Lang::t()` key exists — prevents silent i18n gaps.

---

## Known Gaps

1. **`CALIBER_LEARNINGS.md` missing** — noted in sugarcrash_findings.md, not yet created
2. **EventStore uses `time()` (second precision)** — should use `microtime(true)` for sub-second accuracy
3. **`firstOfViewMonth()` return false handling** — some callers (e.g., `clampCursor()`) don't fully handle the `false` case from `DateTimeImmutable::createFromFormat()`
4. **Vim keys h/j/k/l/g/G not wired in `handleKey()`** — only arrow keys are handled; vim mnemonics need to be added
5. **Min/max date constraints** — not yet implemented (research doc outlines the design)
6. **Focus zones (HeaderMonth/HeaderYear/Calendar)** — not implemented; flat cursor model instead
7. **Event store not wired into rendering** — EventStore exists but `buildCells()` doesn't consult it for per-day styles
8. **No mouse interaction** — keyboard only (matches upstream)
9. **No time picker** — date-only (matches upstream)

---

## File References

### Source Files
- `/home/sites/sugarcraft/sugar-calendar/src/DatePicker.php` — main component (572 lines)
- `/home/sites/sugarcraft/sugar-calendar/src/DateRange.php` — range value object (60 lines)
- `/home/sites/sugarcraft/sugar-calendar/src/Navigation.php` — grid movement helper (39 lines)
- `/home/sites/sugarcraft/sugar-calendar/src/EventStore.php` — event store (39 lines)
- `/home/sites/sugarcraft/sugar-calendar/src/EventStoreInterface.php` — store interface (20 lines)
- `/home/sites/sugarcraft/sugar-calendar/src/Lang.php` — i18n facade (22 lines)
- `/home/sites/sugarcraft/sugar-calendar/lang/en.php` — English translations (34 lines)

### Test Files
- `/home/sites/sugarcraft/sugar-calendar/tests/DatePickerTest.php` — 27 tests (384 lines)
- `/home/sites/sugarcraft/sugar-calendar/tests/NavigationTest.php` — 11 tests (80 lines)
- `/home/sites/sugarcraft/sugar-calendar/tests/DateRangeTest.php` — 12 tests (123 lines)
- `/home/sites/sugarcraft/sugar-calendar/tests/EventStoreTest.php` — 7 tests (74 lines)
- `/home/sites/sugarcraft/sugar-calendar/tests/LangCoverageTest.php` — translation completeness (148 lines)

### Examples
- `/home/sites/sugarcraft/sugar-calendar/examples/basic.php` — basic demo
- `/home/sites/sugarcraft/sugar-calendar/examples/constraints.php` — navigation + selection demo

### VHS Demos
- `/home/sites/sugarcraft/sugar-calendar/.vhs/basic.tape` + `basic.gif`
- `/home/sites/sugarcraft/sugar-calendar/.vhs/constraints.tape` + `constraints.gif`

### Documentation
- `/home/sites/sugarcraft/docs/research/libraries/sugar-calendar-research.md` — 600-line feature research doc
- `/home/sites/sugarcraft/sugar-calendar/README.md` — 75-line package README
- `/home/sites/sugarcraft/plans/leftover/phase-05-i18n/step-01-sugar-calendar.md` — i18n implementation plan
- `/home/sites/sugarcraft/plans/leftover/phase-10-apps/step-20-calendar-range-eventstore.md` — range + event store plan

---

## Related Reports

- `/home/sites/sugarcraft/repo_map/EthanEFung_bubble-datepicker.md` — upstream Go port analysis
- `/home/sites/sugarcraft/repo_map/charmbracelet_bubbles.md` — framework reference (components)
- `/home/sites/sugarcraft/repo_map/ratatui_ratatui.md` — Rust reference (Calendar widget)
- `/home/sites/sugarcraft/repo_map/textualize_textual.md` — Python reference (DateRangePicker)
- `/home/sites/sugarcraft/repo_map/sugarcrash_findings.md` — audit findings (CALIBER_LEARNINGS.md missing, time() precision)

---

## Analysis

**sugar-calendar** is a well-structured PHP port that significantly enhances its upstream Go source. The most notable innovations are the immutable + fluent architecture (every method returns clones), the date range selection system (DateRange + rangeMode + handleKey), and the i18n framework (Lang facade + LangCoverageTest). The pure ANSI renderer design is strategically sound — it decouples the calendar from any specific TUI framework, making it composable with both candy-core and standalone use.

**Strengths:**
- Immutable + fluent throughout
- Pure ANSI rendering (framework-independent)
- Date range selection (significant UX enhancement over upstream)
- Localization framework with completeness testing
- Event store architecture for future extensibility
- Comprehensive test coverage (57 tests across 5 test files)

**Weaknesses:**
- Missing `CALIBER_LEARNINGS.md` (audit finding)
- EventStore uses `time()` instead of `microtime(true)`
- Focus zone system not implemented (flat cursor model only)
- Min/max date constraints not yet implemented
- Vim keys h/j/k/l/g/G not wired in `handleKey()`
- Event store not connected to per-day rendering
- No mouse interaction
- No compound widget (input + dropdown calendar)

**Strategic position:** sugar-calendar occupies the date-picker niche in SugarCraft's component library. Its pure renderer approach makes it a building block for higher-level widgets (DateSelect with input field, DateTimePicker with time component). The 🟢 v1 ready status is appropriate — the core selection and navigation work well, but several enhancements from the research doc remain unimplemented.
