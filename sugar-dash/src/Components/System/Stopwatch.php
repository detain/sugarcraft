<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\System;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A stopwatch component.
 *
 * Displays elapsed time in HH:MM:SS.ms format.
 * Does not run automatically - use withElapsed() to update.
 *
 * Mirrors stopwatch concepts adapted to PHP with wither-style immutable setters.
 */
final class Stopwatch implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $sizerHeight = null;

    public function __construct(
        private readonly int $elapsedMilliseconds = 0,
        private readonly bool $showMilliseconds = true,
        private readonly bool $isRunning = false,
        private readonly ?Color $color = null,
    ) {}

    /**
     * Create a new stopped stopwatch at zero.
     */
    public static function new(): self
    {
        return new self(
            elapsedMilliseconds: 0,
            showMilliseconds: true,
            isRunning: false,
            color: Color::hex('#874BFD'),
        );
    }

    /**
     * Create a running stopwatch.
     */
    public static function start(): self
    {
        return new self(
            elapsedMilliseconds: 0,
            showMilliseconds: true,
            isRunning: true,
            color: Color::hex('#874BFD'),
        );
    }

    /**
     * Set the allocated dimensions for this stopwatch.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the stopwatch display.
     */
    public function render(): string
    {
        $totalSeconds = (int) floor($this->elapsedMilliseconds / 1000);
        $milliseconds = $this->elapsedMilliseconds % 1000;
        $hours = (int) floor($totalSeconds / 3600);
        $minutes = (int) floor(($totalSeconds % 3600) / 60);
        $seconds = $totalSeconds % 60;

        $timeStr;
        if ($hours > 0) {
            if ($this->showMilliseconds) {
                $timeStr = sprintf('%d:%02d:%02d.%02d', $hours, $minutes, $seconds, (int) ($milliseconds / 10));
            } else {
                $timeStr = sprintf('%d:%02d:%02d', $hours, $minutes, $seconds);
            }
        } elseif ($this->showMilliseconds) {
            $timeStr = sprintf('%02d:%02d.%02d', $minutes, $seconds, (int) ($milliseconds / 10));
        } else {
            $timeStr = sprintf('%02d:%02d', $minutes, $seconds);
        }

        if ($this->isRunning) {
            $timeStr .= ' ▶';
        }

        if ($this->color !== null) {
            return $this->color->toFg(ColorProfile::TrueColor) . $timeStr . Ansi::reset();
        }

        return $timeStr;
    }

    /**
     * Calculate the natural dimensions of this stopwatch.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // Format: "00:00.00" = 8 or "00:00.00 ▶" = 10
        $width = $this->showMilliseconds ? 8 : 5;
        if ($this->isRunning) {
            $width += 2;
        }
        return [$width, 1];
    }

    /**
     * Get the elapsed time in seconds.
     */
    public function getElapsedSeconds(): int
    {
        return (int) floor($this->elapsedMilliseconds / 1000);
    }

    /**
     * Get the elapsed milliseconds.
     */
    public function getElapsedMilliseconds(): int
    {
        return $this->elapsedMilliseconds;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the elapsed time in milliseconds.
     */
    public function withElapsed(int $milliseconds): self
    {
        return new self(
            elapsedMilliseconds: max(0, $milliseconds),
            showMilliseconds: $this->showMilliseconds,
            isRunning: $this->isRunning,
            color: $this->color,
        );
    }

    /**
     * Set the running state.
     */
    public function withRunning(bool $isRunning): self
    {
        return new self(
            elapsedMilliseconds: $this->elapsedMilliseconds,
            showMilliseconds: $this->showMilliseconds,
            isRunning: $isRunning,
            color: $this->color,
        );
    }

    /**
     * Show or hide milliseconds.
     */
    public function withMilliseconds(bool $show): self
    {
        return new self(
            elapsedMilliseconds: $this->elapsedMilliseconds,
            showMilliseconds: $show,
            isRunning: $this->isRunning,
            color: $this->color,
        );
    }

    /**
     * Set the stopwatch color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            elapsedMilliseconds: $this->elapsedMilliseconds,
            showMilliseconds: $this->showMilliseconds,
            isRunning: $this->isRunning,
            color: $color,
        );
    }
}