<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A slide-out drawer panel component.
 *
 * Features:
 * - Four positions: left, right, top, bottom
 * - Optional title header with border
 * - Main content area
 * - Optional footer section
 * - Customizable border style (single, double, rounded, bold)
 * - Customizable border color
 * - Configurable width (for left/right) or height (for top/bottom)
 *
 * Mirrors drawer/sidebar UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Drawer implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public const POSITION_LEFT = 'left';
    public const POSITION_RIGHT = 'right';
    public const POSITION_TOP = 'top';
    public const POSITION_BOTTOM = 'bottom';

    public function __construct(
        private readonly Item|string $content,
        private readonly string $position = self::POSITION_LEFT,
        private readonly ?int $size = null,
        private readonly ?string $title = null,
        private readonly ?Color $borderColor = null,
        private readonly string $style = 'single',
        private readonly Item|string|null $footer = null,
    ) {}

    /**
     * Create a new drawer with default styling.
     *
     * Default: left position, single border style, purple border color.
     */
    public static function new(Item|string $content, string $position = self::POSITION_LEFT): self
    {
        return new self(
            content: $content,
            position: $position,
            size: null,
            title: null,
            borderColor: Color::hex('#874BFD'),
            style: 'single',
            footer: null,
        );
    }

    /**
     * Create a left-positioned drawer.
     */
    public static function left(Item|string $content): self
    {
        return self::new($content, self::POSITION_LEFT);
    }

    /**
     * Create a right-positioned drawer.
     */
    public static function right(Item|string $content): self
    {
        return self::new($content, self::POSITION_RIGHT);
    }

    /**
     * Create a top-positioned drawer.
     */
    public static function top(Item|string $content): self
    {
        return self::new($content, self::POSITION_TOP);
    }

    /**
     * Create a bottom-positioned drawer.
     */
    public static function bottom(Item|string $content): self
    {
        return self::new($content, self::POSITION_BOTTOM);
    }

    /**
     * Set the allocated dimensions for this drawer.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style characters for the drawer border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string} top-left, top-right, bottom-left, bottom-right, horizontal, vertical
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'empty' => [' ', ' ', ' ', ' ', ' ', ' '],
            default => ['┌', '┐', '└', '┘', '─', '│'],
        };
    }

    /**
     * Render the drawer as a string.
     */
    public function render(): string
    {
        $allocW = $this->width ?? 80;
        $allocH = $this->height ?? 24;

        // Calculate drawer dimensions based on position
        $drawerW = $this->position === self::POSITION_LEFT || $this->position === self::POSITION_RIGHT
            ? ($this->size ?? (int) floor($allocW * 0.3))
            : $allocW;
        $drawerH = $this->position === self::POSITION_TOP || $this->position === self::POSITION_BOTTOM
            ? ($this->size ?? (int) floor($allocH * 0.4))
            : $allocH;

        $drawerW = max(3, min($drawerW, $allocW));
        $drawerH = max(3, min($drawerH, $allocH));

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $contentWidth = $drawerW - 2;
        $result = '';

        // Apply border color if set
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        // Render based on position
        $contentLines = $this->renderContentLines($contentWidth);

        if ($this->position === self::POSITION_LEFT || $this->position === self::POSITION_RIGHT) {
            $result = $this->renderVerticalDrawer($drawerW, $drawerH, $tl, $tr, $bl, $br, $h, $v, $contentLines);
        } else {
            $result = $this->renderHorizontalDrawer($drawerW, $drawerH, $tl, $tr, $bl, $br, $h, $v, $contentLines);
        }

        // Reset ANSI
        $result .= Ansi::reset();

        return $result;
    }

    /**
     * Render a left or right positioned drawer.
     */
    private function renderVerticalDrawer(
        int $drawerW,
        int $drawerH,
        string $tl,
        string $tr,
        string $bl,
        string $br,
        string $h,
        string $v,
        array $contentLines
    ): string {
        $contentWidth = $drawerW - 2;

        // Top border
        $result = $tl . str_repeat($h, $contentWidth) . $tr . "\n";

        // Content lines
        foreach ($contentLines as $i => $line) {
            $paddedLine = $v . $line . $v;
            $result .= $paddedLine . "\n";
        }

        // Pad with empty lines if needed
        $usedLines = count($contentLines);
        while ($usedLines < $drawerH - 2) {
            $result .= $v . str_repeat(' ', $contentWidth) . $v . "\n";
            $usedLines++;
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $contentWidth) . $br;

        return rtrim($result, "\n");
    }

    /**
     * Render a top or bottom positioned drawer.
     */
    private function renderHorizontalDrawer(
        int $drawerW,
        int $drawerH,
        string $tl,
        string $tr,
        string $bl,
        string $br,
        string $h,
        string $v,
        array $contentLines
    ): string {
        $contentWidth = $drawerW - 2;

        // Top border
        $result = $tl . str_repeat($h, $contentWidth) . $tr . "\n";

        // Content lines
        foreach ($contentLines as $line) {
            $paddedLine = $v . $line . $v;
            $result .= $paddedLine . "\n";
        }

        // Pad with empty lines if needed
        $usedLines = count($contentLines);
        while ($usedLines < $drawerH - 2) {
            $result .= $v . str_repeat(' ', $contentWidth) . $v . "\n";
            $usedLines++;
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $contentWidth) . $br;

        return rtrim($result, "\n");
    }

    /**
     * Render content and return lines.
     *
     * @return list<string>
     */
    private function renderContentLines(int $contentWidth): array
    {
        if ($this->content instanceof Item) {
            $contentToRender = $this->content;
            if ($contentToRender instanceof Sizer) {
                $contentToRender = $contentToRender->setSize($contentWidth, 0);
            }
            $rendered = $contentToRender->render();

            if ($rendered === '') {
                return [str_repeat(' ', $contentWidth)];
            }

            $lines = explode("\n", $rendered);
            return array_map(function ($line) use ($contentWidth) {
                $lineWidth = Width::string($line);
                if ($lineWidth < $contentWidth) {
                    $line .= str_repeat(' ', $contentWidth - $lineWidth);
                }
                return $line;
            }, $lines);
        }

        // String content - simple word wrap
        if ($this->content === '') {
            return [str_repeat(' ', $contentWidth)];
        }

        $wrapped = $this->wrapText($this->content, $contentWidth);

        return array_map(function ($line) use ($contentWidth) {
            $lineWidth = Width::string($line);
            if ($lineWidth < $contentWidth) {
                $line .= str_repeat(' ', $contentWidth - $lineWidth);
            }
            return $line;
        }, $wrapped);
    }

    /**
     * Wrap text to fit within a given width.
     *
     * @return list<string>
     */
    private function wrapText(string $text, int $width): array
    {
        if ($width <= 0) {
            return [$text];
        }

        if ($text === '') {
            return [''];
        }

        $result = [];
        $words = preg_split('/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = Width::string($word);

            if ($currentWidth > 0 && $currentWidth + 1 + $wordWidth > $width) {
                $result[] = $currentLine;
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
                if ($currentLine !== '') {
                    $currentLine .= ' ';
                    $currentWidth++;
                }
                $currentLine .= $word;
                $currentWidth += $wordWidth;
            }
        }

        if ($currentLine !== '') {
            $result[] = $currentLine;
        }

        return $result === [] ? [''] : $result;
    }

    /**
     * Calculate the natural dimensions of this drawer.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $allocW = $this->width ?? 80;
        $allocH = $this->height ?? 24;

        $drawerW = $this->position === self::POSITION_LEFT || $this->position === self::POSITION_RIGHT
            ? ($this->size ?? (int) floor($allocW * 0.3))
            : $allocW;
        $drawerH = $this->position === self::POSITION_TOP || $this->position === self::POSITION_BOTTOM
            ? ($this->size ?? (int) floor($allocH * 0.4))
            : $allocH;

        return [max(3, min($drawerW, $allocW)), max(3, min($drawerH, $allocH))];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the drawer content.
     */
    public function withContent(Item|string $content): self
    {
        return new self(
            content: $content,
            position: $this->position,
            size: $this->size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $this->footer,
        );
    }

    /**
     * Set the drawer position.
     */
    public function withPosition(string $position): self
    {
        return new self(
            content: $this->content,
            position: $position,
            size: $this->size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $this->footer,
        );
    }

    /**
     * Set the drawer size (width for left/right, height for top/bottom).
     */
    public function withSize(?int $size): self
    {
        return new self(
            content: $this->content,
            position: $this->position,
            size: $size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $this->footer,
        );
    }

    /**
     * Set the drawer title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            content: $this->content,
            position: $this->position,
            size: $this->size,
            title: $title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $this->footer,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            position: $this->position,
            size: $this->size,
            title: $this->title,
            borderColor: $color,
            style: $this->style,
            footer: $this->footer,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            content: $this->content,
            position: $this->position,
            size: $this->size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $style,
            footer: $this->footer,
        );
    }

    /**
     * Set the drawer footer.
     */
    public function withFooter(Item|string|null $footer): self
    {
        return new self(
            content: $this->content,
            position: $this->position,
            size: $this->size,
            title: $this->title,
            borderColor: $this->borderColor,
            style: $this->style,
            footer: $footer,
        );
    }
}