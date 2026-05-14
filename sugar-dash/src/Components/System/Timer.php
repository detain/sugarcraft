<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A countdown timer component.
 *
 * Displays remaining time in HH:MM:SS format.
 * The timer runs down from the specified duration.
 *
 * Mirrors timer concepts adapted to PHP with wither-style immutable setters.
 */
final class Timer implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    public function __construct(
        private readonly int $totalSeconds,
        private readonly int $elapsedSeconds = 0,
        private readonly bool $isRunning = false,
        private readonly ?Color $color = null,
        private readonly ?Color $warningColor = null,
        private readonly int $warningThreshold = 60,
    ) {}

    /**
     * Create a new timer with the specified duration.
     *
     * @param int $seconds Total duration in seconds
     */
    public static function new(int $seconds): self
    {
        return new self(
            totalSeconds: max(0, $seconds),
            elapsedSeconds: 0,
            isRunning: false,
            color: Color::hex('#874BFD'),
            warningColor: Color::hex('#FD874B'),
            warningThreshold: 60,
        );
    }

    /**
     * Create a timer from minutes.
     */
    public static function fromMinutes(int $minutes): self
    {
        return self::new($minutes * 60);
    }

    /**
     * Set the allocated dimensions for this timer.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the timer display.
     */
    public function render(): string
    {
        $remaining = max(0, $this->totalSeconds - $this->elapsedSeconds);
        $hours = (int) floor($remaining / 3600);
        $minutes = (int) floor(($remaining % 3600) / 60);
        $seconds = $remaining % 60;

        $timeStr;
        if ($hours > 0) {
            $timeStr = sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
        } else {
            $timeStr = sprintf('%02d:%02d', $minutes, $seconds);
        }

        // Choose color based on remaining time
        $color = $this->color;
        if ($this->warningColor !== null && $remaining <= $this->warningThreshold && $remaining > 0) {
            $color = $this->warningColor;
        }
        if ($remaining === 0) {
            $color = $this->warningColor ?? $this->color;
        }

        if ($color !== null) {
            return $color->toFg(ColorProfile::TrueColor) . $timeStr . Ansi::reset();
        }

        return $timeStr;
    }

    /**
     * Calculate the natural dimensions of this timer.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Format: "59:59" or "1:59:59" = max 8 chars
        return [$this->totalSeconds >= 3600 ? 8 : 5, 1];
    }

    /**
     * Get the remaining seconds.
     */
    public function getRemainingSeconds(): int
    {
        return max(0, $this->totalSeconds - $this->elapsedSeconds);
    }

    /**
     * Check if timer has expired.
     */
    public function isExpired(): bool
    {
        return $this->elapsedSeconds >= $this->totalSeconds;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the elapsed time (for ticking).
     */
    public function withElapsed(int $seconds): self
    {
        return new self(
            totalSeconds: $this->totalSeconds,
            elapsedSeconds: max(0, $seconds),
            isRunning: $this->isRunning,
            color: $this->color,
            warningColor: $this->warningColor,
            warningThreshold: $this->warningThreshold,
        );
    }

    /**
     * Set the running state.
     */
    public function withRunning(bool $isRunning): self
    {
        return new self(
            totalSeconds: $this->totalSeconds,
            elapsedSeconds: $this->elapsedSeconds,
            isRunning: $isRunning,
            color: $this->color,
            warningColor: $this->warningColor,
            warningThreshold: $this->warningThreshold,
        );
    }

    /**
     * Set the timer color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            totalSeconds: $this->totalSeconds,
            elapsedSeconds: $this->elapsedSeconds,
            isRunning: $this->isRunning,
            color: $color,
            warningColor: $this->warningColor,
            warningThreshold: $this->warningThreshold,
        );
    }

    /**
     * Set the warning color (used when time is running low).
     */
    public function withWarningColor(?Color $color): self
    {
        return new self(
            totalSeconds: $this->totalSeconds,
            elapsedSeconds: $this->elapsedSeconds,
            isRunning: $this->isRunning,
            color: $this->color,
            warningColor: $color,
            warningThreshold: $this->warningThreshold,
        );
    }

    /**
     * Set the warning threshold in seconds.
     */
    public function withWarningThreshold(int $seconds): self
    {
        return new self(
            totalSeconds: $this->totalSeconds,
            elapsedSeconds: $this->elapsedSeconds,
            isRunning: $this->isRunning,
            color: $this->color,
            warningColor: $this->warningColor,
            warningThreshold: max(0, $seconds),
        );
    }
}