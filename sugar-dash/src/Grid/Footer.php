<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A page footer component.
 *
 * Features:
 * - Horizontal or vertical layout
 * - Optional border top or bottom
 * - Text content with left, center, or right alignment
 * - Customizable border and text colors
 * - Support for multiple sections (left, center, right)
 * - Copyright notice support
 *
 * Mirrors footer UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Footer implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const ALIGN_LEFT = 'left';
    public const ALIGN_CENTER = 'center';
    public const ALIGN_RIGHT = 'right';

    public function __construct(
        private readonly string $content = '',
        private readonly string $align = self::ALIGN_LEFT,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $bgColor = null,
        private readonly string $borderPosition = 'top', // 'top', 'bottom', 'both', 'none'
    ) {}

    /**
     * Create a new footer with default styling.
     *
     * Default: left-aligned, purple border on top.
     */
    public static function new(string $content = ''): self
    {
        return new self(
            content: $content,
            align: self::ALIGN_LEFT,
            borderColor: Color::hex('#874BFD'),
            textColor: null,
            bgColor: null,
            borderPosition: 'top',
        );
    }

    /**
     * Create a footer with copyright notice.
     */
    public static function copyright(string $holder = '', int $year = null): self
    {
        $year = $year ?? (int) date('Y');
        $content = $holder !== ''
            ? "© $year $holder. All rights reserved."
            : "© $year. All rights reserved.";

        return new self(
            content: $content,
            align: self::ALIGN_CENTER,
            borderColor: Color::hex('#874BFD'),
            textColor: null,
            bgColor: null,
            borderPosition: 'top',
        );
    }

    /**
     * Set the allocated dimensions for this footer.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the footer as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 80;
        $useWidth = max($useWidth, 1);

        $result = '';

        // Background color if set
        if ($this->bgColor !== null) {
            $result .= $this->bgColor->toBg(ColorProfile::TrueColor);
        }

        $lines = [];

        // Top border
        if ($this->borderPosition === 'top' || $this->borderPosition === 'both') {
            if ($this->borderColor !== null) {
                $lines[] = $this->borderColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $useWidth) . Ansi::reset();
            } else {
                $lines[] = str_repeat('─', $useWidth);
            }
        }

        // Content line
        $contentLine = $this->renderContentLine($useWidth);
        if ($contentLine !== '') {
            if ($this->textColor !== null) {
                $contentLine = $this->textColor->toFg(ColorProfile::TrueColor) . $contentLine . Ansi::reset();
            }
            $lines[] = $contentLine;
        } else {
            $lines[] = str_repeat(' ', $useWidth);
        }

        // Bottom border
        if ($this->borderPosition === 'bottom' || $this->borderPosition === 'both') {
            if ($this->borderColor !== null) {
                $lines[] = $this->borderColor->toFg(ColorProfile::TrueColor) . str_repeat('─', $useWidth) . Ansi::reset();
            } else {
                $lines[] = str_repeat('─', $useWidth);
            }
        }

        // Pad to allocated height
        while (count($lines) < ($this->height ?? 1)) {
            array_unshift($lines, str_repeat(' ', $useWidth));
        }

        // Apply background to all lines
        if ($this->bgColor !== null) {
            $bgPrefix = $this->bgColor->toBg(ColorProfile::TrueColor);
            $bgReset = Ansi::reset();
            $lines = array_map(function ($line) use ($bgPrefix, $bgReset) {
                return $bgPrefix . $line . $bgReset;
            }, $lines);
        }

        return implode("\n", $lines);
    }

    /**
     * Render the content line with alignment.
     */
    private function renderContentLine(int $width): string
    {
        if ($this->content === '') {
            return str_repeat(' ', $width);
        }

        $contentWidth = Width::string($this->content);

        if ($contentWidth >= $width) {
            return $this->truncateToWidth($this->content, $width);
        }

        $padding = $width - $contentWidth;

        return match ($this->align) {
            self::ALIGN_LEFT => $this->content . str_repeat(' ', $padding),
            self::ALIGN_RIGHT => str_repeat(' ', $padding) . $this->content,
            self::ALIGN_CENTER => $this->centerAlign($this->content, $contentWidth, $width),
            default => $this->content . str_repeat(' ', $padding),
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
        return mb_substr($s, 0, max(1, $lo), 'UTF-8');
    }

    /**
     * Calculate the natural dimensions of this footer.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? 80;

        $height = 1;
        if ($this->borderPosition === 'top' || $this->borderPosition === 'both') {
            $height++;
        }
        if ($this->borderPosition === 'bottom' || $this->borderPosition === 'both') {
            $height++;
        }

        return [$useWidth, max(1, $height)];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the footer content.
     */
    public function withContent(string $content): self
    {
        return new self(
            content: $content,
            align: $this->align,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            bgColor: $this->bgColor,
            borderPosition: $this->borderPosition,
        );
    }

    /**
     * Set the text alignment.
     */
    public function withAlign(string $align): self
    {
        return new self(
            content: $this->content,
            align: $align,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            bgColor: $this->bgColor,
            borderPosition: $this->borderPosition,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            align: $this->align,
            borderColor: $color,
            textColor: $this->textColor,
            bgColor: $this->bgColor,
            borderPosition: $this->borderPosition,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            align: $this->align,
            borderColor: $this->borderColor,
            textColor: $color,
            bgColor: $this->bgColor,
            borderPosition: $this->borderPosition,
        );
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            align: $this->align,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            bgColor: $color,
            borderPosition: $this->borderPosition,
        );
    }

    /**
     * Set the border position.
     */
    public function withBorderPosition(string $position): self
    {
        return new self(
            content: $this->content,
            align: $this->align,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            bgColor: $this->bgColor,
            borderPosition: $position,
        );
    }
}