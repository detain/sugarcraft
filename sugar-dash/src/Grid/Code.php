<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A code block / snippet display component.
 *
 * Displays multi-line code with optional syntax highlighting colors,
 * border styling, and language label. Supports word-wrap control,
 * horizontal alignment, and custom color schemes.
 *
 * Mirrors code block rendering from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Code implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $code,
        private readonly ?string $language = null,
        private readonly ?int $maxWidth = null,
        private readonly bool $wordWrap = true,
        private readonly HAlign $horizontalAlign = HAlign::Left,
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $languageColor = null,
    ) {}

    /**
     * Create a new code block with default styling.
     */
    public static function new(string $code, ?string $language = null): self
    {
        return new self(
            code: $code,
            language: $language,
            maxWidth: null,
            wordWrap: false,
            horizontalAlign: HAlign::Left,
            backgroundColor: Color::hex('#1E1E2E'),
            textColor: Color::hex('#CDD6F4'),
            borderColor: Color::hex('#45475A'),
            languageColor: Color::hex('#CBA6F7'),
        );
    }

    /**
     * Set the allocated dimensions for this code block.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the code block as a string.
     */
    public function render(): string
    {
        $contentWidth = $this->getContentWidth();

        if ($contentWidth <= 0) {
            return '';
        }

        $lines = $this->prepareLines($contentWidth);
        $border = $this->renderBorder($contentWidth);
        $languageLabel = $this->renderLanguageLabel($contentWidth);

        $output = $border;

        if ($languageLabel !== '') {
            $output .= "\n" . $languageLabel;
        }

        foreach ($lines as $line) {
            $output .= "\n" . $this->renderCodeLine($line, $contentWidth);
        }

        $output .= "\n" . $border;

        return $output;
    }

    /**
     * Get the content width for rendering.
     */
    private function getContentWidth(): int
    {
        if ($this->width !== null && $this->width > 0) {
            // Account for border characters (2 chars: │ ... │)
            return max(0, $this->width - 4);
        }
        return $this->maxWidth ?? 0;
    }

    /**
     * Prepare code lines for rendering.
     *
     * @return list<string>
     */
    private function prepareLines(int $width): array
    {
        $codeLines = explode("\n", $this->code);
        $result = [];

        foreach ($codeLines as $codeLine) {
            if ($this->wordWrap && $width > 0) {
                $wrapped = $this->wrapLine($codeLine, $width);
                $result = array_merge($result, $wrapped);
            } else {
                $result[] = $codeLine;
            }
        }

        return $result;
    }

    /**
     * Wrap a single line of code to fit within the given width.
     *
     * @return list<string>
     */
    private function wrapLine(string $line, int $width): array
    {
        if ($width <= 0 || Width::string($line) <= $width) {
            return [$line];
        }

        $result = [];
        $words = preg_split('/(\s+)/', $line, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($words === false) {
            return [$line];
        }

        $currentLine = '';
        $currentWidth = 0;

        foreach ($words as $word) {
            $wordWidth = Width::string($word);

            if ($currentWidth > 0 && $currentWidth + $wordWidth > $width) {
                $result[] = $currentLine;
                $currentLine = $word;
                $currentWidth = $wordWidth;
            } else {
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
     * Render the border line.
     */
    private function renderBorder(int $contentWidth): string
    {
        $borderLine = str_repeat('─', $contentWidth + 2);

        $output = '┌' . $borderLine . '┐';

        if ($this->borderColor !== null) {
            $output = $this->borderColor->toFg(ColorProfile::TrueColor) . $output . Ansi::reset();
        }

        return $output;
    }

    /**
     * Render the language label line if present.
     */
    private function renderLanguageLabel(int $contentWidth): string
    {
        if ($this->language === null || $this->language === '') {
            return '';
        }

        $label = ' ' . $this->language . ' ';
        $labelWidth = Width::string($label);
        $padding = $contentWidth + 2 - $labelWidth;

        if ($padding < 0) {
            $label = mb_substr($label, 0, $contentWidth + 2, 'UTF-8');
            $labelWidth = Width::string($label);
            $padding = max(0, $contentWidth + 2 - $labelWidth);
        }

        $line = '│' . str_repeat(' ', (int) floor($padding / 2)) . $label;

        if ($this->languageColor !== null) {
            $line = $this->languageColor->toFg(ColorProfile::TrueColor) . $line;
            $line .= Ansi::reset();
        }

        $line .= str_repeat(' ', (int) ceil($padding / 2)) . '│';

        return $line;
    }

    /**
     * Render a single line of code.
     */
    private function renderCodeLine(string $line, int $contentWidth): string
    {
        $lineWidth = Width::string($line);
        $padding = $contentWidth - $lineWidth;

        $alignedLine = match ($this->horizontalAlign) {
            HAlign::Left => $line . str_repeat(' ', max(0, $padding)),
            HAlign::Right => str_repeat(' ', max(0, $padding)) . $line,
            HAlign::Center => $this->centerAlign($line, $lineWidth, $contentWidth),
        };

        $output = '│ ' . $alignedLine . ' │';

        // Apply colors
        if ($this->backgroundColor !== null || $this->textColor !== null) {
            if ($this->backgroundColor !== null) {
                $output = $this->backgroundColor->toBg(ColorProfile::TrueColor) . $output;
            }
            if ($this->textColor !== null) {
                $output = $this->textColor->toFg(ColorProfile::TrueColor) . $output;
            }
            $output .= Ansi::reset();
        }

        return $output;
    }

    /**
     * Center-align a line within the given width.
     */
    private function centerAlign(string $line, int $lineWidth, int $width): string
    {
        if ($lineWidth >= $width) {
            return $line;
        }

        $padding = $width - $lineWidth;
        $left = (int) floor($padding / 2);
        $right = $padding - $left;

        return str_repeat(' ', $left) . $line . str_repeat(' ', $right);
    }

    /**
     * Calculate the natural dimensions of this code block.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $contentWidth = $this->getContentWidth();

        if ($contentWidth <= 0) {
            $lines = explode("\n", $this->code);
            $maxWidth = 0;
            foreach ($lines as $line) {
                $w = Width::string($line);
                if ($w > $maxWidth) {
                    $maxWidth = $w;
                }
            }
            $width = $maxWidth + 4; // Border chars
            $height = count($lines) + 2; // Code lines + top and bottom border
        } else {
            $wrappedLines = $this->prepareLines($contentWidth);
            $width = $contentWidth + 4;
            $height = count($wrappedLines) + 2; // +2 for top/bottom borders
            if ($this->language !== null) {
                $height++; // Extra line for language label
            }
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the language label.
     */
    public function withLanguage(?string $language): self
    {
        return new self(
            code: $this->code,
            language: $language,
            maxWidth: $this->maxWidth,
            wordWrap: $this->wordWrap,
            horizontalAlign: $this->horizontalAlign,
            backgroundColor: $this->backgroundColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            languageColor: $this->languageColor,
        );
    }

    /**
     * Set the maximum width for word-wrapping.
     */
    public function withMaxWidth(?int $maxWidth): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            maxWidth: $maxWidth,
            wordWrap: $this->wordWrap,
            horizontalAlign: $this->horizontalAlign,
            backgroundColor: $this->backgroundColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            languageColor: $this->languageColor,
        );
    }

    /**
     * Enable or disable word-wrapping.
     */
    public function withWordWrap(bool $wordWrap): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            maxWidth: $this->maxWidth,
            wordWrap: $wordWrap,
            horizontalAlign: $this->horizontalAlign,
            backgroundColor: $this->backgroundColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            languageColor: $this->languageColor,
        );
    }

    /**
     * Set the horizontal alignment.
     */
    public function withHorizontalAlign(HAlign $align): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            maxWidth: $this->maxWidth,
            wordWrap: $this->wordWrap,
            horizontalAlign: $align,
            backgroundColor: $this->backgroundColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            languageColor: $this->languageColor,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            maxWidth: $this->maxWidth,
            wordWrap: $this->wordWrap,
            horizontalAlign: $this->horizontalAlign,
            backgroundColor: $color,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            languageColor: $this->languageColor,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            maxWidth: $this->maxWidth,
            wordWrap: $this->wordWrap,
            horizontalAlign: $this->horizontalAlign,
            backgroundColor: $this->backgroundColor,
            textColor: $color,
            borderColor: $this->borderColor,
            languageColor: $this->languageColor,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            maxWidth: $this->maxWidth,
            wordWrap: $this->wordWrap,
            horizontalAlign: $this->horizontalAlign,
            backgroundColor: $this->backgroundColor,
            textColor: $this->textColor,
            borderColor: $color,
            languageColor: $this->languageColor,
        );
    }

    /**
     * Set the language label color.
     */
    public function withLanguageColor(?Color $color): self
    {
        return new self(
            code: $this->code,
            language: $this->language,
            maxWidth: $this->maxWidth,
            wordWrap: $this->wordWrap,
            horizontalAlign: $this->horizontalAlign,
            backgroundColor: $this->backgroundColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            languageColor: $color,
        );
    }

    /**
     * Set new code content.
     */
    public function withCode(string $code): self
    {
        return new self(
            code: $code,
            language: $this->language,
            maxWidth: $this->maxWidth,
            wordWrap: $this->wordWrap,
            horizontalAlign: $this->horizontalAlign,
            backgroundColor: $this->backgroundColor,
            textColor: $this->textColor,
            borderColor: $this->borderColor,
            languageColor: $this->languageColor,
        );
    }
}
