<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Modal;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A modal dialog overlay component.
 *
 * Features:
 * - Centered overlay dialog with backdrop
 * - Optional title header with border
 * - Main content area
 * - Optional close button (X)
 * - Customizable border style (single, double, rounded, bold)
 * - Customizable border and background colors
 * - Centered positioning within allocated dimensions
 *
 * Mirrors modal dialog UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Modal implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly \SugarCraft\Dash\Foundation\Item|string $content,
        private readonly ?string $title = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $bgColor = null,
        private readonly string $style = 'single',
        private readonly bool $showClose = true,
        private readonly string $closeLabel = '×',
    ) {}

    /**
     * Create a new modal with default styling.
     *
     * Default: single border style, purple border color, close button visible.
     */
    public static function new(\SugarCraft\Dash\Foundation\Item|string $content): self
    {
        return new self(
            content: $content,
            title: null,
            borderColor: Color::hex('#874BFD'),
            bgColor: Color::hex('#1E1E2E'),
            style: 'single',
            showClose: true,
            closeLabel: '×',
        );
    }

    /**
     * Create a modal with a title.
     */
    public static function titled(\SugarCraft\Dash\Foundation\Item|string $content, string $title): self
    {
        return new self(
            content: $content,
            title: $title,
            borderColor: Color::hex('#874BFD'),
            bgColor: Color::hex('#1E1E2E'),
            style: 'single',
            showClose: true,
            closeLabel: '×',
        );
    }

    /**
     * Set the allocated dimensions for this modal.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Get the style characters for the modal border.
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
     * Render the modal as a string.
     */
    public function render(): string
    {
        $allocW = $this->width ?? 40;
        $allocH = $this->height ?? 10;

        // Modal takes 80% of available space, minimum 20 chars wide
        $modalW = max(20, (int) floor($allocW * 0.8));
        $modalH = max(5, (int) floor($allocH * 0.8));

        // Center the modal
        $offsetX = (int) floor(($allocW - $modalW) / 2);
        $offsetY = (int) floor(($allocH - $modalH) / 2);

        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Apply colors
        if ($this->borderColor !== null) {
            $result .= $this->borderColor->toFg(ColorProfile::TrueColor);
        }

        // Top border with title or centered
        $result .= $tl;
        if ($this->title !== null) {
            $titleWidth = Width::string($this->title);
            $closeWidth = $this->showClose ? Width::string($this->closeLabel) : 0;
            $paddingWidth = $modalW - 2 - $titleWidth - ($this->showClose ? $closeWidth + 1 : 0);
            $leftPad = (int) floor($paddingWidth / 2);
            $rightPad = $paddingWidth - $leftPad;

            $result .= str_repeat($h, $leftPad);
            $result .= ' ' . $this->title . ' ';
            if ($this->showClose) {
                $result .= str_repeat($h, $rightPad - $closeWidth - 1);
                $result .= $this->closeLabel;
            } else {
                $result .= str_repeat($h, $rightPad);
            }
        } else {
            if ($this->showClose) {
                $result .= str_repeat($h, $modalW - 4);
                $result .= ' ' . $this->closeLabel . ' ';
            } else {
                $result .= str_repeat($h, $modalW - 2);
            }
        }
        $result .= $tr . "\n";

        // Content lines
        $contentLines = $this->renderContentLines($modalW - 2);
        foreach ($contentLines as $line) {
            $paddedLine = $v . $line . $v;
            $result .= $paddedLine . "\n";
        }

        // Bottom border
        $result .= $bl . str_repeat($h, $modalW - 2) . $br;

        // Reset ANSI
        $result .= Ansi::reset();

        // Pad to allocated height with empty lines
        $renderedLines = explode("\n", rtrim($result, "\n"));
        while (count($renderedLines) < $modalH) {
            array_unshift($renderedLines, '');
        }
        while (count($renderedLines) > $modalH) {
            array_shift($renderedLines);
        }

        // Build the full output with centering
        $output = [];
        for ($i = 0; $i < $offsetY; $i++) {
            $output[] = str_repeat(' ', $allocW);
        }

        foreach ($renderedLines as $line) {
            $lineWidth = Width::string($line);
            $leftPad = (int) floor(($allocW - $modalW) / 2);
            $padding = str_repeat(' ', $leftPad);
            $output[] = $padding . str_pad($line, $modalW, ' ') . str_repeat(' ', $allocW - $leftPad - $modalW);
        }

        while (count($output) < $allocH) {
            $output[] = str_repeat(' ', $allocW);
        }

        return implode("\n", array_slice($output, 0, $allocH));
    }

    /**
     * Render content and return lines.
     *
     * @return list<string>
     */
    private function renderContentLines(int $contentWidth): array
    {
        if ($this->content instanceof \SugarCraft\Dash\Foundation\Item) {
            $contentToRender = $this->content;
            if ($contentToRender instanceof \SugarCraft\Dash\Foundation\Sizer) {
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
     * Calculate the natural dimensions of this modal.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $w = $this->width ?? 40;
        $h = $this->height ?? 10;

        return [max(20, (int) floor($w * 0.8)), max(5, (int) floor($h * 0.8))];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the modal content.
     */
    public function withContent(\SugarCraft\Dash\Foundation\Item|string $content): self
    {
        return new self(
            content: $content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
        );
    }

    /**
     * Set the modal title.
     */
    public function withTitle(?string $title): self
    {
        return new self(
            content: $this->content,
            title: $title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
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
            borderColor: $color,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
        );
    }

    /**
     * Set the background color.
     */
    public function withBgColor(?Color $color): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $color,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
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
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $style,
            showClose: $this->showClose,
            closeLabel: $this->closeLabel,
        );
    }

    /**
     * Show or hide the close button.
     */
    public function withShowClose(bool $show): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $show,
            closeLabel: $this->closeLabel,
        );
    }

    /**
     * Set the close button label.
     */
    public function withCloseLabel(string $label): self
    {
        return new self(
            content: $this->content,
            title: $this->title,
            borderColor: $this->borderColor,
            bgColor: $this->bgColor,
            style: $this->style,
            showClose: $this->showClose,
            closeLabel: $label,
        );
    }
}
