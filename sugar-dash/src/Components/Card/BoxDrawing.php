<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * Box drawing frame generator using Unicode box-drawing characters.
 *
 * Features:
 * - Multiple box styles (single, double, rounded, bold, dashed, dotted, ascii)
 * - Customizable border color and background color
 * - Top and bottom title bars
 * - Grid layout support (dividers)
 * - Automatic or manual sizing
 *
 * Mirrors charmbracelet/bubbletea box rendering patterns adapted to PHP
 * with wither-style immutable setters.
 */
final class BoxDrawing implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly ?string $title = null,
        private readonly ?string $subtitle = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $bgColor = null,
        private readonly string $style = 'single',
        private readonly bool $showTopBorder = true,
        private readonly bool $showBottomBorder = true,
        private readonly bool $showLeftBorder = true,
        private readonly bool $showRightBorder = true,
        private readonly bool $showTitleBar = false,
        private readonly bool $showSubtitleBar = false,
    ) {}

    /**
     * Create a new box drawing component.
     */
    public static function new(?string $title = null): self
    {
        return new self(
            title: $title,
            subtitle: null,
            borderColor: Color::hex('#874BFD'),
            bgColor: null,
            style: 'single',
            showTopBorder: true,
            showBottomBorder: true,
            showLeftBorder: true,
            showRightBorder: true,
            showTitleBar: $title !== null,
            showSubtitleBar: false,
        );
    }

    /**
     * Create a box with a title.
     */
    public static function titled(string $title): self
    {
        return new self(
            title: $title,
            subtitle: null,
            borderColor: Color::hex('#874BFD'),
            bgColor: null,
            style: 'single',
            showTopBorder: true,
            showBottomBorder: true,
            showLeftBorder: true,
            showRightBorder: true,
            showTitleBar: true,
            showSubtitleBar: false,
        );
    }

    /**
     * Create a double-line box.
     */
    public static function double(?string $title = null): self
    {
        $box = self::new($title);
        return $box->withStyle('double');
    }

    /**
     * Create a rounded box.
     */
    public static function rounded(?string $title = null): self
    {
        $box = self::new($title);
        return $box->withStyle('rounded');
    }

    /**
     * Create a bold box.
     */
    public static function bold(?string $title = null): self
    {
        $box = self::new($title);
        return $box->withStyle('bold');
    }

    /**
     * Set the allocated dimensions for this component.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style characters for the box.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string, 6:string, 7:string, 8:string, 9:string, 10:string, 11:string} tl, tr, bl, br, h, v, h-double, v-double, h-bold, v-bold, h-dashed, v-dashed
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║', '═', '║', '═', '║', '─', '│'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│', '─', '│', '─', '│', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃', '━', '┃', '━', '┃', '─', '│'],
            'dashed' => ['┏', '┓', '┗', '┛', '┅', '┇', '═', '║', '━', '┃', '┅', '┇'],
            'dotted' => ['┏', '┓', '┗', '┛', '┄', '┆', '═', '║', '━', '┃', '┄', '┆'],
            'ascii' => ['+', '+', '+', '+', '-', '|', '-', '|', '-', '|', '-', '|'],
            'single' => ['┌', '┐', '└', '┘', '─', '│', '─', '│', '─', '│', '─', '│'],
            default => ['┌', '┐', '└', '┘', '─', '│', '─', '│', '─', '│', '─', '│'],
        };
    }

    /**
     * Render the box drawing.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useHeight = $this->height ?? $this->calculateNaturalHeight();
        $useWidth = max($useWidth, 4);
        $useHeight = max($useHeight, 2);

        [
            $tl, $tr, $bl, $br, $h, $v,
            $hDouble, $vDouble, $hBold, $vBold, $hDashed, $vDashed
        ] = $this->getStyleChars();

        $innerWidth = $useWidth - 2;
        $result = '';

        // Apply colors
        if ($this->bgColor !== null) {
            $result .= $this->bgColor->toBg(ColorProfile::TrueColor);
        }
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        // Top border
        if ($this->showTopBorder) {
            if ($this->showTitleBar && $this->title !== null) {
                $result .= $tl . $this->renderTitleBar($innerWidth, $h) . $tr . "\n";
            } else {
                $result .= $tl . str_repeat($h, $innerWidth) . $tr . "\n";
            }
        }

        // Subtitle bar
        if ($this->showSubtitleBar && $this->subtitle !== null) {
            $result .= $v . $this->subtitle . str_repeat(' ', max(0, $innerWidth - mb_strlen($this->subtitle, 'UTF-8'))) . $v . "\n";
        }

        // Content area
        $contentHeight = $useHeight - ($this->showTopBorder ? 1 : 0) - ($this->showBottomBorder ? 1 : 0);
        $contentHeight -= $this->showTitleBar && $this->title !== null ? 1 : 0;
        $contentHeight -= $this->showSubtitleBar && $this->subtitle !== null ? 1 : 0;
        $contentHeight = max(0, $contentHeight);

        // Render content lines (at least one empty line if showing vertical borders)
        $renderLines = max(1, $contentHeight);
        for ($i = 0; $i < $renderLines; $i++) {
            $left = $this->showLeftBorder ? $v : ' ';
            $right = $this->showRightBorder ? $v : ' ';
            $result .= $left . str_repeat(' ', $innerWidth) . $right . "\n";
        }

        // Bottom border
        if ($this->showBottomBorder) {
            $result .= $bl . str_repeat($h, $innerWidth) . $br;
        }

        // Reset ANSI
        $result .= Ansi::reset();

        return rtrim($result, "\n");
    }

    /**
     * Render the title bar with centered title.
     */
    private function renderTitleBar(int $innerWidth, string $h): string
    {
        if ($this->title === null) {
            return str_repeat($h, $innerWidth);
        }

        $titleWidth = mb_strlen($this->title, 'UTF-8');
        $availableWidth = $innerWidth - 2; // Leave space for border chars

        if ($titleWidth >= $availableWidth) {
            return mb_substr($this->title, 0, $availableWidth, 'UTF-8');
        }

        $padding = $availableWidth - $titleWidth;
        $leftPad = (int) floor($padding / 2);
        $rightPad = $padding - $leftPad;

        return str_repeat($h, $leftPad) . $this->title . str_repeat($h, $rightPad);
    }

    /**
     * Calculate natural width based on title or defaults.
     */
    private function calculateNaturalWidth(): int
    {
        if ($this->title !== null) {
            return mb_strlen($this->title, 'UTF-8') + 4;
        }
        return 20;
    }

    /**
     * Calculate natural height.
     */
    private function calculateNaturalHeight(): int
    {
        $height = 2; // top and bottom borders

        if ($this->showTitleBar && $this->title !== null) {
            $height++;
        }
        if ($this->showSubtitleBar && $this->subtitle !== null) {
            $height++;
        }

        return $height;
    }

    /**
     * Calculate the natural dimensions.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useHeight = $this->height ?? $this->calculateNaturalHeight();

        return [$useWidth, $useHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            title: $title,
            subtitle: $this->subtitle,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showTopBorder: $this->showTopBorder,
            showBottomBorder: $this->showBottomBorder,
            showLeftBorder: $this->showLeftBorder,
            showRightBorder: $this->showRightBorder,
            showTitleBar: $title !== null,
            showSubtitleBar: $this->showSubtitleBar,
        );
    }

    /**
     * Set the subtitle.
     */
    public function withSubtitle(?string $subtitle): self
    {
        return new self(
            title: $this->title,
            subtitle: $subtitle,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showTopBorder: $this->showTopBorder,
            showBottomBorder: $this->showBottomBorder,
            showLeftBorder: $this->showLeftBorder,
            showRightBorder: $this->showRightBorder,
            showTitleBar: $this->showTitleBar,
            showSubtitleBar: $subtitle !== null,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            borderColor: $color,
            bgColor: $this->bgColor,
            style: $this->style,
            showTopBorder: $this->showTopBorder,
            showBottomBorder: $this->showBottomBorder,
            showLeftBorder: $this->showLeftBorder,
            showRightBorder: $this->showRightBorder,
            showTitleBar: $this->showTitleBar,
            showSubtitleBar: $this->showSubtitleBar,
        );
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            borderColor: $this->borderColor,
            bgColor: $color,
            style: $this->style,
            showTopBorder: $this->showTopBorder,
            showBottomBorder: $this->showBottomBorder,
            showLeftBorder: $this->showLeftBorder,
            showRightBorder: $this->showRightBorder,
            showTitleBar: $this->showTitleBar,
            showSubtitleBar: $this->showSubtitleBar,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $style,
            showTopBorder: $this->showTopBorder,
            showBottomBorder: $this->showBottomBorder,
            showLeftBorder: $this->showLeftBorder,
            showRightBorder: $this->showRightBorder,
            showTitleBar: $this->showTitleBar,
            showSubtitleBar: $this->showSubtitleBar,
        );
    }

    /**
     * Show/hide specific borders.
     */
    public function withBorders(
        ?bool $top = null,
        ?bool $bottom = null,
        ?bool $left = null,
        ?bool $right = null,
    ): self {
        return new self(
            title: $this->title,
            subtitle: $this->subtitle,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showTopBorder: $top ?? $this->showTopBorder,
            showBottomBorder: $bottom ?? $this->showBottomBorder,
            showLeftBorder: $left ?? $this->showLeftBorder,
            showRightBorder: $right ?? $this->showRightBorder,
            showTitleBar: $this->showTitleBar,
            showSubtitleBar: $this->showSubtitleBar,
        );
    }
}
