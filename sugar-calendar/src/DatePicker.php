<?php

declare(strict_types=1);

namespace SugarCraft\Calendar;

use SugarCraft\Buffer\Buffer;
use SugarCraft\Buffer\Cell;
use SugarCraft\Buffer\Style;
use SugarCraft\Calendar\Lang;

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
        $today = $this->today();
        $clone = clone $this;
        $clone->viewMonth = (int) $today->format('n');
        $clone->viewYear  = (int) $today->format('Y');
        $clone->clampCursor();
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

    /** Resolve the "today" reference: injected value or real now. */
    private function today(): \DateTimeImmutable
    {
        return $this->today ?? new \DateTimeImmutable();
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

    /**
     * Render the calendar view as an ANSI string using a Buffer.
     */
    public function View(): string
    {
        $width = 21; // 7 days × 3 chars each: "Su Mo Tu We Th Fr Sa" is 21 chars (2 + 1) × 7
        $height = 9; // header + day-names + sep + 6 week rows
        $buffer = Buffer::new($width, $height);

        // Row 0: header "May 2026" (left-aligned)
        $headerText = self::monthName($this->viewMonth) . ' ' . $this->viewYear;
        $buffer = $this->placeStringAt($buffer, 0, 0, $headerText, $this->sgrToBufferStyle($this->headerStyle));

        // Row 1: day-name row
        for ($dow = 0; $dow < 7; $dow++) {
            $col = $dow * 3; // 3-char cells: "Su" at col 0, "Mo" at col 3, …
            $dayName = self::dayName($dow);
            $buffer = $this->placeStringAt($buffer, $col, 1, $dayName, $this->sgrToBufferStyle($this->dayNameStyle));
        }

        // Row 2: separator
        for ($col = 0; $col < 21; $col++) {
            $buffer = $buffer->withCellAt($col, 2, Cell::new('─'));
        }

        // Rows 3-8: week rows
        $cells = $this->buildCells();
        for ($week = 0; $week < 6; $week++) {
            $row = 3 + $week;

            // Day cells — each day is a 2-char number centered in a 3-char cell
            for ($dow = 0; $dow < 7; $dow++) {
                $idx = $week * 7 + $dow;
                [$plain, $style] = $cells[$idx] ?? ['  ', null];
                if ($plain === '  ' && $style === null) {
                    continue;
                }
                $col = $dow * 3;
                $buffer = $this->placeStringAt($buffer, $col, $row, $plain, $style);
            }
        }

        return $buffer->toAnsi();
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

    /**
     * Place a string into the buffer at (col, row), handling wide chars.
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
            $gw = $this->graphemeWidth($cluster);
            if ($gw === 0) {
                $colCursor++;
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
            // Basic colors: 30-37 foreground, 40-47 background, 90-97 bright fg
            if ($code >= 30 && $code <= 37) {
                $fg = $this->ansiColorToRgb($code - 30, false);
            } elseif ($code >= 40 && $code <= 47) {
                $bg = $this->ansiColorToRgb($code - 40, false);
            } elseif ($code >= 90 && $code <= 97) {
                $fg = $this->ansiColorToRgb($code - 90, true);
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

    private function ansiColorToRgb(int $idx, bool $bright): int
    {
        $colors = [
            [0, 0, 0],       // black
            [128, 0, 0],     // red
            [0, 128, 0],     // green
            [128, 128, 0],   // yellow
            [0, 0, 128],     // blue
            [128, 0, 128],   // magenta
            [0, 128, 128],   // cyan
            [192, 192, 192], // white
        ];
        $c = $colors[$idx] ?? [192, 192, 192];
        if ($bright) {
            $c = [\min(255, $c[0] + 96), \min(255, $c[1] + 96), \min(255, $c[2] + 96)];
        }
        return ($c[0] << 16) | ($c[1] << 8) | $c[2];
    }

    private function graphemeWidth(string $g): int
    {
        if ($g === '') return 0;
        $cp = \function_exists('mb_ord') ? \mb_ord($g, 'UTF-8') : \ord($g[0]);
        if ($cp === false || $cp === 0) return 0;
        // ASCII control chars (0x00-0x08, 0x0B, 0x0C, 0x0E-0x1F, 0x7F) → 0
        // TAB(0x09), LF(0x0A), CR(0x0D) → 1 (visible)
        // DEL(0x7F) → 0
        if (($cp <= 0x08) || ($cp >= 0x0E && $cp <= 0x1F) || ($cp === 0x7F)) {
            return 0;
        }
        // Zero-width combining marks (0300-036F, 0483-0489, etc.)
        if (($cp >= 0x0300 && $cp <= 0x036F)
            || ($cp >= 0x0483 && $cp <= 0x0489)
            || ($cp >= 0x200b && $cp <= 0x200f)
            || ($cp >= 0x2028 && $cp <= 0x2029)
            || ($cp >= 0x2060 && $cp <= 0x2064)
            || ($cp === 0xfeff)) {
            return 0;
        }
        // Wide East-Asian chars → 2
        if (($cp >= 0x1100 && $cp <= 0x115f)
            || ($cp >= 0x3040 && $cp <= 0xfe6f)
            || ($cp >= 0xff00 && $cp <= 0xff60)
            || ($cp >= 0x20000 && $cp <= 0x2fffd)) {
            return 2;
        }
        return 1;
    }

    /**
     * Build 42-cell grid (6 weeks). Each cell is a 2-tuple: [plainText, ?Style].
     *
     * @return list<array{0: string, 1: Style|null}>
     */
    private function buildCells(): array
    {
        $firstOfMonth = \DateTimeImmutable::createFromFormat(
            'Y-m-d', \sprintf('%04d-%02d-01', $this->viewYear, $this->viewMonth)
        );
        if ($firstOfMonth === false) return [];

        $daysInMonth = (int) $firstOfMonth->format('t');
        $firstDow    = (int) $firstOfMonth->format('w'); // 0=Sun

        $today = $this->today();
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
                $cells[] = ['  ', null];
                continue;
            }

            $isToday   = $dayNum === $todayDay && $this->viewMonth === $todayMonth && $this->viewYear === $todayYear;
            $isCurrentMonth = $dayNum >= 1 && $dayNum <= $daysInMonth;

            $cellDate = $firstOfMonth->modify('+' . ($dayNum - 1) . ' days');
            $isInRange = $range !== null && $range->contains($cellDate);

            $text = \sprintf('%2d', $dayNum);
            $style = null;

            if ($isToday && $dayNum === $selectedDay) {
                $style = $this->sgrToBufferStyle($this->selectedTodayStyle);
            } elseif ($isInRange) {
                $style = $this->sgrToBufferStyle($this->rangeStyle);
            } elseif ($dayNum === $selectedDay && $this->selecting) {
                $style = $this->sgrToBufferStyle($this->selectedStyle);
            } elseif ($isToday) {
                $style = $this->sgrToBufferStyle($this->todayStyle);
            }

            // Apply cursor as final override — compose reverse attr onto existing style
            // so the cursor is visible even on today/selected/range cells.
            if ($i === $this->cursorIndex) {
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
        $lastOfMonth = $firstOfMonth->modify('last day of this month')->setTime(23, 59, 59);

        if ($this->rangeEnd < $firstOfMonth || $this->rangeStart > $lastOfMonth) {
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
}
