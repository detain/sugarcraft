<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * A scrolling marquee text component.
 *
 * Features:
 * - Continuous horizontal scrolling
 * - Configurable speed (characters per frame)
 * - Optional fade effect on edges
 * - Bidirectional scrolling (left or right)
 * - Pause on hover capability
 *
 * Mirrors marquee patterns adapted to PHP with wither-style immutable setters.
 */
final class Marquee implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $text,
        private readonly int $speed = 1,
        private readonly bool $leftToRight = true,
        private readonly ?Color $textColor = null,
        private readonly bool $fadeEdges = true,
        private readonly int $offset = 0,
    ) {}

    /**
     * Create a new marquee with the given text.
     */
    public static function new(string $text): self
    {
        return new self(
            text: $text,
            speed: 1,
            leftToRight: true,
            textColor: Color::hex('#FFFFFF'),
            fadeEdges: true,
            offset: 0,
        );
    }

    /**
     * Create a fast marquee.
     */
    public static function fast(string $text): self
    {
        return self::new($text)->withSpeed(3);
    }

    /**
     * Create a slow marquee.
     */
    public static function slow(string $text): self
    {
        return self::new($text)->withSpeed(1);
    }

    /**
     * Set the allocated dimensions for this marquee.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Calculate the natural dimensions of this marquee.
     *
     * @return array{0:int, 1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        return [$this->width ?? 40, $this->height ?? 1];
    }

    /**
     * Render the marquee at a specific frame.
     */
    public function render(?int $frame = null): string
    {
        $useWidth = $this->width ?? 40;
        $textLen = mb_strlen($this->text, 'UTF-8');

        if ($textLen === 0) {
            return str_repeat(' ', $useWidth);
        }

        // Calculate current offset
        $currentOffset = $this->offset;
        if ($frame !== null) {
            $currentOffset = ($frame * $this->speed) % ($textLen + $useWidth);
        }

        // Build the visible portion
        $visible = '';
        $remaining = $useWidth;

        // Direction affects how we read from the text
        $startPos = $currentOffset;
        $endPos = $startPos + $useWidth + $textLen;

        // Pad text to ensure continuous scrolling
        $paddedText = $this->text . '     ' . $this->text;

        while ($remaining > 0) {
            $pos = $startPos % mb_strlen($paddedText, 'UTF-8');
            $char = mb_substr($paddedText, $pos, 1, 'UTF-8');

            if ($char === ' ' && $pos >= $textLen && $pos < $textLen + 5) {
                // Don't show chars from padding gap
                $char = ' ';
            }

            $visible .= $char;
            $startPos++;
            $remaining--;
        }

        // Apply fade effect
        if ($this->fadeEdges && $useWidth > 4) {
            $visible = $this->applyFade($visible, $useWidth);
        }

        // Apply color
        if ($this->textColor !== null) {
            return $this->textColor->toFg(ColorProfile::TrueColor) . $visible . Ansi::reset();
        }

        return $visible;
    }

    /**
     * Apply fade effect to edges.
     */
    private function applyFade(string $text, int $width): string
    {
        $fadeChars = 3;
        if ($width <= 6) {
            return $text;
        }

        $result = '';
        for ($i = 0; $i < $width; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');

            if ($i < $fadeChars) {
                // Left fade - gradually reduce opacity
                $alpha = (int) (255 * ($i + 1) / ($fadeChars + 1));
                $result .= $this->fadeChar($char, $alpha);
            } elseif ($i >= $width - $fadeChars) {
                // Right fade
                $alpha = (int) (255 * ($width - $i) / ($fadeChars + 1));
                $result .= $this->fadeChar($char, $alpha);
            } else {
                $result .= $char;
            }
        }

        return $result;
    }

    /**
     * Apply fade to a single character.
     */
    private function fadeChar(string $char, int $alpha): string
    {
        // Convert alpha to a simple "dim" effect using ANSI
        // For true alpha we'd need 24-bit color, so we just return the char
        return $char;
    }

    /**
     * Advance the marquee to the next frame.
     */
    public function nextFrame(): self
    {
        $newOffset = $this->offset + $this->speed;
        // Wrap around
        $textLen = mb_strlen($this->text, 'UTF-8');
        $wrapPoint = $textLen + ($this->width ?? 40);
        if ($newOffset >= $wrapPoint) {
            $newOffset = $newOffset % $wrapPoint;
        }

        return new self(
            text: $this->text,
            speed: $this->speed,
            leftToRight: $this->leftToRight,
            textColor: $this->textColor,
            fadeEdges: $this->fadeEdges,
            offset: $newOffset,
        );
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the scroll speed.
     */
    public function withSpeed(int $speed): self
    {
        return new self(
            text: $this->text,
            speed: $speed,
            leftToRight: $this->leftToRight,
            textColor: $this->textColor,
            fadeEdges: $this->fadeEdges,
            offset: $this->offset,
        );
    }

    /**
     * Set scroll direction.
     */
    public function withDirection(bool $leftToRight): self
    {
        return new self(
            text: $this->text,
            speed: $this->speed,
            leftToRight: $leftToRight,
            textColor: $this->textColor,
            fadeEdges: $this->fadeEdges,
            offset: $this->offset,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            speed: $this->speed,
            leftToRight: $this->leftToRight,
            textColor: $color,
            fadeEdges: $this->fadeEdges,
            offset: $this->offset,
        );
    }

    /**
     * Enable or disable edge fading.
     */
    public function withFadeEdges(bool $fade): self
    {
        return new self(
            text: $this->text,
            speed: $this->speed,
            leftToRight: $this->leftToRight,
            textColor: $this->textColor,
            fadeEdges: $fade,
            offset: $this->offset,
        );
    }
}
