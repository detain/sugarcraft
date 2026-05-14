<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A digital clock display component.
 *
 * Shows the current time in various formats (12h/24h).
 * Updates on each render call based on system time.
 *
 * Mirrors clock display concepts adapted to PHP with wither-style immutable setters.
 */
final class Clock implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    public function __construct(
        private readonly bool $use24Hour = false,
        private readonly bool $showSeconds = true,
        private readonly bool $showDate = false,
        private readonly ?Color $color = null,
    ) {}

    /**
     * Create a new clock with default styling (24-hour with seconds).
     */
    public static function new(): self
    {
        return new self(
            use24Hour: false,
            showSeconds: true,
            showDate: false,
            color: Color::hex('#874BFD'),
        );
    }

    /**
     * Create a 24-hour format clock.
     */
    public static function twentyFourHour(): self
    {
        return new self(
            use24Hour: true,
            showSeconds: true,
            showDate: false,
            color: Color::hex('#874BFD'),
        );
    }

    /**
     * Set the allocated dimensions for this clock.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the clock showing current time.
     */
    public function render(): string
    {
        $time = time();
        $hour = (int) date('H', $time);
        $minute = (int) date('i', $time);
        $second = (int) date('s', $time);

        if (!$this->use24Hour) {
            $period = $hour >= 12 ? 'PM' : 'AM';
            $hour = $hour % 12;
            if ($hour === 0) {
                $hour = 12;
            }
        } else {
            $period = '';
        }

        $timeStr = sprintf('%02d:%02d', $hour, $minute);
        if ($this->showSeconds) {
            $timeStr .= sprintf(':%02d', $second);
        }
        if ($period !== '') {
            $timeStr .= ' ' . $period;
        }

        if ($this->showDate) {
            $dateStr = date('D, M j');
            $timeStr .= '  ' . $dateStr;
        }

        if ($this->color !== null) {
            return $this->color->toFg(ColorProfile::TrueColor) . $timeStr . Ansi::reset();
        }

        return $timeStr;
    }

    /**
     * Calculate the natural dimensions of this clock.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Format: "12:59:59 PM" = 11 chars or "00:00:00" = 8 chars
        $width = $this->use24Hour ? ($this->showSeconds ? 8 : 5) : ($this->showSeconds ? 11 : 8);
        if ($this->showDate) {
            $width += 8; // "  Thu, May 12" = ~12 chars
        }
        return [$width, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Use 24-hour format instead of 12-hour.
     */
    public function with24Hour(bool $use24Hour): self
    {
        return new self(
            use24Hour: $use24Hour,
            showSeconds: $this->showSeconds,
            showDate: $this->showDate,
            color: $this->color,
        );
    }

    /**
     * Show or hide seconds.
     */
    public function withSeconds(bool $show): self
    {
        return new self(
            use24Hour: $this->use24Hour,
            showSeconds: $show,
            showDate: $this->showDate,
            color: $this->color,
        );
    }

    /**
     * Show or hide the date.
     */
    public function withDate(bool $show): self
    {
        return new self(
            use24Hour: $this->use24Hour,
            showSeconds: $this->showSeconds,
            showDate: $show,
            color: $this->color,
        );
    }

    /**
     * Set the clock color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            use24Hour: $this->use24Hour,
            showSeconds: $this->showSeconds,
            showDate: $this->showDate,
            color: $color,
        );
    }
}