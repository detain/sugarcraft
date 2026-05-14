<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A shadow effect that wraps any Item.
 *
 * Adds a drop shadow effect beneath rendered content, creating depth.
 * Supports:
 * - Different shadow styles (normal, heavy, none)
 * - Custom shadow color (default: dark gray)
 * - Shadow width/offset control
 * - Horizontal and vertical offset
 *
 * Mirrors shadow concepts from bubble tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Shadow implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const Normal = 'normal';
    public const Heavy = 'heavy';
    public const None = 'none';

    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item $content,
        private readonly string $style = self::Normal,
        private readonly ?Color $color = null,
        private readonly int $xOffset = 1,
        private readonly int $yOffset = 1,
    ) {}

    /**
     * Create a new shadow with default styling.
     *
     * Default: normal style, dark gray shadow, offset (1, 1).
     */
    public static function new(\SugarCraft\Dash\Foundation\Item $content): self
    {
        return new self(
            content: $content,
            style: self::Normal,
            color: Color::hex('#666666'),
            xOffset: 1,
            yOffset: 1,
        );
    }

    /**
     * Set the allocated dimensions for this shadow.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the content with shadow effect.
     */
    public function render(): string
    {
        // Calculate total output dimensions and content area
        $content = $this->renderContentAtSize();
        $lines = explode("\n", $content);
        $contentHeight = count($lines);
        $contentWidth = $this->calculateMaxWidth($lines);

        if ($contentWidth <= 0 || $contentHeight <= 0) {
            return $content;
        }

        if ($this->style === self::None) {
            return $content;
        }

        // Get shadow characters based on style
        $shadowChars = $this->getShadowChars();

        // Build the shadow effect using actual content dimensions
        $bottomShadow = str_repeat($shadowChars['corner'], $contentWidth);

        // Apply shadow color
        $colorCode = '';
        if ($this->color !== null) {
            $colorCode = $this->color->toFg(ColorProfile::TrueColor);
        }

        // Build output with shadow
        $result = [];

        // Add right-side shadow columns for each content line (xOffset columns)
        for ($i = 0; $i < $contentHeight; $i++) {
            $line = $lines[$i];
            $lineWidth = Width::string($line);

            // Pad line to contentWidth if needed
            if ($lineWidth < $contentWidth) {
                $line = $line . str_repeat(' ', $contentWidth - $lineWidth);
            } elseif ($lineWidth > $contentWidth) {
                $line = $this->truncateToWidth($line, $contentWidth);
            }

            // Add right shadow (xOffset columns)
            $rightShadow = str_repeat($shadowChars['vertical'], $this->xOffset);
            if ($colorCode !== '') {
                $result[] = $line . $colorCode . $rightShadow . Ansi::reset();
            } else {
                $result[] = $line . $rightShadow;
            }
        }

        // Add bottom shadow rows (yOffset rows) spanning contentWidth + xOffset
        $bottomShadowWidth = $contentWidth + $this->xOffset;
        $bottomShadowLine = str_repeat($shadowChars['corner'], $bottomShadowWidth);
        if ($colorCode !== '') {
            $bottomShadowLine = $colorCode . $bottomShadowLine . Ansi::reset();
        }
        for ($i = 0; $i < $this->yOffset; $i++) {
            $result[] = $bottomShadowLine;
        }

        return implode("\n", $result);
    }

    /**
     * Render the inner content, respecting setSize for content area.
     */
    private function renderContentAtSize(): string
    {
        // Calculate content area dimensions (total - shadow space)
        $totalW = $this->width ?? 0;
        $totalH = $this->height ?? 0;

        if ($totalW > 0 && $totalH > 0) {
            // setSize was called - calculate content area
            $innerW = $totalW - $this->xOffset - 1;
            $innerH = $totalH - $this->yOffset - 1;
            $innerW = max(1, $innerW);
            $innerH = max(1, $innerH);

            if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $sized = $this->content->setSize($innerW, $innerH);
                return $sized->render();
            }

            // For non-Sizer content, still need to size it
            $rendered = $this->content->render();
            return $this->adjustContentToSize($rendered, $innerW, $innerH);
        }

        // No setSize - render at natural size
        return $this->content->render();
    }

    /**
     * Adjust content to fit within given dimensions.
     */
    private function adjustContentToSize(string $content, int $width, int $height): string
    {
        $lines = explode("\n", $content);
        $adjusted = [];

        for ($i = 0; $i < $height && $i < count($lines); $i++) {
            $line = $lines[$i];
            $lineWidth = Width::string($line);

            if ($lineWidth < $width) {
                $line = $line . str_repeat(' ', $width - $lineWidth);
            } elseif ($lineWidth > $width) {
                $line = $this->truncateToWidth($line, $width);
            }
            $adjusted[] = $line;
        }

        // Pad with empty lines if needed
        while (count($adjusted) < $height) {
            $adjusted[] = str_repeat(' ', $width);
        }

        return implode("\n", array_slice($adjusted, 0, $height));
    }

    /**
     * Render the inner content at appropriate size.
     */
    private function renderContent(): string
    {
        if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
            $innerW = $this->width ?? 0;
            $innerH = $this->height ?? 0;
            if ($innerW > 0 && $innerH > 0) {
                $sized = $this->content->setSize($innerW, $innerH);
                return $sized->render();
            }
        }
        return $this->content->render();
    }

    /**
     * Calculate the maximum width of lines.
     */
    private function calculateMaxWidth(array $lines): int
    {
        $maxWidth = 0;
        foreach ($lines as $line) {
            $w = Width::string($line);
            if ($w > $maxWidth) {
                $maxWidth = $w;
            }
        }
        return $maxWidth;
    }

    /**
     * Get shadow characters based on style.
     *
     * @return array{horizontal: string, vertical: string, corner: string}
     */
    private function getShadowChars(): array
    {
        return match ($this->style) {
            self::Heavy => [
                'horizontal' => '▓',
                'vertical' => '▓',
                'corner' => '▓',
            ],
            self::Normal => [
                'horizontal' => '░',
                'vertical' => '░',
                'corner' => '░',
            ],
            default => [
                'horizontal' => ' ',
                'vertical' => ' ',
                'corner' => ' ',
            ],
        };
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
        return mb_substr($s, 0, max(1, $lo), 'UTF-8');
    }

    /**
     * Calculate the natural dimensions of this shadow component.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $content = $this->renderContentAtSize();
        $lines = explode("\n", $content);
        $contentH = count($lines);
        $contentW = $this->calculateMaxWidth($lines);

        // Calculate total dimensions (content + shadow)
        $totalW = $contentW + $this->xOffset + 1; // +1 for right shadow column
        $totalH = $contentH + $this->yOffset + 1; // +1 for bottom shadow row

        // If setSize was called, use those dimensions
        if ($this->width !== null && $this->height !== null) {
            return [$this->width, $this->height];
        }

        return [$totalW, $totalH];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the shadow style (normal, heavy, none).
     */
    public function withStyle(string $style): self
    {
        return new self(
            content: $this->content,
            style: $style,
            color: $this->color,
            xOffset: $this->xOffset,
            yOffset: $this->yOffset,
        );
    }

    /**
     * Set the shadow color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            style: $this->style,
            color: $color,
            xOffset: $this->xOffset,
            yOffset: $this->yOffset,
        );
    }

    /**
     * Set the horizontal offset (how far shadow extends right).
     */
    public function withXOffset(int $offset): self
    {
        return new self(
            content: $this->content,
            style: $this->style,
            color: $this->color,
            xOffset: max(0, $offset),
            yOffset: $this->yOffset,
        );
    }

    /**
     * Set the vertical offset (how far shadow extends down).
     */
    public function withYOffset(int $offset): self
    {
        return new self(
            content: $this->content,
            style: $this->style,
            color: $this->color,
            xOffset: $this->xOffset,
            yOffset: max(0, $offset),
        );
    }

    /**
     * Set both x and y offset.
     */
    public function withOffset(int $x, int $y): self
    {
        return new self(
            content: $this->content,
            style: $this->style,
            color: $this->color,
            xOffset: max(0, $x),
            yOffset: max(0, $y),
        );
    }

    /**
     * Set a heavy shadow style.
     */
    public function withHeavy(): self
    {
        return $this->withStyle(self::Heavy);
    }

    /**
     * Disable the shadow effect.
     */
    public function withNoShadow(): self
    {
        return $this->withStyle(self::None);
    }
}
