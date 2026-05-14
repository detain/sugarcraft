<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Calendar;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A simple calendar component.
 *
 * Features:
 * - Month/year view
 * - Day cells with customizable markers
 * - Today highlight
 * - Configurable start day of week
 * - Navigation support
 *
 * Mirrors calendar patterns adapted to PHP with wither-style immutable setters.
 */
final class Calendar implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    private const DAYS_OF_WEEK = ['Su', 'Mo', 'Tu', 'We', 'Th', 'Fr', 'Sa'];
    private const MONTHS = [
        'January', 'February', 'March', 'April', 'May', 'June',
        'July', 'August', 'September', 'October', 'November', 'December'
    ];

    public function __construct(
        private readonly int $year,
        private readonly int $month,
        private readonly int $startDayOfWeek = 0, // 0 = Sunday
        private readonly ?int $highlightDay = null,
        private readonly array $markedDays = [], // list<int> of marked days
        private readonly ?Color $headerColor = null,
        private readonly ?Color $todayColor = null,
        private readonly ?Color $markerColor = null,
    ) {}

    /**
     * Create a calendar for the current month.
     */
    public static function now(): self
    {
        return new self(
            year: (int) date('Y'),
            month: (int) date('n'),
            startDayOfWeek: 0,
            highlightDay: (int) date('j'),
            markedDays: [],
            headerColor: Color::hex('#89B4FA'),
            todayColor: Color::hex('#A6E3A1'),
            markerColor: Color::hex('#F38BA8'),
        );
    }

    /**
     * Create a calendar for a specific year and month.
     */
    public static function of(int $year, int $month): self
    {
        return new self(
            year: $year,
            month: $month,
            startDayOfWeek: 0,
            highlightDay: null,
            markedDays: [],
            headerColor: Color::hex('#89B4FA'),
            todayColor: Color::hex('#A6E3A1'),
            markerColor: Color::hex('#F38BA8'),
        );
    }

    /**
     * Set the allocated dimensions for this calendar.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this calendar.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Header (month year) + day names row + up to 6 weeks
        $width = 20; // enough for "September 2024"
        $height = 9; // header + days + 6 weeks
        return [$width, $height];
    }

    /**
     * Render the calendar.
     */
    public function render(): string
    {
        $monthName = self::MONTHS[$this->month - 1] ?? 'Unknown';
        $header = sprintf('%s %d', $monthName, $this->year);

        // Calculate days in month and starting day
        $daysInMonth = cal_days_in_month(CAL_GREGORIAN, $this->month, $this->year);
        $firstDayOfMonth = (int) date('w', mktime(0, 0, 0, $this->month, 1, $this->year));
        $adjustedFirstDay = ($firstDayOfMonth - $this->startDayOfWeek + 7) % 7;

        // Build header
        $headerColorStr = $this->headerColor?->toFg(ColorProfile::TrueColor) ?? '';
        $headerLine = str_pad($header, 20, ' ', STR_PAD_BOTH);
        $result = [$headerColorStr . $headerLine . Ansi::reset()];

        // Day names row
        $dayNames = [];
        for ($i = 0; $i < 7; $i++) {
            $dayIdx = ($this->startDayOfWeek + $i) % 7;
            $dayNames[] = self::DAYS_OF_WEEK[$dayIdx];
        }
        $result[] = implode(' ', $dayNames);

        // Calendar grid
        $dayNum = 1;
        $todayColorStr = $this->todayColor?->toFg(ColorProfile::TrueColor) ?? '';
        $markerColorStr = $this->markerColor?->toFg(ColorProfile::TrueColor) ?? '';

        for ($week = 0; $week < 6; $week++) {
            $weekLine = [];
            for ($day = 0; $day < 7; $day++) {
                $cellIndex = $week * 7 + $day;
                if ($cellIndex < $adjustedFirstDay || $dayNum > $daysInMonth) {
                    $weekLine[] = '  ';
                } else {
                    $dayStr = str_pad((string) $dayNum, 2, ' ', STR_PAD_LEFT);

                    // Apply highlighting
                    if ($dayNum === $this->highlightDay) {
                        $dayStr = $todayColorStr . '[' . $dayNum . ']' . Ansi::reset();
                        $dayStr = str_pad($dayStr, 4, ' ');
                    } elseif (in_array($dayNum, $this->markedDays)) {
                        $dayStr = $markerColorStr . '*' . ($dayNum < 10 ? ' ' : '') . $dayNum . Ansi::reset();
                    }

                    $weekLine[] = $dayStr;
                    $dayNum++;
                }
            }
            $result[] = implode(' ', $weekLine);

            // Stop if we've rendered all days
            if ($dayNum > $daysInMonth) {
                break;
            }
        }

        return implode("\n", $result);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the start day of week (0 = Sunday, 1 = Monday, etc).
     */
    public function withStartDayOfWeek(int $day): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            startDayOfWeek: $day,
            highlightDay: $this->highlightDay,
            markedDays: $this->markedDays,
            headerColor: $this->headerColor,
            todayColor: $this->todayColor,
            markerColor: $this->markerColor,
        );
    }

    /**
     * Set the highlighted day (e.g., today).
     */
    public function withHighlightDay(?int $day): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            startDayOfWeek: $this->startDayOfWeek,
            highlightDay: $day,
            markedDays: $this->markedDays,
            headerColor: $this->headerColor,
            todayColor: $this->todayColor,
            markerColor: $this->markerColor,
        );
    }

    /**
     * Set marked days (e.g., events).
     *
     * @param list<int> $days
     */
    public function withMarkedDays(array $days): self
    {
        return new self(
            year: $this->year,
            month: $this->month,
            startDayOfWeek: $this->startDayOfWeek,
            highlightDay: $this->highlightDay,
            markedDays: $days,
            headerColor: $this->headerColor,
            todayColor: $this->todayColor,
            markerColor: $this->markerColor,
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
            startDayOfWeek: $this->startDayOfWeek,
            highlightDay: $this->highlightDay,
            markedDays: $this->markedDays,
            headerColor: $color,
            todayColor: $this->todayColor,
            markerColor: $this->markerColor,
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
            startDayOfWeek: $this->startDayOfWeek,
            highlightDay: $this->highlightDay,
            markedDays: $this->markedDays,
            headerColor: $this->headerColor,
            todayColor: $color,
            markerColor: $this->markerColor,
        );
    }
}