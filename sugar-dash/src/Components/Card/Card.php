<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Foundation\Buffer;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Rect;
use SugarCraft\Dash\Foundation\Theme;

/**
 * A card / container component with optional header and footer.
 *
 * Displays content within a bordered box:
 * - Optional title header with border
 * - Main content area
 * - Optional footer with border
 * - Customizable border style (single, double, rounded)
 * - Padding around content
 * - Theme-aware: withTheme() uses the theme's primary color
 *
 * Mirrors the card concept from typical UI toolkits but adapted
 * to PHP with wither-style immutable setters.
 */
final class Card implements \SugarCraft\Dash\Foundation\Sizer, Drawable
{
    private ?int $width = null;
    private ?int $height = null;

    /**
     * @param \SugarCraft\Dash\Foundation\Item|string $content The main card content
     * @param \SugarCraft\Dash\Foundation\Item|string|null $footer The optional footer content
     */
    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item|string $content,
        private readonly ?string $title = null,
        private readonly \SugarCraft\Dash\Foundation\Item|string|null $footer = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $titleColor = null,
        private readonly string $style = 'single',
        private readonly int $padding = 1,
    ) {}

    /**
     * Create a new card with default styling.
     *
     * Default: rounded style, purple border.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item|string $content): self
    {
        return new self(
            content: $content,
            title: null,
            footer: null,
            borderColor: Color::hex('#874BFD'),
            titleColor: Color::hex('#874BFD'),
            style: 'single',
            padding: 1,
        );
    }

    /**
     * Create a card with a title (factory method).
     */
    public static function titled(\SugarCraft\Dash\Foundation\Item|string $content, string $title): self
    {
        return new self(
            content: $content,
            title: $title,
            footer: null,
            borderColor: Color::hex('#874BFD'),
            titleColor: Color::hex('#874BFD'),
            style: 'single',
            padding: 1,
        );
    }

    /**
     * Set the allocated dimensions for this card.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style characters for the card border.
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
     * Render the card as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 3); // Minimum width

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Apply border color if set
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        // Top border with title
        $result .= $tl;
        if ($this->title !== null) {
            $titleWidth = Width::string($this->title);
            $paddingWidth = $useWidth - 2 - $titleWidth;
            $leftPad = (int) floor($paddingWidth / 2);
            $rightPad = $paddingWidth - $leftPad;

            $result .= str_repeat($h, max(0, $leftPad));
            if ($this->titleColor !== null) {
                $result .= $this->titleColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $this->title;
            if ($this->borderColor !== null) {
                $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat($h, max(0, $rightPad));
        } else {
            $result .= str_repeat($h, $useWidth - 2);
        }
        $result .= $tr . "\n";

        // Content area
        $contentLines = $this->renderContent($useWidth - 2);
        foreach ($contentLines as $line) {
            $paddedLine = $v . $line . $v;
            $result .= $paddedLine . "\n";
        }

        // Footer
        if ($this->footer !== null) {
            $result .= $bl . str_repeat($h, $useWidth - 2) . $br . "\n";
            $footerLines = $this->renderContent($useWidth - 2, $this->footer);
            foreach ($footerLines as $line) {
                $paddedLine = $v . $line . $v;
                $result .= $paddedLine . "\n";
            }
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        // Reset ANSI
        $result .= Ansi::reset();

        return rtrim($result, "\n");
    }

    /**
     * Render content and return lines.
     *
     * @return list<string>
     */
    private function renderContent(int $contentWidth, \SugarCraft\Dash\Foundation\Item|string|null $content = null): array
    {
        $content ??= $this->content;
        $padding = str_repeat(' ', $this->padding);

        if ($content instanceof \SugarCraft\Dash\Foundation\Item) {
            // Get content size and render
            $itemWidth = $contentWidth - (2 * $this->padding);

            $contentToRender = $content;
            if ($content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $contentToRender = $content->setSize($itemWidth, 0);
            }
            $rendered = $contentToRender->render();

            if ($rendered === '') {
                return [str_repeat(' ', $contentWidth)];
            }

            $lines = explode("\n", $rendered);
            return array_map(function ($line) use ($padding, $contentWidth) {
                $lineWidth = Width::string($line);
                $padded = $padding . $line;
                if ($lineWidth < $contentWidth - $this->padding) {
                    $padded .= str_repeat(' ', $contentWidth - $this->padding - $lineWidth);
                }
                return $padded;
            }, $lines);
        }

        // String content - simple word wrap
        if ($content === '') {
            return [str_repeat(' ', $contentWidth)];
        }

        $innerWidth = $contentWidth - (2 * $this->padding);
        $wrapped = $this->wrapText($content, $innerWidth);

        return array_map(function ($line) use ($padding, $contentWidth, $innerWidth) {
            $lineWidth = Width::string($line);
            $padded = $padding . $line;
            if ($lineWidth < $innerWidth) {
                $padded .= str_repeat(' ', $innerWidth - $lineWidth);
            }
            return $padded;
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
     * Calculate the natural width based on content and footer.
     */
    private function calculateNaturalWidth(): int
    {
        $contentWidth = 0;

        if ($this->title !== null) {
            $contentWidth = Width::string($this->title) + 4; // +4 for padding
        }

        if ($this->content instanceof \SugarCraft\Dash\Foundation\Item) {
            if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$w, ] = $this->content->getInnerSize();
                // Add space for borders (2) and padding (2 * padding)
                $contentWidth = max($contentWidth, $w + 2 + (2 * $this->padding));
            }
        } else {
            // Add space for borders (2) and padding (2 * padding)
            $contentWidth = max($contentWidth, Width::string($this->content) + 2 + (2 * $this->padding));
        }

        // Account for footer width as well
        if ($this->footer !== null) {
            if ($this->footer instanceof \SugarCraft\Dash\Foundation\Item && $this->footer instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$fw, ] = $this->footer->getInnerSize();
                $footerWidth = $fw + 2 + (2 * $this->padding);
            } else {
                $footerWidth = Width::string($this->footer) + 2 + (2 * $this->padding);
            }
            $contentWidth = max($contentWidth, $footerWidth);
        }

        return max($contentWidth, 10);
    }

    /**
     * Calculate the natural dimensions of this card.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 3);

        // Calculate content height
        $rows = 1; // Top border

        if ($this->title !== null) {
            $rows++; // Title line
        }

        // Content lines
        $contentHeight = 1;
        if ($this->content instanceof \SugarCraft\Dash\Foundation\Item) {
            if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [, $h] = $this->content->getInnerSize();
                $contentHeight = max(1, $h);
            }
        } elseif ($this->content !== '') {
            $innerWidth = $useWidth - 2 - (2 * $this->padding);
            $wrapped = $this->wrapText($this->content, $innerWidth);
            $contentHeight = max(1, count($wrapped));
        }
        $rows += $contentHeight;

        if ($this->footer !== null) {
            $rows++; // Footer separator
            $footerHeight = 1;
            if ($this->footer instanceof \SugarCraft\Dash\Foundation\Item) {
                if ($this->footer instanceof \SugarCraft\Dash\Foundation\Sizer) {
                    [, $h] = $this->footer->getInnerSize();
                    $footerHeight = max(1, $h);
                }
            }
            $rows += $footerHeight;
        }

        $rows++; // Bottom border

        return [$useWidth, $rows];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the card content.
     */
    public function withContent(\SugarCraft\Dash\Foundation\Item|string $content): self
    {
        return new self(
            content: $content,
            title: $this->title,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
            padding: $this->padding,
        );
    }

    /**
     * Set the card title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            content: $this->content,
            title: $title,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
            padding: $this->padding,
        );
    }

    /**
     * Set the card footer.
     */
    public function withFooter(\SugarCraft\Dash\Foundation\Item|string|null $footer): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            footer: $footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
            padding: $this->padding,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            footer: $this->footer,
            borderColor: $color,
            titleColor: $this->titleColor,
            style: $this->style,
            padding: $this->padding,
        );
    }

    /**
     * Set the title color.
     */
    public function withTitleColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $color,
            style: $this->style,
            padding: $this->padding,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $style,
            padding: $this->padding,
        );
    }

    /**
     * Set the content padding.
     */
    public function withPadding(int $padding): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
            padding: $padding,
        );
    }

    /**
     * Apply a theme to this card, using the theme's primary color
     * for border and title.
     */
    public function withTheme(Theme $theme): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            footer: $this->footer,
            borderColor: $theme->primary(),
            titleColor: $theme->primary(),
            style: $this->style,
            padding: $this->padding,
        );
    }

    // ─── Drawable implementation ──────────────────────────────────

    public function getRect(): Rect
    {
        [$w, $h] = $this->getInnerSize();
        return new Rect(0, 0, $w - 1, $h - 1);
    }

    public function setRect(Rect $rect): self
    {
        return $this;
    }

    public function draw(Buffer $buffer): void
    {
        // Cards render to strings, not into Buffer grids
    }
}
