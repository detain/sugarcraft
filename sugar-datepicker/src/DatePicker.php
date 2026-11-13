<?php

declare(strict_types=1);

namespace SugarCraft\DatePicker;

use SugarCraft\CellBuffer\Buffer;
use SugarCraft\CellBuffer\Cell;
use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\Width;

/**
 * Interactive date picker component.
 *
 * Manages: current view month/year, cursor position (row/col grid),
 * selected date, today marker, optional min/max selection bounds, and
 * navigation.
 *
 * Navigation and selection methods are immutable: a state change returns a
 * new instance and never mutates the receiver; a no-op or refused operation
 * returns the receiver itself.
 *
 * Design antecedent: EthanEFung/bubble-datepicker, implemented as a native
 * PHP component (there is no Go runtime dependency).
 *
 * Methods are declared camelCase per the house naming rule (AGENTS.md).
 * The upstream PascalCase spellings (`GoToPreviousMonth()`, `SetTime()`,
 * `View()`, `With*Style()`, …) remain supported: PHP resolves method names
 * case-insensitively, so every legacy call site keeps working against the
 * renamed declarations — deprecated in guidance, never in dispatch.
 *
 * @see https://github.com/EthanEFung/bubble-datepicker
 */
final class DatePicker
{
    private const DAYS_IN_WEEK = 7;

    /** Day columns each occupy 3 cells (2 for the number + 1 space). */
    private const CELL_CHARS = 3;

    /** Total grid cells: 6 week rows × 7 days. */
    private const GRID_CELLS = 42;

    /** Largest buffer width View() accepts before clamping. */
    private const MAX_TOTAL_WIDTH = 63;

    /** Characters reserved for the optional ISO week-number column. */
    private const WEEK_COL_CHARS = 4;

    /** Longest SGR string any style setter accepts. */
    private const SGR_MAX_LEN = 32;

    /**
     * The SGR accept set — single source of truth for what {@see assertSgr()}
     * admits, enumerated exactly as {@see sgrToBufferStyle()} decodes: no code
     * may live in one without the other (the style test matrix pins every
     * member round-trips and every outsider is refused).
     */
    private const SUPPORTED_SGR = [
        1, 2, 3, 4, 5, 6, 7, 9,                // attributes (8=hidden has no arm)
        30, 31, 32, 33, 34, 35, 36, 37,      // standard foregrounds
        40, 41, 42, 43, 44, 45, 46, 47,      // standard backgrounds
        90, 91, 92, 93, 94, 95, 96, 97,      // bright foregrounds
        100, 101, 102, 103, 104, 105, 106, 107, // bright backgrounds
    ];

    /**
     * Lower month-position bound of the calendar itself: January 0001
     * (position encoding is year*12 + month-1). The library represents no
     * dates below year 1 — the view floors there so grid dates stay valid
     * and the cursor never lands on an unrepresentable month.
     */
    private const VIEW_FLOOR_POS = 12;

    /** Largest cursor grid index (the 6×7 day grid minus one cell). */
    private const DAY_INDEX_MAX = 41;

    // Key constants for handleKey()
    public const KEY_LEFT  = 'left';
    public const KEY_RIGHT = 'right';
    public const KEY_UP    = 'up';
    public const KEY_DOWN  = 'down';
    public const KEY_ENTER = 'enter';
    // The value string is the handleKey() wire contract; sugar-core spells the
    // same key KeyType::Escape = 'escape'. dp-18 accepts BOTH spellings at the
    // widget surface (KEY_ESCAPE stays 'esc' for BC); Model still routes the
    // canonical constant.
    public const KEY_ESCAPE = 'esc';
    public const KEY_ESCAPE_ALT = 'escape';
    public const KEY_HOME  = 'home';
    public const KEY_END   = 'end';

    /** Currently viewed month (1-indexed). */
    private int $viewMonth;

    /** Currently viewed year. */
    private int $viewYear;

    /** Selected date (null if none selected). */
    private ?\DateTimeImmutable $selectedDate = null;

    /** Injected "today" reference (null = real wall-clock now). */
    private ?\DateTimeImmutable $today = null;

    /** Cursor grid index 0-41 (6 weeks × 7 days). */
    private int $cursorIndex = 0;

    /** Whether the date is currently being selected (cursor shown). */
    private bool $selecting = false;

    /** Range selection start date. */
    private ?\DateTimeImmutable $rangeStart = null;

    /** Range selection end date. */
    private ?\DateTimeImmutable $rangeEnd = null;

    /** Whether range selection mode is active. */
    private bool $rangeMode = false;

    /** Earliest selectable/visible date (null = unbounded below). */
    private ?\DateTimeImmutable $minDate = null;

    /** Latest selectable/visible date (null = unbounded above). */
    private ?\DateTimeImmutable $maxDate = null;

    /**
     * Configured render width, as stored by withWidth(). The intent is kept
     * verbatim; the renderer clamps into the visible band at draw time, so a
     * host sizing from a live terminal never needs a pre-validation dance.
     */
    private int $renderWidth = 21;

    /** Configured week-number column intent, as stored by withShowWeekNumbers(). */
    private bool $showWeekNumbers = false;

    /** Styling (SGR ANSI codes). */
    private string $headerStyle        = '1;37';  // bold white
    private string $dayNameStyle       = '90';    // bright black
    private string $todayStyle         = '1;32';  // bold green
    private string $selectedStyle      = '1;36';  // bold cyan
    private string $selectedTodayStyle = '1;33';  // bold yellow
    private string $cursorStyle        = '7';     // reverse
    private string $rangeStyle         = '1;35';  // bold magenta (range highlight)

    // -------------------------------------------------------------------------
    // i18n helpers
    // -------------------------------------------------------------------------

    /**
     * @param int $dow 0=Sun … 6=Sat
     */
    private static function dayName(int $dow): string
    {
        return Lang::t('day.' . $dow);
    }

    /**
     * @param int $month 1=Jan … 12=Dec
     */
    private static function monthName(int $month): string
    {
        return Lang::t('month.' . $month);
    }

    // -------------------------------------------------------------------------
    // Factory
    // -------------------------------------------------------------------------

    /**
     * Public constructor — kept as a house exception pending a v2 convergence
     * on private ctors; the docs advertise `DatePicker::new()` as the entry point.
     */
    public function __construct(?\DateTimeImmutable $time = null)
    {
        $t = $time ?? new \DateTimeImmutable();
        [$this->viewYear, $this->viewMonth] = self::flooredView(
            (int) $t->format('Y'),
            (int) $t->format('n'),
        );
        // Aim the cursor at the initial date when it falls inside the viewed
        // month (edit-existing-value integrations), else at the first valid
        // day — the picker never opens focus-invisible on an empty cell.
        $this->cursorIndex = $this->cursorForDate($t);
    }

    public static function new(?\DateTimeImmutable $time = null): self
    {
        return new self($time);
    }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public function goToPreviousMonth(): self
    {
        return $this->stepView(-1);
    }

    public function goToNextMonth(): self
    {
        return $this->stepView(1);
    }

    public function goToPreviousYear(): self
    {
        return $this->stepView(-12);
    }

    public function goToNextYear(): self
    {
        return $this->stepView(12);
    }

    /**
     * Step the viewed month by $deltaMonths, clamped to the calendar floor
     * (year >= 1) and to the [minDate, maxDate] month window when set.
     */
    private function stepView(int $deltaMonths): self
    {
        $clone = clone $this;
        [$clone->viewYear, $clone->viewMonth] = $this->boundedView(
            $this->viewYear,
            $this->viewMonth,
            $deltaMonths,
        );
        $clone->cursorIndex = $clone->clampedCursor($clone->cursorIndex);

        return $clone;
    }

    public function goToToday(): self
    {
        $today = $this->today();
        $clone = clone $this;
        [$clone->viewYear, $clone->viewMonth] = $this->boundedView(
            (int) $today->format('Y'),
            (int) $today->format('n'),
            0,
        );
        $clone->cursorIndex = $clone->clampedCursor($clone->cursorIndex);

        return $clone;
    }

    /**
     * Clamp a target year/month (optionally shifted by $deltaMonths) into
     * the viewable window: never outside the configured min/max months, and
     * finally never below January 0001.
     *
     * The calendar floor is applied LAST so a `maxDate` earlier than year 1
     * can never drag the view under it into an unrepresentable month (a zero
     * date is exactly what bare MySQL/hosting rows yield). Pure — reads only.
     *
     * @return array{0:int,1:int} [viewYear, viewMonth]
     */
    private function boundedView(int $year, int $month, int $deltaMonths = 0): array
    {
        $pos = $year * 12 + ($month - 1) + $deltaMonths;
        if ($this->minDate !== null) {
            $pos = max($pos, self::monthPos($this->minDate));
        }
        if ($this->maxDate !== null) {
            $pos = min($pos, self::monthPos($this->maxDate));
        }
        $pos = max(self::VIEW_FLOOR_POS, $pos);

        return [intdiv($pos, 12), ($pos % 12) + 1];
    }

    /**
     * Floor a raw year/month pair to the calendar floor (January 0001) and a
     * valid month. Used at construction where no bounds exist yet.
     *
     * @return array{0:int,1:int} [viewYear, viewMonth]
     */
    private static function flooredView(int $year, int $month): array
    {
        $pos = $year * 12 + ($month - 1);
        $pos = max(self::VIEW_FLOOR_POS, $pos);

        return [intdiv($pos, 12), ($pos % 12) + 1];
    }

    /** Month-window position encoding (year*12 + month-1) for a date. */
    private static function monthPos(\DateTimeImmutable $date): int
    {
        return (int) $date->format('Y') * 12 + ((int) $date->format('n') - 1);
    }

    // -------------------------------------------------------------------------
    // Selection bounds (min/max constraints)
    //
    // Bounds clamp navigation to the containing months and refuse selection
    // of dates outside them. Existing selections are NOT retroactively
    // cleared when a bound is tightened — the bounds govern future
    // navigation and selection only. All comparisons are made on the calendar
    // day (Y-m-d), never the absolute instant, so a bound expressed in one
    // zone and a probe in another agree when they show the same wall date.
    // -------------------------------------------------------------------------

    /**
     * Set the earliest selectable date. If the current view is before the
     * bound's month it is pulled back into the window.
     *
     * @throws \InvalidArgumentException if $min falls after the current maxDate.
     */
    public function withMinDate(\DateTimeImmutable $min): self
    {
        if ($this->maxDate !== null && self::dayKey($min) > self::dayKey($this->maxDate)) {
            throw new \InvalidArgumentException('minDate must not be after maxDate');
        }

        return $this->withBounds($min, $this->maxDate);
    }

    /** Remove the lower selection bound. */
    public function withoutMinDate(): self
    {
        return $this->withBounds(null, $this->maxDate);
    }

    /**
     * Set the latest selectable date. If the current view is after the
     * bound's month it is pulled back into the window.
     *
     * @throws \InvalidArgumentException if $max falls before the current minDate.
     */
    public function withMaxDate(\DateTimeImmutable $max): self
    {
        if ($this->minDate !== null && self::dayKey($max) < self::dayKey($this->minDate)) {
            throw new \InvalidArgumentException('maxDate must not be before minDate');
        }

        return $this->withBounds($this->minDate, $max);
    }

    /** Remove the upper selection bound. */
    public function withoutMaxDate(): self
    {
        return $this->withBounds($this->minDate, null);
    }

    public function minDate(): ?\DateTimeImmutable
    {
        return $this->minDate;
    }

    public function maxDate(): ?\DateTimeImmutable
    {
        return $this->maxDate;
    }

    /** Whether $date lies inside the configured bounds (inclusive). */
    public function inBounds(\DateTimeImmutable $date): bool
    {
        $d = self::dayKey($date);
        if ($this->minDate !== null && $d < self::dayKey($this->minDate)) {
            return false;
        }

        return !($this->maxDate !== null && $d > self::dayKey($this->maxDate));
    }

    /**
     * The date an Enter on the CURRENT cursor state would attempt to apply:
     * the cursor date (range mode — and single mode when the cell holds a
     * day), or the first of the viewed month as single mode's lead-in blank
     * fallback. Pure query over current state (dp-09 refusal seam), which is
     * exactly what {@see selectDate()} and {@see handleRangeEnter()} act on.
     */
    public function selectionTarget(): ?\DateTimeImmutable
    {
        return $this->dateAtCursor() ?? ($this->isRangeMode() ? null : $this->firstOfViewMonth());
    }

    /**
     * Why a bare Enter on the CURRENT cursor state would be refused, or null
     * when it would apply (dp-09). Vocabulary mirrors PickerEvent::SELECTION_REFUSED
     * payloads: `out_of_bounds` (dimmed day under cursor), `empty_cell` (blank
     * lead-in/trailing cell whose fallback the bounds also reject), and
     * `year_unresolvable` (defensive — the floored view makes it unreachable).
     */
    public function selectionRefusal(): ?string
    {
        $target = $this->selectionTarget();
        if ($target !== null && $this->inBounds($target)) {
            return null;
        }

        if ($this->dateAtCursor() !== null) {
            return 'out_of_bounds';
        }

        // Blank cell: single mode fell back to a first-of-month the bounds
        // also reject; range mode has no fallback at all. The reason only
        // escalates to year_unresolvable when even the month floor fails.
        return !$this->isRangeMode() && $this->firstOfViewMonth() === null
            ? 'year_unresolvable'
            : 'empty_cell';
    }

    /**
     * Stable calendar-day key (Y as 4 digits + m + d) for timezone-safe
     * comparison: two dates showing the same wall date compare equal
     * regardless of the zone each \DateTimeImmutable carries.
     */
    private static function dayKey(\DateTimeImmutable $date): int
    {
        return (int) $date->format('Ymd');
    }

    /** Shared write path for the bound setters; re-clamps the view. */
    private function withBounds(?\DateTimeImmutable $min, ?\DateTimeImmutable $max): self
    {
        $clone = clone $this;
        $clone->minDate = $min;
        $clone->maxDate = $max;
        [$clone->viewYear, $clone->viewMonth] = $clone->boundedView($clone->viewYear, $clone->viewMonth);
        $clone->cursorIndex = $clone->clampedCursor($clone->cursorIndex);

        return $clone;
    }

    /**
     * Pin the "today" reference (testability). Default (unset) uses the
     * real wall-clock now, so production behavior is unchanged.
     */
    public function withToday(\DateTimeImmutable $today): self
    {
        $clone = clone $this;
        $clone->today = $today;

        return $clone;
    }

    /** Drop the pinned "today" reference (back to real wall-clock now). */
    public function withoutToday(): self
    {
        $clone = clone $this;
        $clone->today = null;

        return $clone;
    }

    /** Resolve the "today" reference: injected value or real now. */
    private function today(): \DateTimeImmutable
    {
        return $this->today ?? new \DateTimeImmutable();
    }

    public function setTime(\DateTimeImmutable $t): self
    {
        $clone = clone $this;
        [$clone->viewYear, $clone->viewMonth] = $clone->boundedView(
            (int) $t->format('Y'),
            (int) $t->format('n'),
        );
        $clone->cursorIndex = $clone->cursorForDate($t);

        return $clone;
    }

    /**
     * Aim the cursor at an arbitrary date (the edit-existing-value recipe):
     * when $date falls inside the currently viewed month the cursor lands on
     * its cell; otherwise the view is unchanged and the cursor keeps its
     * clamped position. Immutable.
     */
    public function moveToDate(\DateTimeImmutable $date): self
    {
        $clone = clone $this;
        $clone->cursorIndex = $clone->cursorForDate($date);

        return $clone;
    }

    // -------------------------------------------------------------------------
    // Cursor movement
    //
    // NOTE: Cursor navigation is GRID-INDEX based (0-41), not date-based like
    // upstream bubbles/calendar (which uses DateTimeImmutable cursor). When at
    // the first selectable day and pressing Left, the cursor steps into the
    // lead-in (empty or dimmed) and stays there (no month-boundary wrap); the
    // same on the trailing edge. Arrow keys therefore CAN rest on empty or
    // dimmed cells — Enter falls back to the first of the month from a lead-in
    // blank (and is refused when that date is out of bounds) — while Home and
    // End always land on a SELECTABLE day (dp-11: min/max consulted).
    // This is an architectural difference from upstream.
    // -------------------------------------------------------------------------

    public function moveCursorLeft(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = \max(0, $clone->cursorIndex - 1);

        return $clone;
    }

    public function moveCursorRight(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = \min(self::DAY_INDEX_MAX, $clone->cursorIndex + 1);

        return $clone;
    }

    public function moveCursorUp(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = \max(0, $clone->cursorIndex - self::DAYS_IN_WEEK);

        return $clone;
    }

    public function moveCursorDown(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = \min(self::DAY_INDEX_MAX, $clone->cursorIndex + self::DAYS_IN_WEEK);

        return $clone;
    }

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    /**
     * Enter selection mode and set the selected date to the cursor date.
     * Falls back to the first of the viewed month when the cursor is on
     * an empty cell (before the 1st or past the last day of the month).
     *
     * Early exit: when no date can be resolved (month beyond 4-digit
     * representation) or the resolved date lies outside the configured
     * [minDate, maxDate] bounds, selection is REFUSED — the receiver is
     * returned unchanged (same reference, not a copy).
     */
    public function selectDate(): self
    {
        $date = $this->dateAtCursor() ?? $this->firstOfViewMonth();
        if ($date === null || !$this->inBounds($date)) {
            return $this;
        }

        $clone = clone $this;
        $clone->selecting = true;
        $clone->selectedDate = $date;

        return $clone;
    }

    /**
     * Clear selection / exit selection mode.
     */
    public function clearDate(): self
    {
        $clone = clone $this;
        $clone->selecting = false;
        $clone->selectedDate = null;

        return $clone;
    }

    public function toggleSelection(): self
    {
        return $this->selecting ? $this->clearDate() : $this->selectDate();
    }

    // -------------------------------------------------------------------------
    // Range selection
    // -------------------------------------------------------------------------

    public function withRangeMode(bool $mode): self
    {
        $clone = clone $this;
        $clone->rangeMode = $mode;
        if (!$mode) {
            $clone->rangeStart = null;
            $clone->rangeEnd = null;
        }

        return $clone;
    }

    public function rangeStart(): ?\DateTimeImmutable
    {
        return $this->rangeStart;
    }

    public function rangeEnd(): ?\DateTimeImmutable
    {
        return $this->rangeEnd;
    }

    public function isRangeMode(): bool
    {
        return $this->rangeMode;
    }

    /**
     * Handle keyboard navigation and selection (single AND range mode).
     *
     * Arrow keys: move cursor (grid clamp, no month-boundary wrap)
     * Enter:      range mode — set range start (first press) or range end
     *             (second press); single mode — select the highlighted date
     *             (same semantics as SelectDate(), incl. the empty-cell
     *             fallback and min/max refusal)
     * Escape / esc: range mode — clear the range; single mode — clear the
     *             selection (same as ClearDate()); both spellings act (dp-18
     *             accepts the core spelling alongside the BC 'esc' wire value)
     * Home/End:   jump to the first / last SELECTABLE day of the viewed month
     *             (min/max are consulted: the jump scans past dimmed days;
     *             a whole month outside the window leaves the cursor in place)
     *
     * An unrecognized key returns the receiver unchanged AND logs
     * `datepicker: unknown key …` — the wire vocabulary IS the API of a
     * key-driven widget, so a typo must be loud at integration time (no
     * throw: handleKey stays pure). A refused selection likewise returns
     * state-equal results silently. Callers must compare on state,
     * not object identity, to detect a no-op.
     */
    public function handleKey(string $key): self
    {
        if ($key === self::KEY_LEFT) {
            return $this->moveCursorLeft();
        }
        if ($key === self::KEY_RIGHT) {
            return $this->moveCursorRight();
        }
        if ($key === self::KEY_UP) {
            return $this->moveCursorUp();
        }
        if ($key === self::KEY_DOWN) {
            return $this->moveCursorDown();
        }
        if ($key === self::KEY_HOME) {
            return $this->jumpToFirstValidDay();
        }
        if ($key === self::KEY_END) {
            return $this->jumpToLastValidDay();
        }
        if ($key === self::KEY_ENTER) {
            return $this->rangeMode
                ? $this->handleRangeEnter()
                : $this->selectDate();
        }
        if ($key === self::KEY_ESCAPE || $key === self::KEY_ESCAPE_ALT) {
            if ($this->rangeMode) {
                $clone = clone $this;
                $clone->rangeStart = null;
                $clone->rangeEnd = null;

                return $clone;
            }

            return $this->clearDate();
        }

        // Unknown key: loud at integration, state-safe at runtime.
        \error_log('datepicker: unknown key ' . $key);

        return $this;
    }

    private function jumpToFirstValidDay(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = $clone->firstValidIndex();

        return $clone;
    }

    private function jumpToLastValidDay(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = $clone->lastValidIndex();

        return $clone;
    }

    private function handleRangeEnter(): self
    {
        $date = $this->dateAtCursor();
        if ($date === null || !$this->inBounds($date)) {
            // Early exit: empty cell or out-of-bounds date cannot anchor a range.
            return $this;
        }

        $clone = clone $this;

        if ($this->rangeStart === null) {
            $clone->rangeStart = $date;
            $clone->rangeEnd = null;
        } elseif ($this->rangeEnd === null) {
            // Set end to cursor date; ensure start <= end
            if (self::dayKey($date) < self::dayKey($this->rangeStart)) {
                $clone->rangeEnd = $this->rangeStart;
                $clone->rangeStart = $date;
            } else {
                $clone->rangeEnd = $date;
            }
        } else {
            // Both set — start fresh
            $clone->rangeStart = $date;
            $clone->rangeEnd = null;
        }

        return $clone;
    }

    // -------------------------------------------------------------------------
    // Queries
    // -------------------------------------------------------------------------

    public function selectedDate(): ?\DateTimeImmutable
    {
        return $this->selectedDate;
    }

    public function isSelecting(): bool
    {
        return $this->selecting;
    }

    public function cursorIndex(): int
    {
        return $this->cursorIndex;
    }

    public function viewMonth(): int
    {
        return $this->viewMonth;
    }

    public function viewYear(): int
    {
        return $this->viewYear;
    }

    /**
     * Get the date at the current cursor position, or null when the cursor
     * sits on an empty cell (before the 1st of the month or past the last
     * day in the 6×7 grid).
     *
     * Delegates to Navigation::gridIndexToDate(). The constructor and
     * SetTime floor the view year to >= 1 and clamp the month to 1-12, so the
     * delegated call never receives out-of-range input and cannot throw.
     */
    public function dateAtCursor(): ?\DateTimeImmutable
    {
        return Navigation::gridIndexToDate($this->cursorIndex, $this->viewMonth, $this->viewYear);
    }

    private function firstOfViewMonth(): ?\DateTimeImmutable
    {
        // Leading "!" zeroes time-of-day so cursor dates are at 00:00:00
        // instead of inheriting the current wall clock from createFromFormat.
        $d = \DateTimeImmutable::createFromFormat(
            '!Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
        );
        return $d === false ? null : $d;
    }

    /**
     * Grid index of the day-number within the viewed month, or null when the
     * date is not in this month (so the cursor cannot be aimed at it).
     */
    private function gridIndexOf(?\DateTimeImmutable $date): ?int
    {
        if ($date === null) {
            return null;
        }
        if ((int) $date->format('n') !== $this->viewMonth || (int) $date->format('Y') !== $this->viewYear) {
            return null;
        }
        $firstOfMonth = $this->firstOfViewMonth();
        if ($firstOfMonth === null) {
            return null;
        }
        $firstDow = (int) $firstOfMonth->format('w');

        return $this->clampedCursor($firstDow + (int) $date->format('j') - 1);
    }

    /** Cursor index for a seed date: its cell if in-month, else the first selectable day. */
    private function cursorForDate(?\DateTimeImmutable $date): int
    {
        return $this->gridIndexOf($date) ?? $this->firstValidIndex();
    }

    /**
     * Grid index of the viewed month's first SELECTABLE day — day 1 scanned
     * forward through inBounds() until min/max accept it. A jump therefore
     * never parks the cursor on a dimmed out-of-bounds day. When the entire
     * viewed month lies outside the window the cursor stays where it is (the
     * old grid-valid landing place is unreachable then anyway: navigation
     * clamps month stepping to the bound months).
     */
    private function firstValidIndex(): int
    {
        return $this->edgeIndex(true);
    }

    /**
     * Grid index of the viewed month's last SELECTABLE day — day N scanned
     * backward through inBounds(). Same whole-month-out fallback as
     * {@see firstValidIndex()}: current cursor.
     */
    private function lastValidIndex(): int
    {
        return $this->edgeIndex(false);
    }

    /** Shared scan for the first/last selectable day-cell of the viewed month. */
    private function edgeIndex(bool $fromStart): int
    {
        $firstOfMonth = $this->firstOfViewMonth();
        if ($firstOfMonth === null) {
            // Defensive: flooredView keeps year>=1/month 1-12, so this is
            // unreachable in practice. Leaving the cursor in place beats
            // aiming it at a cell this helper knows nothing about.
            return $this->cursorIndex;
        }
        $firstDow    = (int) $firstOfMonth->format('w');
        $daysInMonth = (int) $firstOfMonth->format('t');

        for ($i = 0; $i < $daysInMonth; $i++) {
            $day = $fromStart ? $i + 1 : $daysInMonth - $i;
            $date = \DateTimeImmutable::createFromFormat(
                '!Y-m-d', \sprintf('%04d-%02d-%02d', $this->viewYear, $this->viewMonth, $day)
            );
            if ($date !== false && $this->inBounds($date)) {
                return $this->clampedCursor($firstDow + $day - 1);
            }
        }

        return $this->cursorIndex;
    }

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    /**
     * Render the date-picker view as an ANSI string using a Buffer.
     *
     * @param ?int $width    Requested calendar-area width in characters.
     *                      Null (default) falls back to the withWidth() state
     *                      intent, so the argless Core\Model contract renders
     *                      the configured grid. The TOTAL buffer width
     *                      (calendar area + the optional 4-cell week column)
     *                      is clamped into [natural, 63]: the floor
     *                      guarantees all seven day columns stay visible
     *                      (21 without a week column, 25 with one); the
     *                      ceiling is 63. Widths outside that band are
     *                      silently clamped, never rejected.
     * @param ?bool $showWeekNumbers Show ISO week number column on the left.
     *                      Null (default) falls back to the
     *                      withShowWeekNumbers() state intent.
     */
    public function view(?int $width = null, ?bool $showWeekNumbers = null): string
    {
        $width ??= $this->renderWidth;
        $showWeekNumbers ??= $this->showWeekNumbers;
        $weekColWidth = $showWeekNumbers ? self::WEEK_COL_CHARS : 0;
        $minTotal = self::DAYS_IN_WEEK * self::CELL_CHARS + $weekColWidth;
        $totalWidth = \max($minTotal, \min(self::MAX_TOTAL_WIDTH, $width + $weekColWidth));
        $height = 9; // header + day-names + sep + 6 week rows

        $buffer = Buffer::new($totalWidth, $height);

        // Row 0: header via the locale template (dp-15) so year-first locales
        // can reorder without patching code; en renders "May 2026" exactly as
        // the pre-template byte string. Budget = the calendar
        // area (total width minus the week column); at the natural 21 floor
        // that leaves "September 2026" room, and a fatter locale clips on
        // width instead of bleeding over the day-name row's columns.
        $headerText = Lang::t('header', [
            'month' => self::monthName($this->viewMonth),
            'year'  => (string) $this->viewYear,
        ]);
        $headerText = Width::truncate($headerText, $totalWidth - $weekColWidth);
        $buffer = $this->placeStringAt($buffer, $weekColWidth, 0, $headerText, $this->sgrToBufferStyle($this->headerStyle));

        // Row 1: day-name row. Each name is clamped to its own 3-column
        // slot (the locale budget, see lang/*.php contract): an oversized
        // translation degrades cosmetically (a clip) instead of
        // sequentially overwriting the NEXT header column.
        for ($dow = 0; $dow < self::DAYS_IN_WEEK; $dow++) {
            $col = $weekColWidth + $dow * self::CELL_CHARS; // 3-char cells
            $dayName = Width::truncate(self::dayName($dow), self::CELL_CHARS);
            $buffer = $this->placeStringAt($buffer, $col, 1, $dayName, $this->sgrToBufferStyle($this->dayNameStyle));
        }

        // Row 2: separator
        for ($col = $weekColWidth; $col < $totalWidth; $col++) {
            $buffer = $buffer->withCellAt($col, 2, Cell::new('─'));
        }

        // Rows 3-8: week rows — rebuild the 42-cell grid on every render.
        // View() is a pure query: it must not mutate $this (a memoised cache
        // broke immutability, since two callers sharing an instance would see
        // each other's writes). O(42) is negligible, so we recompute instead.
        $cells = $this->buildCells();

        for ($week = 0; $week < 6; $week++) {
            $row = 3 + $week;

            // Optional week number column — omitted on rows with no in-month
            // day (a ghost week number over an all-blank row is meaningless).
            if ($showWeekNumbers && $this->weekRowHasDay($cells, $week)) {
                $weekNum = $this->isoWeekNumber($week);
                $buffer = $this->placeStringAt($buffer, 0, $row, $weekNum, $this->sgrToBufferStyle('90'));
            }

            // Day cells — each day is a 2-char number centered in a 3-char cell
            for ($dow = 0; $dow < self::DAYS_IN_WEEK; $dow++) {
                $idx = $week * self::DAYS_IN_WEEK + $dow;
                [$plain, $style] = $cells[$idx] ?? ['  ', null];
                if ($plain === '  ' && $style === null) {
                    continue;
                }
                $col = $weekColWidth + $dow * self::CELL_CHARS;
                $buffer = $this->placeStringAt($buffer, $col, $row, $plain, $style);
            }
        }

        return $buffer->toAnsi();
    }

    /** Whether a displayed week row (0-5) contains at least one real day. */
    private function weekRowHasDay(array $cells, int $week): bool
    {
        for ($dow = 0; $dow < self::DAYS_IN_WEEK; $dow++) {
            $idx = $week * self::DAYS_IN_WEEK + $dow;
            if (isset($cells[$idx]) && \trim($cells[$idx][0]) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * Compute the ISO week number for a given grid row (0-5).
     *
     * A displayed row is a Sunday-start week, but ISO weeks run Monday to
     * Sunday: the row's Sunday belongs to the PRECEDING ISO week, so reading
     * the week number off the Sunday under-labels Sunday-start rows by one.
     * Every day of a Monday-Sunday week shares one ISO number, so the row's
     * Wednesday is used as the unambiguous representative.
     *
     * @param int $weekRow 0-based week row index (0 = first week displayed)
     */
    private function isoWeekNumber(int $weekRow): string
    {
        // Compute the date of the first day shown in this week row
        $firstOfMonth = $this->firstOfViewMonth();
        if ($firstOfMonth === null) {
            return '   ';
        }
        $firstDow = (int) $firstOfMonth->format('w'); // 0=Sun
        // %+d forces an explicit sign: for week row 0 the offset is negative
        // (step back to the row's Sunday). String-concatenating "+" with a
        // negative int yields the malformed "+-4 days", which PHP mis-parses
        // as +4, shifting every week number a full week forward.
        $sundayOfWeekRow = $firstOfMonth->modify(
            \sprintf('%+d days', -$firstDow + $weekRow * self::DAYS_IN_WEEK)
        );
        // ISO week representative: the Wednesday of this displayed row.
        $midWeek = $sundayOfWeekRow->modify('+3 days');

        $isoWeek = (int) $midWeek->format('W');
        return \sprintf('%2d ', $isoWeek);
    }

    // -------------------------------------------------------------------------
    // Styling helpers
    // -------------------------------------------------------------------------

    public function withHeaderStyle(string $s): self
    {
        self::assertSgr($s);
        $clone = clone $this;
        $clone->headerStyle = $s;

        return $clone;
    }

    public function withDayNameStyle(string $s): self
    {
        self::assertSgr($s);
        $clone = clone $this;
        $clone->dayNameStyle = $s;

        return $clone;
    }

    public function withTodayStyle(string $s): self
    {
        self::assertSgr($s);
        $clone = clone $this;
        $clone->todayStyle = $s;

        return $clone;
    }

    public function withSelectedStyle(string $s): self
    {
        self::assertSgr($s);
        $clone = clone $this;
        $clone->selectedStyle = $s;

        return $clone;
    }

    public function withSelectedTodayStyle(string $s): self
    {
        self::assertSgr($s);
        $clone = clone $this;
        $clone->selectedTodayStyle = $s;

        return $clone;
    }

    public function withCursorStyle(string $s): self
    {
        self::assertSgr($s);
        $clone = clone $this;
        $clone->cursorStyle = $s;

        return $clone;
    }

    public function withRangeStyle(string $s): self
    {
        self::assertSgr($s);
        $clone = clone $this;
        $clone->rangeStyle = $s;

        return $clone;
    }

    /**
     * Configure the render width used by view() and Model::view() whenever no
     * explicit width argument is passed. The intent is stored as given — the
     * renderer clamps into the visible band at draw time (a host sizing from
     * a live terminal may set anything; it will never be rejected).
     */
    public function withWidth(int $width): self
    {
        $clone = clone $this;
        $clone->renderWidth = $width;

        return $clone;
    }

    /**
     * Configure whether the ISO week-number column renders by default. As
     * with withWidth(), this is the state-side default; an explicit
     * view($width, $showWeekNumbers) argument still wins per call.
     */
    public function withShowWeekNumbers(bool $show): self
    {
        $clone = clone $this;
        $clone->showWeekNumbers = $show;

        return $clone;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * Validate an SGR code string — must be empty, or digit groups joined by
     * single semicolons (e.g. "1;31", "7", ""). Dangling or empty parameter
     * shapes (";", "1;", "1;;2") are refused: sgrToBufferStyle() explodes on
     * ';' and would silently mis-parse them. Every numeric code must be a
     * member of {@see self::SUPPORTED_SGR} — the accept set and the decoder
     * are one enumerated truth, and the widget's standing principle applies:
     * accepting a code that then gets silently dropped or mis-decoded (the
     * old `58;5;9` → blink+strike read, the `39` "reset" that suppressed a
     * marker) is worse than a loud refusal. Off-codes (`0`, `21`-`29`, …) are
     * refused for the same reason: a no-op setter is a misleading setter.
     * Throws if a full escape sequence is passed, which would mangle that
     * parsing. The offending input is control-byte-escaped in the message so
     * a forged ESC can never reach the terminal through the error path.
     *
     * @throws \InvalidArgumentException
     */
    private static function assertSgr(string $s): void
    {
        if ($s === '') {
            return;
        }

        $quoted = '"' . \addcslashes($s, "\x00..\x1f\x7f\x80..\x9f\"\\") . '"';

        if (\strlen($s) > self::SGR_MAX_LEN) {
            throw new \InvalidArgumentException(
                'SGR code exceeds ' . self::SGR_MAX_LEN . ' characters, got: ' . $quoted
            );
        }

        // \z (not $) so a trailing newline is not silently accepted; no
        // unbounded digit runs beyond the length cap above.
        if (!\preg_match('/\A(?:\d+(?:;\d+)*)\z/', $s)) {
            throw new \InvalidArgumentException(
                'SGR code must be empty or digits/semicolons only, got: ' . $quoted
            );
        }

        foreach (\explode(';', $s) as $code) {
            if (!\in_array((int) $code, self::SUPPORTED_SGR, true)) {
                throw new \InvalidArgumentException(
                    'SGR code ' . $quoted . ' contains ' . $code
                    . ', which has no decoder arm; only attributes 1-7, 9, foregrounds 30-37/90-97'
                    . ' and backgrounds 40-47/100-107 are supported'
                );
            }
        }
    }

    /**
     * Place a string into the buffer at (col, row), handling wide chars.
     *
     * C0 control bytes, DEL, the C1 range (U+0080-U+009F) and clusters mb_ord
     * cannot decode are dropped before they reach the buffer: the cell grid
     * only suppresses zero-width clusters, but a control character such as TAB
     * is scored width 4 and would otherwise land on the wire. The guard is the
     * boundary — sugar-cellbuffer's Buffer::toAnsi() emits each cell rune
     * verbatim — so untrusted translated text cannot smuggle control codes
     * (including the one-byte CSI at U+009B) into output.
     *
     * @return Buffer
     */
    private function placeStringAt(Buffer $buf, int $col, int $row, string $s, ?Style $style): Buffer
    {
        $clusters = function_exists('grapheme_str_split')
            ? (grapheme_str_split($s) ?: \mb_str_split($s, 1, 'UTF-8'))
            : \mb_str_split($s, 1, 'UTF-8');

        $colCursor = $col;
        foreach ($clusters as $cluster) {
            if ($colCursor >= $buf->width()) {
                break;
            }
            $ord = \mb_ord($cluster, 'UTF-8');
            if ($ord === false || $ord < 0x20 || $ord === 0x7f
                || ($ord >= 0x80 && $ord <= 0x9f)) {
                // C0, DEL, C1 (U+0080-U+009F — includes the one-byte CSI
                // U+009B) or a malformed UTF-8 cluster: drop it, consuming no
                // column. The malformed case is fail-CLOSED on purpose: a
                // sequence mb_ord cannot score may embed a raw ESC byte, and
                // sugar-cellbuffer emits whatever rune it is given verbatim.
                continue;
            }
            $gw = $this->graphemeWidth($cluster);
            if ($gw === 0) {
                // Zero-width clusters consume no column (they are not grapheme-
                // joined to the previous cell in this buffer model — dropping
                // them is correct; advancing used to shift later cells right).
                continue;
            }
            $buf = $buf->withCellAt($colCursor, $row, new Cell($cluster, $style, null, $gw));
            if ($gw === 2 && $colCursor + 1 < $buf->width()) {
                $buf = $buf->withCellAt($colCursor + 1, $row, Cell::continuation());
            }
            $colCursor += $gw;
        }
        return $buf;
    }

    /**
     * Convert SGR code string (e.g. "1;32" or "7") to a Buffer Style.
     */
    private function sgrToBufferStyle(string $sgr): ?Style
    {
        if ($sgr === '') {
            return null;
        }
        $fg = null;
        $bg = null;
        $attrs = 0;
        $codes = \explode(';', $sgr);
        foreach ($codes as $code) {
            $code = (int) $code;
            // Colors mirror SUPPORTED_SGR: 30-37/90-97 foregrounds,
            // 40-47/100-107 backgrounds (bright arms share ansiColorToRgb)
            if ($code >= 30 && $code <= 37) {
                $fg = $this->ansiColorToRgb($code - 30, false);
            } elseif ($code >= 40 && $code <= 47) {
                $bg = $this->ansiColorToRgb($code - 40, false);
            } elseif ($code >= 90 && $code <= 97) {
                $fg = $this->ansiColorToRgb($code - 90, true);
            } elseif ($code >= 100 && $code <= 107) {
                $bg = $this->ansiColorToRgb($code - 100, true);
            } elseif ($code === 1) {
                $attrs |= Style::ATTR_BOLD;
            } elseif ($code === 2) {
                $attrs |= Style::ATTR_FAINT;
            } elseif ($code === 3) {
                $attrs |= Style::ATTR_ITALIC;
            } elseif ($code === 4) {
                $attrs |= Style::ATTR_UNDERLINE;
            } elseif ($code === 5 || $code === 6) {
                $attrs |= Style::ATTR_BLINK;
            } elseif ($code === 7) {
                $attrs |= Style::ATTR_REVERSE;
            } elseif ($code === 9) {
                $attrs |= Style::ATTR_STRIKE;
            }
        }
        return ($fg !== null || $bg !== null || $attrs !== 0)
            ? new Style($fg, $bg, $attrs)
            : null;
    }

    /**
     * Map an SGR colour index (0-7, bright variant when {@see $bright}) to a
     * packed 24-bit RGB int. The triples come from sugar-core's canonical
     * xterm table — slot 4 = `#0000EE` (`main.h DEF_COLOR4 "blue2"`), slot 12
     * = `#5C5CFF` (`DEF_COLOR12 "rgb:5c/5c/ff"`) — indexed from
     * {@see Color::ANSI16_RGB} so this decode can never fork its own blues
     * again. Indices that resolve outside the 16-slot table fall back to its
     * white slot (7, or 15 for the bright half), preserving the old
     * default-to-white.
     */
    private function ansiColorToRgb(int $idx, bool $bright): int
    {
        $offset = $bright ? 8 : 0;
        [$r, $g, $b] = Color::ANSI16_RGB[$offset + $idx] ?? Color::ANSI16_RGB[$offset + 7];
        return ($r << 16) | ($g << 8) | $b;
    }

    /**
     * Display width of one cluster, delegated to the canonical
     * SugarCraft\Core\Util\Width table (Unicode 16.0 East_Asian_Width +
     * Emoji_Presentation). The former hand-rolled range fork here was a
     * stale copy that scored modern emoji as 1 cell and misjudged CJK
     * punctuation.
     */
    private function graphemeWidth(string $g): int
    {
        return Width::of($g);
    }

    /**
     * Build 42-cell grid (6 weeks). Each cell is a 2-tuple: [plainText, ?Style].
     *
     * @return list<array{0: string, 1: Style|null}>
     */
    private function buildCells(): array
    {
        // Leading "!" per the firstOfViewMonth law: the grid must not inherit
        // the wall-clock time-of-day from createFromFormat.
        $firstOfMonth = \DateTimeImmutable::createFromFormat(
            '!Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
        );
        if ($firstOfMonth === false) return [];

        $daysInMonth = (int) $firstOfMonth->format('t');
        $firstDow    = (int) $firstOfMonth->format('w'); // 0=Sun

        $todayKey    = self::dayKey($this->today());
        $selectedKey = $this->selectedDate !== null
            ? self::dayKey($this->selectedDate) : 0;

        $range = $this->buildRange();

        $cells = [];

        for ($i = 0; $i < self::GRID_CELLS; $i++) {
            $dayNum = $i - $firstDow + 1;

            $cellDate = ($dayNum >= 1 && $dayNum <= $daysInMonth)
                ? $firstOfMonth->modify('+' . ($dayNum - 1) . ' days')
                : null;

            // Cursor override is applied to EVERY cell the cursor rests on —
            // including empty lead-in/trailing cells — so the single focus
            // cue stays visible wherever the cursor lands.
            $isCursor = $i === $this->cursorIndex;

            if ($cellDate === null) {
                $cells[] = ['  ', $isCursor ? $this->applyCursorStyle(null) : null];
                continue;
            }

            $dayKey   = self::dayKey($cellDate);
            $isToday  = $dayKey === $todayKey;

            $isInRange   = $range !== null && $range->contains($cellDate);
            $isRangeEdge = $range !== null
                && (self::dayKey($this->rangeStart) === $dayKey
                    || self::dayKey($this->rangeEnd) === $dayKey);
            // In-progress anchor: range start armed but not yet completed.
            $isRangeArm = $this->rangeMode
                && $range === null
                && $this->rangeStart !== null
                && $dayKey === self::dayKey($this->rangeStart);

            $text = \sprintf('%2d', $dayNum);

            if ($isToday && $dayKey === $selectedKey) {
                $style = $this->sgrToBufferStyle($this->selectedTodayStyle);
            } elseif ($isInRange) {
                $style = $this->sgrToBufferStyle($this->rangeStyle);
            } elseif ($isRangeArm) {
                $style = $this->sgrToBufferStyle($this->selectedStyle);
            } elseif ($dayKey === $selectedKey && $this->selecting) {
                $style = $this->sgrToBufferStyle($this->selectedStyle);
            } elseif ($isToday) {
                $style = $this->sgrToBufferStyle($this->todayStyle);
            } else {
                $style = null;
            }

            // Range endpoints keep a textual/underline cue distinct from the
            // fill so the start/end are visible, not two identical magenta days.
            if ($isRangeEdge && $style !== null) {
                $style = new Style(
                    $style->fg(),
                    $style->bg(),
                    $style->attrs() | Style::ATTR_UNDERLINE,
                );
            }

            // Out-of-bounds (disabled) days are dimmed so a host shows the
            // difference between an unselectable day and a selectable one.
            // The faint attribute COMPOSES onto whatever state style the chain
            // chose (buffer-level attr union, not an SGR string concat): a
            // disabled today/selected cell keeps its colour cue and still reads
            // disabled, so no out-of-bounds day can masquerade as live.
            if (!$this->inBounds($cellDate)) {
                $style = $style === null
                    ? new Style(null, null, Style::ATTR_FAINT)
                    : new Style($style->fg(), $style->bg(), $style->attrs() | Style::ATTR_FAINT);
            }

            // Apply cursor as final override — compose reverse attr onto existing style
            // so the cursor is visible even on today/selected/range cells.
            if ($isCursor) {
                $style = $this->applyCursorStyle($style);
            }

            $cells[] = [$text, $style];
        }

        return $cells;
    }

    /**
     * Compose the cursor's reverse attribute onto a base style, preserving
     * fg/bg colours so the cursor is visible on today/selected/range cells.
     */
    private function applyCursorStyle(?Style $base): Style
    {
        $cursorStyle = $this->sgrToBufferStyle($this->cursorStyle);
        if ($base === null) {
            return $cursorStyle ?? Style::reverse();
        }
        // OR the cursor attrs into the base style, keeping base fg/bg
        return new Style(
            $base->fg(),
            $base->bg(),
            $base->attrs() | ($cursorStyle?->attrs() ?? Style::ATTR_REVERSE),
        );
    }

    private function buildRange(): ?DateRange
    {
        if ($this->rangeStart === null || $this->rangeEnd === null) {
            return null;
        }
        // Highlight any portion of the range that overlaps the view month.
        // Return null only when the range cannot possibly intersect this month:
        // i.e. the range ends entirely before the first of this month, OR
        // the range starts entirely after the last of this month.
        $firstOfMonth = $this->firstOfViewMonth();
        if ($firstOfMonth === null) return null;
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        if (self::dayKey($this->rangeEnd) < self::dayKey($firstOfMonth)
            || self::dayKey($this->rangeStart) > self::dayKey($lastOfMonth)) {
            return null;
        }
        return new DateRange($this->rangeStart, $this->rangeEnd);
    }

    /**
     * Compute the clamped cursor index for the current view month: pulled
     * into the [first day, last day] window so a view change never leaves the
     * cursor resting on an empty lead-in or trailing cell.
     *
     * Pure: returns the clamped value without mutating $this.
     *
     * When the month itself is unconstructable (view year beyond the 4-digit
     * %04d representation), fall back to the raw grid clamp instead of
     * dereferencing a false createFromFormat() result.
     */
    private function clampedCursor(int $idx): int
    {
        $firstOfMonth = $this->firstOfViewMonth();
        if ($firstOfMonth === null) {
            return \min(self::DAY_INDEX_MAX, \max(0, $idx));
        }

        $firstDow     = (int) $firstOfMonth->format('w');
        $daysInMonth  = (int) $firstOfMonth->format('t');
        $firstIndex   = $firstDow;
        $lastIndex    = $firstDow + $daysInMonth - 1;

        return \min(\max($idx, $firstIndex), $lastIndex);
    }
}
