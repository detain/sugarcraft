<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A calendar view component.
 *
 * Displays a monthly calendar grid with the current month highlighted.
 * Supports custom starting day of week (Sunday = 0 or Monday = 1).
 *
 * Mirrors calendar concepts adapted to PHP with wither-style immutable setters.
 */
final class Calendar implements Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    private const DAY_NAMES = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    private const DAY_NAMES_MON = ['Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa', 'Su'];
    private const MONTH_NAMES = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];

    public function __construct(
        private readonly int $year,
        private readonly int $month,
        private readonly int $highlightDay = 0,
        private readonly bool $startOnMonday = false,
        private readonly ?Color $headerColor = null,
        private readonly ?Color $todayColor = null,
        private readonly ?Color $weekendColor = null,
    ) {}

    /**
     * Create a calendar for the current month.
     */
    public static function new(): self
    {
        $today = getdate();
        return new self(
            year: $today['year'],
            month: $today['mon'],
            highlightDay: $today['mday'],
            startOnMonday: false,
            headerColor: Color::hex('#874BFD'),
            todayColor: Color::hex('#FD874B'),
            weekendColor: Color::hex('#888888'),
        );
    }

    /**
     * Create a calendar for a specific year and month.
     */
    public static function forDate(int $year, int $month): self
    {
        return new self(
            year: $year,
            month: max(1, min(12, $month)),
            highlightDay: 0,
            startOnMonday: false,
            headerColor: Color::hex('#874BFD'),
            todayColor: Color::hex('#FD874B'),
            weekendColor: Color::hex('#888888'),
        );
    }

    /**
     * Set the allocated dimensions for this calendar.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the calendar.
     */
    public function render(): string
    {
        $monthName = self::MONTH_NAMES[$this->month] ?? '';
        $header = sprintf('%s %d', $monthName, $this->year);

        $dayNames = $this->startOnMonday ? self::DAY_NAMES_MON : self::DAY_NAMES;

        // Get the number of days in the month
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $this->month, $this->year);

        // Get the day of week for the first day (0 = Sunday)
        $firstDayOfWeek = (int) date('w', mktime(0, 0, 0, $this->month, 1, $this->year));
        if ($this->startOnMonday) {
            $firstDayOfWeek = ($firstDayOfWeek + 6) % 7; // Convert to Monday = 0
        }

        // Build the header row
        $result = '';
        if ($this->headerColor !== null) {
            $result .= $this->headerColor->toFg(ColorProfile::TrueColor);
        }
        $result .= $header . "\n";
        $result .= implode(' ', $dayNames) . "\n";
        if ($this->headerColor !== null) {
            $result .= Ansi::reset();
        }

        // Build the calendar grid
        $currentDay = 1;
        $dayCells = [];

        // Add empty cells for days before the first day of the month
        for ($i = 0; $i < $firstDayOfWeek; $i++) {
            $dayCells[] = '  ';
        }

        // Add days of the month
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $dayCells[] = sprintf('%2d', $day);
        }

        // Pad the last row with empty cells
        while (count($dayCells) % 7 !== 0) {
            $dayCells[] = '  ';
        }

        // Render rows
        $rows = array_chunk($dayCells, 7);
        foreach ($rows as $row) {
            $rowStr = implode(' ', $row);
            $result .= $rowStr . "\n";
        }

        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural dimensions of this calendar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Width: "January 2024" = ~12 chars, each day cell is 2 + 1 space = 3 chars
        $width = 20; // Su Mo Tu We Th Fr Sa
        // Height: header + day names + up to 6 weeks
        $height = 2 + 6;
        return [$width, $height];
    }

    /**
     * Get the number of days in the displayed month.
     */
    public function getDaysInMonth(): int
    {
        return cal_days_in_month(CAL_GREGORIAN, $this->month, $this->year);
    }

    /**
     * Check if a given day is today (highlighted).
     */
    public function isToday(int $day): bool
    {
        return $day === $this->highlightDay;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the highlighted day (e.g., today).
     */
    public function withHighlight(int $day): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            highlightDay: $day,
            startOnMonday: $this->startOnMonday,
            headerColor: $this->headerColor,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Set whether weeks start on Monday.
     */
    public function withStartOnMonday(bool $startOnMonday): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            highlightDay: $this->highlightDay,
            startOnMonday: $startOnMonday,
            headerColor: $this->headerColor,
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
            highlightDay: $this->highlightDay,
            startOnMonday: $this->startOnMonday,
            headerColor: $color,
            todayColor: $this->todayColor,
            weekendColor: $this->weekendColor,
        );
    }

    /**
     * Set the today color.
     */
    public function withTodayColor(?Color $color): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            highlightDay: $this->highlightDay,
            startOnMonday: $this->startOnMonday,
            headerColor: $this->headerColor,
            todayColor: $color,
            weekendColor: $this->weekendColor,
        );
    }
}
