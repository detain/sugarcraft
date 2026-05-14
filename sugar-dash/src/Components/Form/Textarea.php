<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Form;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A multi-line text area input component.
 *
 * Features:
 * - Multi-line text input with word wrapping
 * - Display current line count and optionally max lines
 * - Optional label, placeholder, and error message
 * - Focus and readonly states
 * - Customizable border and text colors
 *
 * Mirrors textarea UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Textarea implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly ?string $value = null,
        private readonly ?string $placeholder = null,
        private readonly ?string $label = null,
        private readonly ?string $error = null,
        private readonly ?Color $borderColor = null,
        private readonly ?Color $textColor = null,
        private readonly ?Color $placeholderColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly int $rows = 5,
        private readonly ?int $maxRows = null,
        private readonly string $style = 'single',
    ) {}

    /**
     * Create a new textarea with default styling.
     *
     * Default: single border, 5 rows, gray placeholder.
     */
    public static function new(?string $value = null, int $rows = 5): self
    {
        return new self(
            value: $value,
            placeholder: null,
            label: null,
            error: null,
            borderColor: Color::hex('#6B7280'),
            textColor: Color::hex('#F9FAFB'),
            placeholderColor: Color::hex('#6B7280'),
            backgroundColor: null,
            rows: max(1, $rows),
            maxRows: null,
            style: 'single',
        );
    }

    /**
     * Create a textarea with a label.
     */
    public static function labeled(?string $value, string $label, int $rows = 5): self
    {
        return new self(
            value: $value,
            placeholder: null,
            label: $label,
            error: null,
            borderColor: Color::hex('#6B7280'),
            textColor: Color::hex('#F9FAFB'),
            placeholderColor: Color::hex('#6B7280'),
            backgroundColor: null,
            rows: $rows,
            maxRows: null,
            style: 'single',
        );
    }

    /**
     * Set the allocated dimensions for this textarea.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the textarea as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 50;
        $useWidth = max($useWidth, 5);

        $contentWidth = $useWidth - 2;
        $useRows = $this->height ?? $this->rows;
        $useRows = max($useRows, 1);

        // Determine border characters based on style
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Render label above textarea if present
        if ($this->label !== null) {
            if ($this->textColor !== null) {
                $result .= $this->textColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $this->label . "\n";
        }

        // Apply colors
        $borderColor = $this->error !== null ? Color::hex('#EF4444') : $this->borderColor;
        if ($borderColor !== null) {
            $result .= $borderColor->toFg(ColorProfile::TrueColor);
        }
        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }

        // Top border
        $result .= $tl . str_repeat($h, $contentWidth) . $tr . "\n";

        // Content lines
        $lines = $this->getContentLines($contentWidth, $useRows);
        foreach ($lines as $line) {
            if ($this->textColor !== null) {
                $result .= $this->textColor->toFg(ColorProfile::TrueColor);
            }
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
            }

            $lineContent = $v . ' ' . $line;
            $lineWidth = Width::string($line) + 1;

            if ($lineWidth < $contentWidth) {
                $lineContent .= str_repeat(' ', $contentWidth - $lineWidth);
            }

            $result .= $lineContent . $v . "\n";
        }

        // Bottom border
        if ($borderColor !== null) {
            $result .= $borderColor->toFg(ColorProfile::TrueColor);
        }
        $result .= $bl . str_repeat($h, $contentWidth) . $br;

        // Reset ANSI before adding error message
        $result .= Ansi::reset();

        // Add error message if present
        if ($this->error !== null) {
            $result .= "\n";
            if (Width::string($this->error) > $contentWidth) {
                $wrapped = $this->wrapText($this->error, $contentWidth);
                $result .= implode("\n", $wrapped);
            } else {
                $result .= $this->error;
            }
        }

        // Final reset
        $result .= Ansi::reset();

        return rtrim($result, "\n");
    }

    /**
     * Get the content lines for the textarea.
     *
     * @return list<string>
     */
    private function getContentLines(int $contentWidth, int $maxLines): array
    {
        $value = $this->value ?? '';

        if ($value === '') {
            if ($this->placeholder !== null) {
                $placeholderLines = $this->wrapText($this->placeholder, $contentWidth - 1);
                // Show placeholder only in first line
                if (count($placeholderLines) > 0) {
                    $placeholderLines[0] = $this->placeholderColor?->toFg(ColorProfile::TrueColor)
                        . $placeholderLines[0]
                        . ($this->textColor?->toFg(ColorProfile::TrueColor) ?? '');
                }
                // Pad to maxLines
                while (count($placeholderLines) < $maxLines) {
                    $placeholderLines[] = '';
                }
                return array_slice($placeholderLines, 0, $maxLines);
            }
            return array_fill(0, $maxLines, '');
        }

        // Wrap the actual content
        $wrapped = $this->wrapText($value, $contentWidth - 1);

        // Pad or truncate to match rows
        while (count($wrapped) < $maxLines) {
            $wrapped[] = '';
        }

        return array_slice($wrapped, 0, $maxLines);
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
     * Get the style characters for the textarea border.
     *
     * @return array{0:string, 1:string, 2:string, 3:string, 4:string, 5:string}
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
     * Calculate the natural dimensions of this textarea.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? 50;
        $width = max($useWidth, 5);
        $height = 2 + $this->rows; // top border, rows, bottom border

        if ($this->error !== null) {
            $errorLines = $this->wrapText($this->error, $width - 2);
            $height += count($errorLines);
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the textarea value.
     */
    public function withValue(?string $value): self
    {
        return new self(
            value: $value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the placeholder text.
     */
    public function withPlaceholder(?string $placeholder): self
    {
        return new self(
            value: $this->value,
            placeholder: $placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the label text.
     */
    public function withLabel(?string $label): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the error message.
     */
    public function withError(?string $error): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $color,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the text color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $color,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the placeholder color.
     */
    public function withPlaceholderColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $color,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $color,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the number of rows.
     */
    public function withRows(int $rows): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: max(1, $rows),
            maxRows: $this->maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the maximum number of rows.
     */
    public function withMaxRows(?int $maxRows): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $maxRows,
            style: $this->style,
        );
    }

    /**
     * Set the border style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            value: $this->value,
            placeholder: $this->placeholder,
            label: $this->label,
            error: $this->error,
            borderColor: $this->borderColor,
            textColor: $this->textColor,
            placeholderColor: $this->placeholderColor,
            backgroundColor: $this->backgroundColor,
            rows: $this->rows,
            maxRows: $this->maxRows,
            style: $style,
        );
    }
}
