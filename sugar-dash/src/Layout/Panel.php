<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Layout;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;
use SugarCraft\Dash\Foundation\Drawable;
use SugarCraft\Dash\Foundation\Theme;

/**
 * A panel component with header and footer sections.
 *
 * Features:
 * - Optional title header with border
 * - Main content area
 * - Optional footer with border
 * - Customizable border style (single, double, rounded, bold)
 * - Customizable border color
 * - Title and content colors
 *
 * Mirrors panel UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Panel implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item|string $content,
        private readonly ?string $title = null,
        private readonly \SugarCraft\Dash\Foundation\Item|string|null $header = null,
        private readonly \SugarCraft\Dash\Foundation\Item|string|null $footer = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $titleColor = null,
        private readonly string $style = 'single',
    ) {}

    /**
     * Create a new panel with default styling.
     *
     * Default: single border style, purple border color.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item|string $content): self
    {
        return new self(
            content: $content,
            title: null,
            header: null,
            footer: null,
            borderColor: Color::hex('#874BFD'),
            titleColor: Color::hex('#874BFD'),
            style: 'single',
        );
    }

    /**
     * Create a panel with a title.
     */
    public static function titled(\SugarCraft\Dash\Foundation\Item|string $content, string $title): self
    {
        return new self(
            content: $content,
            title: $title,
            header: null,
            footer: null,
            borderColor: Color::hex('#874BFD'),
            titleColor: Color::hex('#874BFD'),
            style: 'single',
        );
    }

    /**
     * Set the allocated dimensions for this panel.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style characters for the panel border.
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
     * Render the panel as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 3);

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Apply border color if set
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        // Top border with title or header
        $result .= $tl;
        if ($this->title !== null) {
            $titleWidth = Width::string($this->title);
            $paddingWidth = $useWidth - 2 - $titleWidth;
            $leftPad = (int) floor($paddingWidth / 2);
            $rightPad = $paddingWidth - $leftPad;

            $result .= str_repeat($h, $leftPad);
            if ($this->titleColor !== null) {
                $result .= $this->titleColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $this->title;
            if ($this->borderColor !== null) {
                $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
            }
            $result .= str_repeat($h, $rightPad);
        } elseif ($this->header !== null) {
            $headerStr = $this->header instanceof \SugarCraft\Dash\Foundation\Item ? $this->header->render() : $this->header;
            $headerWidth = Width::string($headerStr);
            $paddingWidth = $useWidth - 2 - $headerWidth;
            $leftPad = (int) floor($paddingWidth / 2);
            $rightPad = $paddingWidth - $leftPad;

            $result .= str_repeat($h, $leftPad) . $headerStr . str_repeat($h, $rightPad);
        } else {
            $result .= str_repeat($h, $useWidth - 2);
        }
        $result .= $tr . "\n";

        // Content area
        $contentLines = $this->renderContentLines($useWidth - 2);
        foreach ($contentLines as $line) {
            $paddedLine = $v . $line . $v;
            $result .= $paddedLine . "\n";
        }

        // Footer
        if ($this->footer !== null) {
            $result .= $bl . str_repeat($h, $useWidth - 2) . $br . "\n";
            $footerLines = $this->renderContentLines($useWidth - 2, $this->footer);
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
    private function renderContentLines(int $contentWidth, \SugarCraft\Dash\Foundation\Item|string|null $content = null): array
    {
        $content ??= $this->content;

        if ($content instanceof \SugarCraft\Dash\Foundation\Item) {
            $contentToRender = $content;
            if ($content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                $contentToRender = $content->setSize($contentWidth, 0);
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
        if ($content === '') {
            return [str_repeat(' ', $contentWidth)];
        }

        $wrapped = $this->wrapText($content, $contentWidth);

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
     * Calculate the natural width based on content.
     */
    private function calculateNaturalWidth(): int
    {
        $contentWidth = 0;

        if ($this->title !== null) {
            $contentWidth = Width::string($this->title) + 4;
        }

        if ($this->content instanceof \SugarCraft\Dash\Foundation\Item) {
            if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$w, ] = $this->content->getInnerSize();
                $contentWidth = max($contentWidth, $w + 2);
            }
        } else {
            $contentWidth = max($contentWidth, Width::string($this->content) + 2);
        }

        if ($this->footer !== null) {
            if ($this->footer instanceof \SugarCraft\Dash\Foundation\Item && $this->footer instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [$fw, ] = $this->footer->getInnerSize();
                $contentWidth = max($contentWidth, $fw + 2);
            } else {
                $contentWidth = max($contentWidth, Width::string($this->footer) + 2);
            }
        }

        return max($contentWidth, 10);
    }

    /**
     * Calculate the natural dimensions of this panel.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 3);

        $rows = 1; // Top border

        if ($this->title !== null) {
            $rows++; // Title line
        } elseif ($this->header !== null) {
            $rows++; // Header line
        }

        // Content lines
        $contentHeight = 1;
        if ($this->content instanceof \SugarCraft\Dash\Foundation\Item) {
            if ($this->content instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [, $h] = $this->content->getInnerSize();
                $contentHeight = max(1, $h);
            }
        } elseif ($this->content !== '') {
            $wrapped = $this->wrapText($this->content, $useWidth - 2);
            $contentHeight = max(1, count($wrapped));
        }
        $rows += $contentHeight;

        if ($this->footer !== null) {
            $rows++; // Footer separator
            $footerHeight = 1;
            if ($this->footer instanceof \SugarCraft\Dash\Foundation\Item && $this->footer instanceof \SugarCraft\Dash\Foundation\Sizer) {
                [, $h] = $this->footer->getInnerSize();
                $footerHeight = max(1, $h);
            }
            $rows += $footerHeight;
        }

        $rows++; // Bottom border

        return [$useWidth, $rows];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the panel content.
     */
    public function withContent(\SugarCraft\Dash\Foundation\Item|string $content): self
    {
        return new self(
            content: $content,
            title: $this->title,
            header: $this->header,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
        );
    }

    /**
     * Set the panel title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            content: $this->content,
            title: $title,
            header: $this->header,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
        );
    }

    /**
     * Set the panel header content.
     */
    public function withHeader(\SugarCraft\Dash\Foundation\Item|string|null $header): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            header: $header,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
        );
    }

    /**
     * Set the panel footer.
     */
    public function withFooter(\SugarCraft\Dash\Foundation\Item|string|null $footer): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            header: $this->header,
            footer: $footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
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
            header: $this->header,
            footer: $this->footer,
            borderColor: $color,
            titleColor: $this->titleColor,
            style: $this->style,
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
            header: $this->header,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $color,
            style: $this->style,
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
            header: $this->header,
            footer: $this->footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $style,
        );
    }

    /**
     * Apply a theme, fanning it down to any theme-aware children.
     */
    public function withTheme(Theme $theme): self
    {
        $content = $this->content;
        if ($content instanceof Drawable) {
            $content = $content->withTheme($theme);
        }

        $header = $this->header;
        if ($header instanceof Drawable) {
            $header = $header->withTheme($theme);
        }

        $footer = $this->footer;
        if ($footer instanceof Drawable) {
            $footer = $footer->withTheme($theme);
        }

        return new self(
            content: $content,
            title: $this->title,
            header: $header,
            footer: $footer,
            borderColor: $this->borderColor,
            titleColor: $this->titleColor,
            style: $this->style,
        );
    }
}
