<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * Text with ASCII-art style border characters.
 *
 * Features:
 * - Multiple border styles (single, double, rounded, bold, dashed, dotted)
 * - Customizable border color
 * - Text color support
 * - Automatic sizing based on content
 * - Top and bottom border padding options
 *
 * Mirrors charmbracelet/bubbletea border text patterns adapted to PHP.
 */
final class BorderText implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly Item|string $content,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $textColor = null,
        private readonly string $style = 'single',
        private readonly int $topPadding = 0,
        private readonly int $bottomPadding = 0,
    ) {}

    /**
     * Create a new border text component.
     */
    public static function new(Item|string $content): self
    {
        return new self(
            content: $content,
            borderColor: Color::hex('#874BFD'),
            textColor: null,
            style: 'single',
            topPadding: 0,
            bottomPadding: 0,
        );
    }

    /**
     * Create with a top and bottom border.
     */
    public static function withBorders(Item|string $content): self
    {
        return new self(
            content: $content,
            borderColor: Color::hex('#874BFD'),
            textColor: null,
            style: 'single',
            topPadding: 1,
            bottomPadding: 1,
        );
    }

    /**
     * Set the allocated dimensions for this component.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style characters for the border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string} top-left, top-right, bottom-left, bottom-right, horizontal, vertical
     */
    private function getStyleChars(): array
    {
        return match ($this->style) {
            'double' => ['╔', '╗', '╚', '╝', '═', '║'],
            'rounded' => ['╭', '╮', '╰', '╯', '─', '│'],
            'bold' => ['┏', '┓', '┗', '┛', '━', '┃'],
            'dashed' => ['┏', '┓', '┗', '┛', '┅', '┇'],
            'dotted' => ['┏', '┓', '┗', '┛', '┄', '┆'],
            'ascii' => ['+', '+', '+', '+', '-', '|'],
            'single' => ['┌', '┐', '└', '┘', '─', '│'],
            default => ['┌', '┐', '└', '┘', '─', '│'],
        };
    }

    /**
     * Render the bordered text.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 4);

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $contentWidth = $useWidth - 2;
        $contentLines = $this->renderContentLines($contentWidth);

        $result = '';

        // Apply border color if set
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        // Top border
        $result .= $tl . str_repeat($h, $useWidth - 2) . $tr . "\n";

        // Top padding
        for ($i = 0; $i < $this->topPadding; $i++) {
            $result .= $v . str_repeat(' ', $contentWidth) . $v . "\n";
        }

        // Content lines
        foreach ($contentLines as $line) {
            $result .= $v . $line . $v . "\n";
        }

        // Bottom padding
        for ($i = 0; $i < $this->bottomPadding; $i++) {
            $result .= $v . str_repeat(' ', $contentWidth) . $v . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $useWidth - 2) . $br;

        // Reset ANSI
        $result .= Ansi::reset();

        return rtrim($result, "\n");
    }

    /**
     * Render content lines.
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
            return $this->padLines($lines, $contentWidth);
        }

        // String content - simple word wrap
        if ($this->content === '') {
            return [str_repeat(' ', $contentWidth)];
        }

        $wrapped = $this->wrapText($this->content, $contentWidth);
        return $this->padLines($wrapped, $contentWidth);
    }

    /**
     * Pad lines to content width.
     *
     * @param list<string> $lines
     * @return list<string>
     */
    private function padLines(array $lines, int $contentWidth): array
    {
        return array_map(function ($line) use ($contentWidth) {
            $lineWidth = Width::string($line);
            if ($lineWidth < $contentWidth) {
                $line .= str_repeat(' ', $contentWidth - $lineWidth);
            }
            return $line;
        }, $lines);
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

        if ($this->content instanceof Item) {
            if ($this->content instanceof Sizer) {
                [$w, ] = $this->content->getInnerSize();
                $contentWidth = $w + 2;
            }
        } else {
            $contentWidth = Width::string($this->content) + 2;
        }

        return max($contentWidth, 6);
    }

    /**
     * Calculate the natural dimensions.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? $this->calculateNaturalWidth();
        $useWidth = max($useWidth, 4);

        $contentHeight = 1;
        if ($this->content instanceof Item) {
            if ($this->content instanceof Sizer) {
                [, $h] = $this->content->getInnerSize();
                $contentHeight = max(1, $h);
            }
        } elseif ($this->content !== '') {
            $wrapped = $this->wrapText($this->content, $useWidth - 2);
            $contentHeight = max(1, count($wrapped));
        }

        $rows = 1 + $this->topPadding + $contentHeight + $this->bottomPadding + 1;

        return [$useWidth, $rows];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            borderColor: $color,
            textColor: $this->textColor,
            style: $this->style,
            topPadding: $this->topPadding,
            bottomPadding: $this->bottomPadding,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            borderColor: $this->borderColor,
            textColor: $color,
            style: $this->style,
            topPadding: $this->topPadding,
            bottomPadding: $this->bottomPadding,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            content: $this->content,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            style: $style,
            topPadding: $this->topPadding,
            bottomPadding: $this->bottomPadding,
        );
    }

    /**
     * Set the top and bottom padding.
     */
    public function withPadding(int $top, int $bottom): self
    {
        return new self(
            content: $this->content,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            style: $this->style,
            topPadding: $top,
            bottomPadding: $bottom,
        );
    }

    /**
     * Set the content.
     */
    public function withContent(Item|string $content): self
    {
        return new self(
            content: $content,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            style: $this->style,
            topPadding: $this->topPadding,
            bottomPadding: $this->bottomPadding,
        );
    }
}
