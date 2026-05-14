<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Select;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A date picker component.
 *
 * Features:
 * - Month view grid display
 * - Day selection highlighting
 * - Navigation between months
 * - Configurable starting day of week
 *
 * Mirrors date picker UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class DatePicker implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const DAYS_SUNDAY = 0;
    public const DAYS_MONDAY = 1;
    public const DAYS_SATURDAY = 6;

    private static array $MONTH_NAMES = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December',
    ];

    private static array $DAY_NAMES = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];

    public function __construct(
        private readonly int $year = 0,
        private readonly int $month = 0,
        private readonly int $selectedDay = 0,
        private readonly int $startDayOfWeek = self::DAYS_SUNDAY,
        private readonly ?Color $headerColor = null,
        private readonly ?Color $selectedColor = null,
        private readonly ?Color $todayColor = null,
        private readonly ?Color $weekendColor = null,
    ) {}

    /**
     * Create a new date picker with default styling.
     */
    public static function new(int $year, int $month, ?int $selectedDay = null): self
    {
        $currentYear = (int) date('Y');
        $currentMonth = (int) date('n');
        $today = (int) date('j');

        return new self(
            year: $year ?: $currentYear,
            month: $month ?: $currentMonth,
            selectedDay: $selectedDay ?? $today,
            startDayOfWeek: self::DAYS_SUNDAY,
            headerColor: Color::hex('#3B82F6'),
            selectedColor: Color::hex('#874BFD'),
            todayColor: Color::hex('#22C55E'),
            weekendColor: Color::hex('#EF4444'),
        );
    }

    /**
     * Create for current month.
     */
    public static function today(): self
    {
        return self::new((int) date('Y'), (int) date('n'));
    }

    /**
     * Set the allocated dimensions for this date picker.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the date picker as a string.
     */
    public function render(): string
    {
        $result = $this->renderHeader();
        $result .= "\n" . $this->renderDayHeaders();
        $result .= "\n" . $this->renderCalendarGrid();

        return $result;
    }

    /**
     * Render the month/year header.
     */
    private function renderHeader(): string
    {
        $monthName = self::$MONTH_NAMES[$this->month - 1] ?? 'Unknown';
        $content = $monthName . ' ' . $this->year;

        if ($this->headerColor !== null) {
            return $this->headerColor->toFg(ColorProfile::TrueColor) . $content . Ansi::reset();
        }

        return $content;
    }

    /**
     * Render the day-of-week headers.
     */
    private function renderDayHeaders(): string
    {
        $headers = [];

        for ($i = 0; $i < 7; $i++) {
            $dayIndex = ($this->startDayOfWeek + $i) % 7;
            $headers[] = self::$DAY_NAMES[$dayIndex];
        }

        $line = implode(' ', $headers);

        if ($this->headerColor !== null) {
            return $this->headerColor->toFg(ColorProfile::TrueColor) . $line . Ansi::reset();
        }

        return $line;
    }

    /**
     * Render the calendar grid.
     */
    private function renderCalendarGrid(): string
    {
        $firstDayOfMonth = $this->getFirstDayOfMonth();
        $daysInMonth = $this->getDaysInMonth();
        $today = (int) date('j');
        $currentMonth = (int) date('n');
        $currentYear = (int) date('Y');

        $isCurrentMonth = ($this->year === $currentYear && $this->month === $currentMonth);

        // Calculate offset
        $offset = ($firstDayOfMonth - $this->startDayOfWeek + 7) % 7;

        $weeks = [];
        $currentDay = 1 - $offset;

        // Build 6 weeks (max rows in a month view)
        for ($week = 0; $week < 6; $week++) {
            $weekDays = [];
            for ($dayOfWeek = 0; $dayOfWeek < 7; $dayOfWeek++) {
                if ($currentDay < 1 || $currentDay > $daysInMonth) {
                    $weekDays[] = '  ';
                } else {
                    $weekDays[] = $this->renderDay($currentDay, $isCurrentMonth, $today, $dayOfWeek);
                }
                $currentDay++;
            }
            $weeks[] = implode(' ', $weekDays);
        }

        // Stop when we've shown all days (but keep at least some weeks for consistency)
        $lastWeekWithDays = 0;
        for ($i = count($weeks) - 1; $i >= 0; $i--) {
            if (strpos($weeks[$i], str_repeat('  ', 6)) !== 0 || $i < 5) {
                $lastWeekWithDays = $i;
                break;
            }
        }

        $weeks = array_slice($weeks, 0, $lastWeekWithDays + 1);

        return implode("\n", $weeks);
    }

    /**
     * Render a single day cell.
     */
    private function renderDay(int $day, bool $isCurrentMonth, int $today, int $dayOfWeek): string
    {
        $isWeekend = ($dayOfWeek === 0 || $dayOfWeek === 6);
        $isToday = $isCurrentMonth && ($day === $today);
        $isSelected = ($day === $this->selectedDay);

        $dayStr = str_pad((string) $day, 2, ' ', STR_PAD_LEFT);

        if ($isSelected && $this->selectedColor !== null) {
            return $this->selectedColor->toFg(ColorProfile::TrueColor) . '[' . $dayStr . ']' . Ansi::reset();
        }

        if ($isToday && $this->todayColor !== null) {
            return $this->todayColor->toFg(ColorProfile::TrueColor) . '(' . $dayStr . ')' . Ansi::reset();
        }

        if ($isWeekend && $this->weekendColor !== null) {
            return $this->weekendColor->toFg(ColorProfile::TrueColor) . $dayStr . Ansi::reset();
        }

        return ' ' . $dayStr;
    }

    /**
     * Get the day of week for the first day of the month (0=Sunday).
     */
    private function getFirstDayOfMonth(): int
    {
        return (int) date('w', strtotime(sprintf('%04d-%02d-01', $this->year, $this->month)));
    }

    /**
     * Get the number of days in the month.
     */
    private function getDaysInMonth(): int
    {
        return (int) date('t', strtotime(sprintf('%04d-%02d-01', $this->year, $this->month)));
    }

    /**
     * Calculate the natural dimensions of this date picker.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Header line + day headers line + up to 6 week lines
        // Each day cell is 3 chars (space + 2-digit day), 7 days per week
        $width = 3 * 7 + 6; // 20 chars for week row + separators = 27
        $height = 1 + 1 + 6; // header + day names + up to 6 weeks

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the year and month.
     */
    public function withDate(int $year, int $month): self
    {
        $month = max(1, min(12, $month));
        $daysInMonth = (int) date('t', strtotime(sprintf('%04d-%02d-01', $year, $month)));
        $selectedDay = min($this->selectedDay, $daysInMonth);

        return new self(
            year: $year,
            month: $month,
            selectedDay: $selectedDay,
            startDayOfWeek: $this->startDayOfWeek,
            headerColor: $this->headerColor,
            selectedColor: $this->selectedColor,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Set the selected day.
     */
    public function withSelectedDay(int $day): self
    {
        $daysInMonth = $this->getDaysInMonth();

        return new self(
            year: $this->year,
            month: $this->month,
            selectedDay: max(1, min($day, $daysInMonth)),
            startDayOfWeek: $this->startDayOfWeek,
            headerColor: $this->headerColor,
            selectedColor: $this->selectedColor,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Navigate to the previous month.
     */
    public function withPreviousMonth(): self
    {
        if ($this->month === 1) {
            return new self(
                year: $this->year - 1,
                month: 12,
                selectedDay: $this->selectedDay,
                startDayOfWeek: $this->startDayOfWeek,
                headerColor: $this->headerColor,
                selectedColor: $this->selectedColor,
                todayColor: $this->todayColor,
                weekendColor: $this->weekendColor,
            );
        }

        return new self(
            year: $this->year,
            month: $this->month - 1,
            selectedDay: $this->selectedDay,
            startDayOfWeek: $this->startDayOfWeek,
            headerColor: $this->headerColor,
            selectedColor: $this->selectedColor,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Navigate to the next month.
     */
    public function withNextMonth(): self
    {
        if ($this->month === 12) {
            return new self(
                year: $this->year + 1,
                month: 1,
                selectedDay: $this->selectedDay,
                startDayOfWeek: $this->startDayOfWeek,
                headerColor: $this->headerColor,
                selectedColor: $this->selectedColor,
                todayColor: $this->todayColor,
                weekendColor: $this->weekendColor,
            );
        }

        return new self(
            year: $this->year,
            month: $this->month + 1,
            selectedDay: $this->selectedDay,
            startDayOfWeek: $this->startDayOfWeek,
            headerColor: $this->headerColor,
            selectedColor: $this->selectedColor,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Set the starting day of week.
     */
    public function withStartDayOfWeek(int $day): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            selectedDay: $this->selectedDay,
            startDayOfWeek: max(0, min(6, $day)),
            headerColor: $this->headerColor,
            selectedColor: $this->selectedColor,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Set the header color.
     */
    public function withHeaderColor(?Color $color): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            selectedDay: $this->selectedDay,
            startDayOfWeek: $this->startDayOfWeek,
            headerColor: $color,
            selectedColor: $this->selectedColor,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Set the selected day color.
     */
    public function withSelectedColor(?Color $color): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            selectedDay: $this->selectedDay,
            startDayOfWeek: $this->startDayOfWeek,
            headerColor: $this->headerColor,
            selectedColor: $color,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Set the today highlight color.
     */
    public function withTodayColor(?Color $color): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            selectedDay: $this->selectedDay,
            startDayOfWeek: $this->startDayOfWeek,
            headerColor: $this->headerColor,
            selectedColor: $this->selectedColor,
            todayColor: $color,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Set the weekend day color.
     */
    public function withWeekendColor(?Color $color): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            selectedDay: $this->selectedDay,
            startDayOfWeek: $this->startDayOfWeek,
            headerColor: $this->headerColor,
            selectedColor: $this->selectedColor,
            todayColor: $this->todayColor,
            weekendColor: $color,
        );
    }
}