<?php

declare(strict_types=1);

namespace SugarCraft\Calendar;

use SugarCraft\Calendar\Lang;
use SugarCraft\Core\Util\Ansi;

/**
 * Interactive date picker component.
 *
 * Manages: current view month/year, cursor position (row/col grid),
 * selected date, today marker, and navigation.
 *
 * Port of EthanEFung/bubble-datepicker.
 *
 * @see https://github.com/EthanEFung/bubble-datepicker
 */
final class DatePicker
{
    private const DAYS_IN_WEEK = 7;

    // Key constants for handleKey()
    public const KEY_LEFT  = 'left';
    public const KEY_RIGHT = 'right';
    public const KEY_UP    = 'up';
    public const KEY_DOWN  = 'down';
    public const KEY_ENTER = 'enter';
    public const KEY_ESCAPE = 'esc';
    public const KEY_HOME  = 'home';
    public const KEY_END   = 'end';

    /** Currently viewed month (1-indexed). */
    private int $viewMonth;

    /** Currently viewed year. */
    private int $viewYear;

    /** Selected date (null if none selected). */
    private ?\DateTimeImmutable $selectedDate = null;

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

    /** Styling (SGR ANSI codes). */
    private string $headerStyle       = '1;37';  // bold white
    private string $dayNameStyle      = '90';    // bright black
    private string $todayStyle        = '1;32';  // bold green
    private string $selectedStyle     = '1;36';  // bold cyan
    private string $selectedTodayStyle = '1;33'; // bold yellow
    private string $cursorStyle       = '7';     // reverse
    private string $normalDayStyle    = '';
    private string $rangeStyle        = '1;35'; // bold magenta (range highlight)

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

    public function __construct(?\DateTimeImmutable $time = null)
    {
        $t = $time ?? new \DateTimeImmutable();
        $this->viewMonth = (int) $t->format('n');
        $this->viewYear  = (int) $t->format('Y');
    }

    public static function new(?\DateTimeImmutable $time = null): self
    {
        return new self($time);
    }

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    public function GoToPreviousMonth(): self
    {
        $clone = clone $this;
        if ($clone->viewMonth === 1) {
            $clone->viewMonth = 12;
            $clone->viewYear--;
        } else {
            $clone->viewMonth--;
        }
        $clone->clampCursor();
        return $clone;
    }

    public function GoToNextMonth(): self
    {
        $clone = clone $this;
        if ($clone->viewMonth === 12) {
            $clone->viewMonth = 1;
            $clone->viewYear++;
        } else {
            $clone->viewMonth++;
        }
        $clone->clampCursor();
        return $clone;
    }

    public function GoToPreviousYear(): self
    {
        $clone = clone $this;
        $clone->viewYear--;
        $clone->clampCursor();
        return $clone;
    }

    public function GoToNextYear(): self
    {
        $clone = clone $this;
        $clone->viewYear++;
        $clone->clampCursor();
        return $clone;
    }

    public function GoToToday(): self
    {
        $today = new \DateTimeImmutable();
        $clone = clone $this;
        $clone->viewMonth = (int) $today->format('n');
        $clone->viewYear  = (int) $today->format('Y');
        $clone->clampCursor();
        return $clone;
    }

    public function SetTime(\DateTimeImmutable $t): self
    {
        $clone = clone $this;
        $clone->viewMonth = (int) $t->format('n');
        $clone->viewYear  = (int) $t->format('Y');
        $clone->clampCursor();
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Cursor movement
    // -------------------------------------------------------------------------

    public function MoveCursorLeft(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = \max(0, $clone->cursorIndex - 1);
        return $clone;
    }

    public function MoveCursorRight(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = \min(41, $clone->cursorIndex + 1);
        return $clone;
    }

    public function MoveCursorUp(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = \max(0, $clone->cursorIndex - self::DAYS_IN_WEEK);
        return $clone;
    }

    public function MoveCursorDown(): self
    {
        $clone = clone $this;
        $clone->cursorIndex = \min(41, $clone->cursorIndex + self::DAYS_IN_WEEK);
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Selection
    // -------------------------------------------------------------------------

    /**
     * Enter selection mode and set the selected date to the cursor date.
     * Falls back to the first of the viewed month when the cursor is on
     * an empty cell (before the 1st or past the last day of the month).
     */
    public function SelectDate(): self
    {
        $clone = clone $this;
        $clone->selecting = true;
        $clone->selectedDate = $clone->dateAtCursor() ?? $clone->firstOfViewMonth();
        return $clone;
    }

    /**
     * Clear selection / exit selection mode.
     */
    public function ClearDate(): self
    {
        $clone = clone $this;
        $clone->selecting = false;
        $clone->selectedDate = null;
        return $clone;
    }

    public function ToggleSelection(): self
    {
        return $this->selecting ? $this->ClearDate() : $this->SelectDate();
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
     * Handle keyboard navigation and range selection.
     *
     * Arrow keys: move cursor
     * Enter: set range start (first press) or range end (second press)
     * Escape: clear range when in range mode
     * Home/End: jump to first/last cell
     */
    public function handleKey(string $key): self
    {
        $clone = clone $this;

        if ($key === self::KEY_LEFT) {
            return $clone->MoveCursorLeft();
        }
        if ($key === self::KEY_RIGHT) {
            return $clone->MoveCursorRight();
        }
        if ($key === self::KEY_UP) {
            return $clone->MoveCursorUp();
        }
        if ($key === self::KEY_DOWN) {
            return $clone->MoveCursorDown();
        }
        if ($key === self::KEY_HOME) {
            $clone->cursorIndex = 0;
            return $clone;
        }
        if ($key === self::KEY_END) {
            $clone->cursorIndex = 41;
            return $clone;
        }
        if ($key === self::KEY_ENTER && $this->rangeMode) {
            return $this->handleRangeEnter($clone);
        }
        if ($key === self::KEY_ESCAPE && $this->rangeMode) {
            $clone->rangeStart = null;
            $clone->rangeEnd = null;
            return $clone;
        }

        return $clone;
    }

    private function handleRangeEnter(self $clone): self
    {
        $date = $this->dateAtCursor();
        if ($date === null) {
            return $clone;
        }

        if ($this->rangeStart === null) {
            $clone->rangeStart = $date;
            $clone->rangeEnd = null;
        } elseif ($this->rangeEnd === null) {
            // Set end to cursor date; ensure start <= end
            if ($date < $this->rangeStart) {
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

    public function SelectedDate(): ?\DateTimeImmutable
    {
        return $this->selectedDate;
    }

    public function IsSelecting(): bool
    {
        return $this->selecting;
    }

    public function CursorIndex(): int
    {
        return $this->cursorIndex;
    }

    public function ViewMonth(): int
    {
        return $this->viewMonth;
    }

    public function ViewYear(): int
    {
        return $this->viewYear;
    }

    /**
     * Get the date at the current cursor position, or null when the cursor
     * sits on an empty cell (before the 1st of the month or past the last
     * day in the 6×7 grid).
     */
    public function dateAtCursor(): ?\DateTimeImmutable
    {
        $firstOfMonth = $this->firstOfViewMonth();
        if ($firstOfMonth === null) return null;

        $firstDow    = (int) $firstOfMonth->format('w'); // 0=Sun
        $daysInMonth = (int) $firstOfMonth->format('t');
        $dayNum      = $this->cursorIndex - $firstDow + 1;

        if ($dayNum < 1 || $dayNum > $daysInMonth) {
            return null;
        }

        // dayNum=1 is the 1st itself, hence offset = dayNum - 1.
        return $firstOfMonth->modify('+' . ($dayNum - 1) . ' days');
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

    // -------------------------------------------------------------------------
    // Rendering
    // -------------------------------------------------------------------------

    public function View(): string
    {
        $lines = [];
        $lines[] = $this->renderHeader();

        // Day names row
        $dayRow = '    ';
        for ($dow = 0; $dow < 7; $dow++) {
            $dayRow .= ' ' . $this->ansi(self::dayName($dow), $this->dayNameStyle) . ' ';
        }
        $lines[] = $dayRow;
        $lines[] = '   ' . \str_repeat('───', 7);

        $cells = $this->buildCells();
        for ($week = 0; $week < 6; $week++) {
            $line = \sprintf('%2d ', $week * 7 - $this->firstDayOffset() + 1);
            for ($dow = 0; $dow < 7; $dow++) {
                $idx = $week * 7 + $dow;
                $line .= ' ' . ($cells[$idx] ?? '  ') . ' ';
            }
            $lines[] = $line;
            if ($cells === []) break;
        }

        return \implode("\n", $lines);
    }

    // -------------------------------------------------------------------------
    // Styling helpers
    // -------------------------------------------------------------------------

    public function WithHeaderStyle(string $s): self
    {
        $clone = clone $this;
        $clone->headerStyle = $s;
        return $clone;
    }

    public function WithTodayStyle(string $s): self
    {
        $clone = clone $this;
        $clone->todayStyle = $s;
        return $clone;
    }

    public function WithSelectedStyle(string $s): self
    {
        $clone = clone $this;
        $clone->selectedStyle = $s;
        return $clone;
    }

    public function WithCursorStyle(string $s): self
    {
        $clone = clone $this;
        $clone->cursorStyle = $s;
        return $clone;
    }

    public function WithRangeStyle(string $s): self
    {
        $clone = clone $this;
        $clone->rangeStyle = $s;
        return $clone;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function renderHeader(): string
    {
        $monthName = self::monthName($this->viewMonth);
        $title = \sprintf('%s %d', $monthName, $this->viewYear);
        return $this->ansi($title, $this->headerStyle);
    }

    /**
     * Build 42-cell grid (6 weeks). Each cell is a 2-char string.
     *
     * @return list<string>
     */
    private function buildCells(): array
    {
        $firstOfMonth = \DateTimeImmutable::createFromFormat(
            'Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
        );
        if ($firstOfMonth === false) return [];

        $daysInMonth = (int) $firstOfMonth->format('t');
        $firstDow    = (int) $firstOfMonth->format('w'); // 0=Sun

        $today = new \DateTimeImmutable();
        $todayDay = (int) $today->format('j');
        $todayMonth = (int) $today->format('n');
        $todayYear  = (int) $today->format('Y');

        $selectedDay = $this->selectedDate !== null
            ? (int) $this->selectedDate->format('j') : 0;

        $range = $this->buildRange();

        $cells = [];

        for ($i = 0; $i < 42; $i++) {
            $dayNum = $i - $firstDow + 1;

            if ($dayNum < 1 || $dayNum > $daysInMonth) {
                $cells[] = '  ';
                continue;
            }

            $isToday   = $dayNum === $todayDay && $this->viewMonth === $todayMonth && $this->viewYear === $todayYear;
            $isCurrentMonth = $dayNum >= 1 && $dayNum <= $daysInMonth;

            $cellDate = $firstOfMonth->modify('+' . ($dayNum - 1) . ' days');
            $isInRange = $range !== null && $range->contains($cellDate);

            if ($isToday && $dayNum === $selectedDay) {
                $cells[] = $this->ansi(\sprintf('%2d', $dayNum), $this->selectedTodayStyle);
            } elseif ($isInRange) {
                $cells[] = $this->ansi(\sprintf('%2d', $dayNum), $this->rangeStyle);
            } elseif ($dayNum === $selectedDay && $this->selecting) {
                $cells[] = $this->ansi(\sprintf('%2d', $dayNum), $this->selectedStyle);
            } elseif ($isToday) {
                $cells[] = $this->ansi(\sprintf('%2d', $dayNum), $this->todayStyle);
            } else {
                $cells[] = \sprintf('%2d', $dayNum);
            }
        }

        return $cells;
    }

    private function buildRange(): ?DateRange
    {
        if ($this->rangeStart === null || $this->rangeEnd === null) {
            return null;
        }
        // Only highlight range when start and end are in the view month/year
        if ($this->rangeStart->format('Y-n') !== $this->viewYear . '-' . $this->viewMonth
            && $this->rangeEnd->format('Y-n') !== $this->viewYear . '-' . $this->viewMonth) {
            return null;
        }
        return new DateRange($this->rangeStart, $this->rangeEnd);
    }

    private function firstDayOffset(): int
    {
        $firstOfMonth = \DateTimeImmutable::createFromFormat(
            'Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
        );
        return $firstOfMonth !== false ? (int) $firstOfMonth->format('w') : 0;
    }

    private function clampCursor(): void
    {
        $daysInMonth = (int) \DateTimeImmutable::createFromFormat(
            'Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
        )->format('t');

        $firstDow = $this->firstDayOffset();
        $lastIndex = $firstDow + $daysInMonth - 1;

        $this->cursorIndex = \min($this->cursorIndex, \max(0, $lastIndex));
    }

    private function ansi(string $text, string $codes): string
    {
        if ($codes === '') return $text;
        return Ansi::CSI . $codes . 'm' . $text . Ansi::reset();
    }
}
