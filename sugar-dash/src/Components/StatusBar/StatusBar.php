<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\StatusBar;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Sprinkles\Bar\StatusBar as SprinklesStatusBar;

/**
 * A horizontal status bar with multiple segments.
 *
 * Features:
 * - Left, center, and right segment support
 * - Configurable foreground/background colors
 * - Border characters to frame the bar
 * - Automatic width allocation across segments
 *
 * Mirrors the statusbar concept from bubble-tea but adapted
 * to PHP with wither-style immutable setters.
 *
 * The three-column layout — segment positioning, gap-fill and (ANSI-aware)
 * truncation, plus the left/right border caps — is delegated to the shared
 * {@see SprinklesStatusBar} primitive. This class stays a thin themed
 * wrapper that adds fg/bg colours, {@see \SugarCraft\Dash\Foundation\Sizer}
 * sizing and the plain-string withers on top.
 */
final class StatusBar implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $left = '',
        private readonly string $center = '',
        private readonly string $right = '',
        private readonly ?Color $foreground = null,
        private readonly ?Color $background = null,
        private readonly string $leftBorder = '',
        private readonly string $rightBorder = '',
    ) {}

    /**
     * Create a new status bar with default styling.
     *
     * Default: purple foreground on dark background.
     */
    public static function new(): self
    {
        return new self(
            left: '',
            center: '',
            right: '',
            foreground: Color::hex('#874BFD'),
            background: Color::hex('#1A1B26'),
            leftBorder: '',
            rightBorder: '',
        );
    }

    /**
     * Set the allocated dimensions for this status bar.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the status bar as a string.
     */
    public function render(): string
    {
        $width = $this->getWidth();

        if ($width <= 0) {
            return '';
        }

        $borderWidth = Width::string($this->leftBorder) + Width::string($this->rightBorder);
        $availableWidth = max(0, $width - $borderWidth);

        if ($availableWidth <= 0) {
            return '';
        }

        // Delegate the three-column layout (fill + ANSI-aware truncation) and
        // the border caps to the shared status-bar primitive. Empty segments
        // collapse to nothing there, matching the previous concatenation.
        $bar = SprinklesStatusBar::new()
            ->left($this->left)
            ->center($this->center)
            ->right($this->right)
            ->caps($this->leftBorder, $this->rightBorder)
            ->width($width)
            ->render();

        // The primitive already fills to $width; this only pads back the
        // pathological case where the caps alone exceed the target width.
        $totalWidth = Width::string($bar);
        if ($totalWidth < $width) {
            $bar .= str_repeat(' ', $width - $totalWidth);
        }

        // Apply colors if set (byte layout unchanged: bg, fg, bar, reset).
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
     * Get the width to use for the status bar.
     */
    private function getWidth(): int
    {
        if ($this->width !== null) {
            if ($this->width <= 0) {
                return 0;
            }
            return $this->width;
        }
        // Auto-size to fit all content
        $contentWidth = Width::string($this->left) + Width::string($this->center) + Width::string($this->right);
        $borderWidth = Width::string($this->leftBorder) + Width::string($this->rightBorder);
        return max(1, $contentWidth + $borderWidth);
    }

    /**
     * Calculate the natural dimensions of this status bar.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->getWidth();
        return [$w > 0 ? $w : 1, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the left segment content.
     */
    public function withLeft(string $left): self
    {
        return new self(
            left: $left,
            center: $this->center,
            right: $this->right,
            foreground: $this->foreground,
            background: $this->background,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
    }

    /**
     * Set the center segment content.
     */
    public function withCenter(string $center): self
    {
        return new self(
            left: $this->left,
            center: $center,
            right: $this->right,
            foreground: $this->foreground,
            background: $this->background,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
    }

    /**
     * Set the right segment content.
     */
    public function withRight(string $right): self
    {
        return new self(
            left: $this->left,
            center: $this->center,
            right: $right,
            foreground: $this->foreground,
            background: $this->background,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
    }

    /**
     * Set all segment contents at once.
     */
    public function withSegments(string $left, string $center, string $right): self
    {
        return new self(
            left: $left,
            center: $center,
            right: $right,
            foreground: $this->foreground,
            background: $this->background,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
    }

    /**
     * Set the foreground color.
     */
    public function withForeground(?Color $color): self
    {
        return new self(
            left: $this->left,
            center: $this->center,
            right: $this->right,
            foreground: $color,
            background: $this->background,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackground(?Color $color): self
    {
        return new self(
            left: $this->left,
            center: $this->center,
            right: $this->right,
            foreground: $this->foreground,
            background: $color,
            leftBorder: $this->leftBorder,
            rightBorder: $this->rightBorder,
        );
    }

    /**
     * Set border characters for left and right sides.
     */
    public function withBorders(string $left, string $right): self
    {
        return new self(
            left: $this->left,
            center: $this->center,
            right: $this->right,
            foreground: $this->foreground,
            background: $this->background,
            leftBorder: $left,
            rightBorder: $right,
        );
    }
}
