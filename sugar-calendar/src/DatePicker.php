<?php

declare(strict_types=1);

namespace CandyCore\Calendar;

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

    /** Styling (SGR ANSI codes). */
    private string $headerStyle       = '1;37';  // bold white
    private string $dayNameStyle      = '90';    // bright black
    private string $todayStyle        = '1;32';  // bold green
    private string $selectedStyle     = '1;36';  // bold cyan
    private string $selectedTodayStyle = '1;33'; // bold yellow
    private string $cursorStyle       = '7';     // reverse
    private string $normalDayStyle    = '';

    private const DAY_NAMES = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    private const MONTH_NAMES = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December',
    ];

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
     */
    public function SelectDate(): self
    {
        $clone = clone $this;
        $clone->selecting = true;
        $clone->selectedDate = $clone->dateAtCursor();
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
     * Get the date at the current cursor position (may be outside current month).
     */
    public function dateAtCursor(): ?\DateTimeImmutable
    {
        $firstOfMonth = \DateTimeImmutable::createFromFormat(
            'Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
        );
        if ($firstOfMonth === false) return null;

        $firstDow = (int) $firstOfMonth->format('w'); // 0=Sun
        $dayNum = $this->cursorIndex - $firstDow + 1;

        return $firstOfMonth->modify("+{$dayNum} days");
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
        foreach (self::DAY_NAMES as $d) {
            $dayRow .= ' ' . $this->ansi($d, $this->dayNameStyle) . ' ';
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

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function renderHeader(): string
    {
        $monthName = self::MONTH_NAMES[$this->viewMonth] ?? '';
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

        $cells = [];

        for ($i = 0; $i < 42; $i++) {
            $dayNum = $i - $firstDow + 1;

            if ($dayNum < 1 || $dayNum > $daysInMonth) {
                $cells[] = '  ';
                continue;
            }

            $isToday   = $dayNum === $todayDay && $this->viewMonth === $todayMonth && $this->viewYear === $todayYear;
            $isCurrentMonth = $dayNum >= 1 && $dayNum <= $daysInMonth;

            if ($isToday && $dayNum === $selectedDay) {
                $cells[] = $this->ansi(\sprintf('%2d', $dayNum), $this->selectedTodayStyle);
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
        return "\x1b[{$codes}m{$text}\x1b[0m";
    }
}
