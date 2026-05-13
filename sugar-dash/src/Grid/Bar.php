<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A horizontal status bar component.
 *
 * Features:
 * - Displays text content in a single horizontal bar
 * - Configurable foreground/background colors
 * - Left/center/right section support via alignment
 * - Border characters to frame the bar
 *
 * Mirrors the bar concept from bubble-termbox/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Bar implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $content = '',
        private readonly ?Color $foreground = null,
        private readonly ?Color $background = null,
        private readonly HAlign $align = HAlign::Left,
        private readonly string $leftBorder = '',
        private readonly string $rightBorder = '',
    ) {}

    /**
     * Create a new bar with default styling.
     *
     * Default: purple foreground on dark background, left-aligned.
     */
    public static function new(string $content = ''): self
    {
        return new self(
            content: $content,
            foreground: Color::hex('#874BFD'),
            background: Color::hex('#1A1B26'),
            align: HAlign::Left,
            leftBorder: '',
            rightBorder: '',
        );
    }

    /**
     * Set the allocated dimensions for this bar.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the bar as a string.
     */
    public function render(): string
    {
        $width = $this->getWidth();

        if ($width <= 0) {
            return '';
        }

        $contentWidth = Width::string($this->content);
        $availableWidth = $width - Width::string($this->leftBorder) - Width::string($this->rightBorder);
        $availableWidth = max(0, $availableWidth);

        // Align content within available space
        $aligned = $this->alignContent($this->content, $availableWidth, $this->align);

        // Build the complete bar line
        $bar = $this->leftBorder . $aligned . $this->rightBorder;

        // Calculate total width after adding borders
        $totalWidth = Width::string($bar);

        // Pad with spaces if content + borders is less than requested width
        if ($totalWidth < $width) {
            $padding = $width - $totalWidth;
            // Extend the right side padding
            $bar .= str_repeat(' ', $padding);
        }

        // Apply colors if set
        $result = '';
        if ($this->foreground !== null || $this->background !== null) {
            if ($this->background !== null) {
                $result .= $this->background->toBg(ColorProfile::TrueColor);
            }
            if ($this->foreground !== null) {
                $result .= $this->foreground->toFg(ColorProfile::TrueColor);
            }
            $result .= $bar;
            $result .= Ansi::reset();
        } else {
            $result = $bar;
        }

        return $result;
    }

    /**
     * Get the width to use for the bar.
     */
    private function getWidth(): int
    {
        if ($this->width !== null) {
            // Explicit 0 means render nothing
            if ($this->width <= 0) {
                return 0;
            }
            return $this->width;
        }
        // If no explicit width set via setSize, auto-size to fit content + borders
        $contentWidth = Width::string($this->content);
        $borderWidth = Width::string($this->leftBorder) + Width::string($this->rightBorder);
        return max(1, $contentWidth + $borderWidth);
    }

    /**
     * Calculate the natural dimensions of this bar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->getWidth();
        // Bar is a single-line component
        return [$w > 0 ? $w : Width::string($this->content), 1];
    }

    /**
     * Align content within the given width.
     */
    private function alignContent(string $content, int $availableWidth, HAlign $align): string
    {
        $contentWidth = Width::string($content);

        if ($contentWidth >= $availableWidth) {
            // Truncate if too wide
            return $this->truncateToWidth($content, $availableWidth);
        }

        $padding = $availableWidth - $contentWidth;

        return match ($align) {
            HAlign::Left => $content . str_repeat(' ', $padding),
            HAlign::Right => str_repeat(' ', $padding) . $content,
            HAlign::Center => $this->centerAlign($content, $contentWidth, $availableWidth),
        };
    }

    /**
     * Center-align content within the given width.
     */
    private function centerAlign(string $content, int $contentWidth, int $width): string
    {
        $padding = $width - $contentWidth;
        $left = (int) floor($padding / 2);
        $right = $padding - $left;

        return str_repeat(' ', $left) . $content . str_repeat(' ', $right);
    }

    /**
     * Truncate a string to fit within the given width.
     */
    private function truncateToWidth(string $s, int $width): string
    {
        if ($width <= 0) {
            return '';
        }
        if (Width::string($s) <= $width) {
            return $s;
        }
        $lo = 0;
        $hi = mb_strlen($s, 'UTF-8');
        while ($lo < $hi) {
            $mid = (int) (($lo + $hi + 1) / 2);
            $candidate = mb_substr($s, 0, $mid, 'UTF-8');
            if (Width::string($candidate) <= $width) {
                $lo = $mid;
            } else {
                $hi = $mid - 1;
            }
        }
        if ($lo === 0) {
            return '';
        }
        return mb_substr($s, 0, $lo, 'UTF-8');
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the bar content text.
     */
    public function withContent(string $content): self
    {
        $clone = new self(
            content: $content,
            foreground: $this->foreground,
            background: $this->background,
            align: $this->align,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

    /**
     * Set the foreground color.
     */
    public function withForeground(?Color $color): self
    {
        $clone = new self(
            content: $this->content,
            foreground: $color,
            background: $this->background,
            align: $this->align,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

    public function withBackground(?Color $color): self
    {
        $clone = new self(
            content: $this->content,
            foreground: $this->foreground,
            background: $color,
            align: $this->align,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

    public function withAlign(HAlign $align): self
    {
        $clone = new self(
            content: $this->content,
            foreground: $this->foreground,
            background: $this->background,
            align: $align,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

    public function withBorders(string $left, string $right): self
    {
        $clone = new self(
            content: $this->content,
            foreground: $this->foreground,
            background: $this->background,
            align: $this->align,
            leftBorder: $left,
            rightBorder: $right,
        );
        if ($this->width !== null && $this->height !== null) {
            return $clone->setSize($this->width, $this->height);
        }
        return $clone;
    }

}
