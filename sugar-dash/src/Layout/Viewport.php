<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A scrollable viewport for content.
 *
 * Features:
 * - Horizontal and vertical scrolling
 * - Configurable viewport dimensions
 * - Supports any Item content
 * - Scroll position tracking
 *
 * Mirrors the viewport concept from bubble-viewport but adapted
 * to PHP with wither-style immutable setters.
 */
final class Viewport implements \SugarCraft\Dash\Foundation\Sizer
{
    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item $content,
        private readonly int $scrollX = 0,
        private readonly int $scrollY = 0,
        private readonly ?Color $background = null,
        private readonly ?int $width = null,
        private readonly ?int $height = null,
    ) {}

    /**
     * Create a new viewport with default styling.
     *
     * Default: no background color, scroll position 0,0.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            scrollX: 0,
            scrollY: 0,
            background: null,
            width: null,
            height: null,
        );
    }

    /**
     * Set the allocated dimensions for this viewport.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        return new self(
            content: $this->content,
            scrollX: $this->scrollX,
            scrollY: $this->scrollY,
            background: $this->background,
            width: $width,
            height: $height,
        );
    }

    /**
     * Render the viewport as a string (multi-line).
     */
    public function render(): string
    {
        $width = $this->getWidth();
        $height = $this->getHeight();

        if ($width <= 0 || $height <= 0) {
            return '';
        }

        // Get content dimensions
        [$contentWidth, $contentHeight] = $this->content->getInnerSize();

        // Clamp scroll position
        $maxScrollX = max(0, $contentWidth - $width);
        $maxScrollY = max(0, $contentHeight - $height);
        $scrollX = max(0, min($this->scrollX, $maxScrollX));
        $scrollY = max(0, min($this->scrollY, $maxScrollY));

        // Set size on content and render it
        $sizedContent = $this->content;
        if ($sizedContent instanceof \SugarCraft\Dash\Foundation\Sizer) {
            $sizedContent = $sizedContent->setSize($contentWidth, $contentHeight);
        }
        $fullContent = $sizedContent->render();

        // Split into lines
        $lines = explode("\n", $fullContent);

        // Extract visible lines based on scrollY
        $visibleLines = array_slice($lines, $scrollY, $height);

        // Extract visible portion of each line based on scrollX
        $result = '';
        foreach ($visibleLines as $line) {
            if ($scrollX > 0 && Width::string($line) > $scrollX) {
                $line = $this->substringByWidth($line, $scrollX, $width);
            } elseif (Width::string($line) <= $scrollX) {
                $line = '';
            }

            // Apply background color if set
            if ($this->background !== null) {
                $lineContent = $this->background->toBg(ColorProfile::TrueColor) . $line . Ansi::reset();
            } else {
                $lineContent = $line;
            }

            // Pad or truncate line to viewport width
            $lineWidth = Width::string($line);
            if ($lineWidth < $width) {
                $lineContent .= str_repeat(' ', $width - $lineWidth);
            } elseif ($lineWidth > $width) {
                $lineContent = $this->truncateToWidth($lineContent, $width);
            }

            $result .= $lineContent . "\n";
        }

        // Pad with empty lines if needed
        while (count($visibleLines) < $height) {
            $emptyLine = $this->background !== null
                ? $this->background->toBg(ColorProfile::TrueColor) . str_repeat(' ', $width) . Ansi::reset()
                : str_repeat(' ', $width);
            $result .= $emptyLine . "\n";
            $visibleLines[] = '';
        }

        return rtrim($result, "\n");
    }

    /**
     * Get the width of the viewport.
     */
    private function getWidth(): int
    {
        return $this->width ?? 0;
    }

    /**
     * Get the height of the viewport.
     */
    private function getHeight(): int
    {
        return $this->height ?? 0;
    }

    /**
     * Calculate the natural dimensions of this viewport.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        return [
            $this->getWidth() > 0 ? $this->getWidth() : 0,
            $this->getHeight() > 0 ? $this->getHeight() : 0,
        ];
    }

    /**
     * Extract a substring from a string by visual width.
     */
    private function substringByWidth(string $s, int $offset, int $width): string
    {
        // Skip the first offset characters by finding the starting point
        if ($offset <= 0) {
            return $this->truncateToWidth($s, $width);
        }

        // Find the character index that corresponds to the offset
        $lo = 0;
        $hi = mb_strlen($s, 'UTF-8');
        $currentWidth = 0;
        $startIndex = 0;

        for ($i = 0; $i < $hi; $i++) {
            $char = mb_substr($s, $i, 1, 'UTF-8');
            $charWidth = Width::string($char);

            if ($currentWidth + $charWidth > $offset) {
                $startIndex = $i;
                break;
            }
            $currentWidth += $charWidth;
        }

        // Extract from startIndex with given width
        $remaining = mb_substr($s, $startIndex, null, 'UTF-8');
        return $this->truncateToWidth($remaining, $width);
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
     * Set the scroll position (X coordinate).
     */
    public function withScrollX(int $scrollX): self
    {
        return new self(
            content: $this->content,
            scrollX: $scrollX,
            scrollY: $this->scrollY,
            background: $this->background,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the scroll position (Y coordinate).
     */
    public function withScrollY(int $scrollY): self
    {
        return new self(
            content: $this->content,
            scrollX: $this->scrollX,
            scrollY: $scrollY,
            background: $this->background,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set both scroll coordinates.
     */
    public function withScroll(int $scrollX, int $scrollY): self
    {
        return new self(
            content: $this->content,
            scrollX: $scrollX,
            scrollY: $scrollY,
            background: $this->background,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackground(?Color $color): self
    {
        return new self(
            content: $this->content,
            scrollX: $this->scrollX,
            scrollY: $this->scrollY,
            background: $color,
            width: $this->width,
            height: $this->height,
        );
    }

    /**
     * Scroll the viewport by the given delta.
     */
    public function scrollBy(int $deltaX, int $deltaY): self
    {
        return $this->withScroll(
            $this->scrollX + $deltaX,
            $this->scrollY + $deltaY,
        );
    }

    /**
     * Check if scrolling is possible in any direction.
     */
    public function canScroll(): bool
    {
        [$contentWidth, $contentHeight] = $this->content->getInnerSize();
        $viewportWidth = $this->getWidth();
        $viewportHeight = $this->getHeight();

        return $contentWidth > $viewportWidth || $contentHeight > $viewportHeight;
    }
}
