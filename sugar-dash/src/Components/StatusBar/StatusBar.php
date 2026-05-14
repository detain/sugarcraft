<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\StatusBar;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

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

        // Calculate segment widths
        $leftWidth = Width::string($this->left);
        $centerWidth = Width::string($this->center);
        $rightWidth = Width::string($this->right);
        $totalContentWidth = $leftWidth + $centerWidth + $rightWidth;

        // Build the content with proper spacing
        $content = $this->buildContent($this->left, $this->center, $this->right, $availableWidth);

        // Build the complete bar
        $bar = $this->leftBorder . $content . $this->rightBorder;

        // Calculate total width and pad if needed
        $totalWidth = Width::string($bar);
        if ($totalWidth < $width) {
            $bar .= str_repeat(' ', $width - $totalWidth);
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
     * Build the content string with proper segment spacing.
     */
    private function buildContent(string $left, string $center, string $right, int $availableWidth): string
    {
        $leftWidth = Width::string($left);
        $centerWidth = Width::string($center);
        $rightWidth = Width::string($right);
        $totalContentWidth = $leftWidth + $centerWidth + $rightWidth;

        // If content fits, distribute remaining space
        if ($totalContentWidth <= $availableWidth) {
            $remaining = $availableWidth - $totalContentWidth;

            // Distribute remaining space: left gets 0, center gets most, right gets 0
            $leftPad = 0;
            $centerPad = $remaining;
            $rightPad = 0;

            return $left . str_repeat(' ', $leftPad)
                . str_repeat(' ', (int) floor($centerPad / 2)) . $center . str_repeat(' ', (int) ceil($centerPad / 2))
                . str_repeat(' ', $rightPad) . $right;
        }

        // Content doesn't fit - need to truncate
        // Priority: center > left > right
        $minCenterWidth = 1;
        $minLeftWidth = 1;
        $minRightWidth = 1;
        $minTotal = $minCenterWidth + $minLeftWidth + $minRightWidth;

        if ($availableWidth < $minTotal) {
            // Not enough space for minimum - just show what fits
            return mb_substr($left, 0, $availableWidth, 'UTF-8');
        }

        // First allocate minimum widths
        $leftAlloc = $minLeftWidth;
        $centerAlloc = $minCenterWidth;
        $rightAlloc = $minRightWidth;

        // Remaining space to distribute
        $remaining = $availableWidth - $minTotal;

        // Try to fit each segment proportionally
        if ($totalContentWidth > 0) {
            $leftAlloc = min($leftWidth, (int) floor($remaining * ($leftWidth / $totalContentWidth)) + $minLeftWidth);
            $remaining -= ($leftAlloc - $minLeftWidth);

            $centerAlloc = min($centerWidth, (int) floor($remaining * ($centerWidth / $totalContentWidth)) + $minCenterWidth);
            $remaining -= ($centerAlloc - $minCenterWidth);

            $rightAlloc = min($rightWidth, $remaining + $minRightWidth);
        }

        // Truncate each segment to its allocated width
        $leftTruncated = $this->truncateToWidth($left, $leftAlloc);
        $centerTruncated = $this->truncateToWidth($center, $centerAlloc);
        $rightTruncated = $this->truncateToWidth($right, $rightAlloc);

        // Recalculate actual widths
        $actualLeftWidth = Width::string($leftTruncated);
        $actualCenterWidth = Width::string($centerTruncated);
        $actualRightWidth = Width::string($rightTruncated);

        // Fill remaining space with padding between segments
        $usedWidth = $actualLeftWidth + $actualCenterWidth + $actualRightWidth;
        $padding = $availableWidth - $usedWidth;

        // Distribute padding: left side gets floor(padding/2), right side gets ceil(padding/2)
        $leftPadding = (int) floor($padding / 2);
        $rightPadding = $padding - $leftPadding;

        return $leftTruncated
            . str_repeat(' ', $leftPadding)
            . $centerTruncated
            . str_repeat(' ', $rightPadding)
            . $rightTruncated;
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
