<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * An animated loading text component with cycling dots.
 *
 * Features:
 * - Customizable base text with animated suffix
 * - Multiple animation styles (dots, bouncing, pulsing)
 * - Custom colors and animation speed
 * - Shows cycling animation frames via tick-based rendering
 *
 * Mirrors loading text concepts adapted to PHP with wither-style immutable setters.
 */
final class LoadingText implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const Dots = 'dots';       // "Loading..."
    public const Bouncing = 'bouncing'; // "Loading ●"
    public const Pulsing = 'pulsing';   // "Loading*"
    public const Arrows = 'arrows';   // "Loading < >"

    public function __construct(
        private readonly string $text = 'Loading',
        private readonly string $style = self::Dots,
        private readonly ?Color $color = null,
        private readonly int $interval = 300,
    ) {}

    /**
     * Create a new loading text with default styling.
     */
    public static function new(string $text = 'Loading'): self
    {
        return new self(
            text: $text,
            style: self::Dots,
            color: Color::hex('#874BFD'),
            interval: 300,
        );
    }

    /**
     * Set the allocated dimensions for this loading text.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the loading text at the current frame.
     */
    public function render(): string
    {
        $frame = $this->getCurrentFrame();
        $output = $this->text . $frame;

        if ($this->color !== null) {
            return $this->color->toFg(ColorProfile::TrueColor) . $output . Ansi::reset();
        }

        return $output;
    }

    /**
     * Get the current animation frame based on time.
     */
    public function getCurrentFrame(): string
    {
        $tick = (int) (microtime(true) * 1000 / $this->interval);

        return match ($this->style) {
            self::Dots => $this->getDotsFrame($tick),
            self::Bouncing => $this->getBouncingFrame($tick),
            self::Pulsing => $this->getPulsingFrame($tick),
            self::Arrows => $this->getArrowsFrame($tick),
            default => $this->getDotsFrame($tick),
        };
    }

    /**
     * Get dots animation frame.
     */
    private function getDotsFrame(int $tick): string
    {
        $numDots = ($tick % 4);
        return str_repeat('.', $numDots);
    }

    /**
     * Get bouncing animation frame.
     */
    private function getBouncingFrame(int $tick): string
    {
        $positions = ['●', '○', '●', '○'];
        $index = $tick % count($positions);
        return ' ' . $positions[$index];
    }

    /**
     * Get pulsing animation frame.
     */
    private function getPulsingFrame(int $tick): string
    {
        $phase = $tick % 2;
        return $phase === 0 ? '*' : '';
    }

    /**
     * Get arrows animation frame.
     */
    private function getArrowsFrame(int $tick): string
    {
        $frames = [' < ', '   ', ' > ', '   '];
        return $frames[$tick % count($frames)];
    }

    /**
     * Get the frame at a specific index (for deterministic testing).
     */
    public function getFrameAt(int $index): string
    {
        $tick = $index;

        return match ($this->style) {
            self::Dots => $this->getDotsFrame($tick),
            self::Bouncing => $this->getBouncingFrame($tick),
            self::Pulsing => $this->getPulsingFrame($tick),
            self::Arrows => $this->getArrowsFrame($tick),
            default => $this->getDotsFrame($tick),
        };
    }

    /**
     * Calculate the natural dimensions of this loading text.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $textWidth = Width::string($this->text);
        $frameWidth = $this->getMaxFrameWidth();
        $totalWidth = $textWidth + $frameWidth;

        return [$totalWidth, 1];
    }

    /**
     * Get the maximum width of any animation frame.
     */
    private function getMaxFrameWidth(): int
    {
        return match ($this->style) {
            self::Dots => 3,    // "..." is max
            self::Bouncing => 2, // " ●" is max
            self::Pulsing => 1,  // "*"
            self::Arrows => 3,  // " > "
            default => 3,
        };
    }

    /**
     * Get the animation style.
     */
    public function getStyle(): string
    {
        return $this->style;
    }

    /**
     * Get the animation interval in milliseconds.
     */
    public function getInterval(): int
    {
        return $this->interval;
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the loading text.
     */
    public function withText(string $text): self
    {
        return new self(
            text: $text,
            style: $this->style,
            color: $this->color,
            interval: $this->interval,
        );
    }

    /**
     * Set the animation style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            text: $this->text,
            style: $style,
            color: $this->color,
            interval: $this->interval,
        );
    }

    /**
     * Set the text color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            style: $this->style,
            color: $color,
            interval: $this->interval,
        );
    }

    /**
     * Set the animation interval in milliseconds.
     *
     * Lower values = faster animation.
     */
    public function withInterval(int $interval): self
    {
        return new self(
            text: $this->text,
            style: $this->style,
            color: $this->color,
            interval: max(1, $interval),
        );
    }
}
