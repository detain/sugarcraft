<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Sprinkles\Style;
use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A loading spinner component that displays cycling animation frames.
 *
 * Renders one frame at a time - call tick() to advance the animation.
 * Supports custom frames, colors, and an optional accompanying message.
 *
 * Mirrors the spinner concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Spinner implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * Default spinner frames (classic line spinner).
     */
    private const DEFAULT_FRAMES = ['|', '/', '-', '\\'];

    public function __construct(
        private readonly array $frames = self::DEFAULT_FRAMES,
        private readonly int $interval = 80,
        private readonly ?Color $color = null,
        private readonly string $message = '',
    ) {}

    /**
     * Create a new spinner with default styling.
     */
    public static function new(): self
    {
        return new self(
            frames: self::DEFAULT_FRAMES,
            interval: 80,
            color: Color::hex('#874BFD'),
            message: '',
        );
    }

    /**
     * Set the allocated dimensions for this spinner.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the spinner at the current frame.
     */
    public function render(): string
    {
        $frame = $this->getCurrentFrame();

        if ($this->color !== null) {
            return $this->color->toFg(ColorProfile::TrueColor) . $frame . Ansi::reset();
        }

        return $frame;
    }

    /**
     * Get the current frame character.
     */
    public function getCurrentFrame(): string
    {
        if ($this->frames === []) {
            return '';
        }

        // Simple tick-based frame selection using interval as divisor
        // Lower interval = faster spin = higher tick rate
        // We use a pseudo-random-ish index based on interval to allow
        // different spinners to appear out of phase
        $tick = (int) (microtime(true) * 1000 / $this->interval);
        $index = $tick % count($this->frames);

        return $this->frames[$index];
    }

    /**
     * Get the frame at a specific index (for testing/ deterministic output).
     */
    public function getFrameAt(int $index): string
    {
        if ($this->frames === []) {
            return '';
        }

        $index = $index % count($this->frames);
        if ($index < 0) {
            $index += count($this->frames);
        }

        return $this->frames[$index];
    }

    /**
     * Calculate the natural dimensions of this spinner.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $messageWidth = mb_strlen($this->message, 'UTF-8');
        $frameWidth = $this->frames !== [] ? 1 : 0;
        $totalWidth = $frameWidth + ($messageWidth > 0 ? $messageWidth + 1 : 0);

        return [$totalWidth, 1];
    }

    /**
     * Get the number of frames in the spinner.
     */
    public function getFrameCount(): int
    {
        return count($this->frames);
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set custom spinner frames.
     *
     * @param list<string> $frames Array of frame characters
     */
    public function withFrames(array $frames): self
    {
        return new self(
            frames: $frames,
            interval: $this->interval,
            color: $this->color,
            message: $this->message,
        );
    }

    /**
     * Set the spin interval in milliseconds.
     *
     * Lower values = faster spinning.
     */
    public function withInterval(int $interval): self
    {
        return new self(
            frames: $this->frames,
            interval: max(1, $interval),
            color: $this->color,
            message: $this->message,
        );
    }

    /**
     * Set the spinner color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            frames: $this->frames,
            interval: $this->interval,
            color: $color,
            message: $this->message,
        );
    }

    /**
     * Set the message displayed alongside the spinner.
     */
    public function withMessage(string $message): self
    {
        return new self(
            frames: $this->frames,
            interval: $this->interval,
            color: $this->color,
            message: $message,
        );
    }
}
