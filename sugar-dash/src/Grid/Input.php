<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A text input field component.
 *
 * Features:
 * - Display a text input with optional label
 * - Placeholder text support
 * - Focus state styling
 * - Error state with error message
 * - Customizable border and text colors
 * - Password masking support
 *
 * Mirrors text input UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Input implements Sizer
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
        private readonly bool $masked = false,
        private readonly string $style = 'single',
    ) {}

    /**
     * Create a new input with default styling.
     *
     * Default: single border, gray placeholder, purple border on focus.
     */
    public static function new(?string $value = null): self
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
            masked: false,
            style: 'single',
        );
    }

    /**
     * Create an input with a label.
     */
    public static function labeled(?string $value, string $label): self
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
            masked: false,
            style: 'single',
        );
    }

    /**
     * Create a password input.
     */
    public static function password(?string $value = null): self
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
            masked: true,
            style: 'single',
        );
    }

    /**
     * Set the allocated dimensions for this input.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the input as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 30;
        $useWidth = max($useWidth, 3);

        $contentWidth = $useWidth - 2;

        // Determine border characters based on style
        [$tl, $tr, $bl, $br, $h, $v] = $this->getStyleChars();

        $result = '';

        // Render label above input if present
        if ($this->label !== null) {
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

        // Middle line with value/placeholder
        $displayValue = $this->getDisplayValue();
        $lineContent = $v . ' ' . $displayValue;
        $lineWidth = Width::string($displayValue) + 1;

        if ($lineWidth < $contentWidth) {
            $lineContent .= str_repeat(' ', $contentWidth - $lineWidth);
        } elseif ($lineWidth > $contentWidth) {
            // Truncate if too long
            $lineContent = $v . ' ' . mb_substr($displayValue, 0, $contentWidth - 1, 'UTF-8');
        }

        // Apply text color
        if ($this->textColor !== null) {
            $result .= $this->textColor->toFg(ColorProfile::TrueColor);
        }
        $result .= $lineContent . $v . "\n";

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

        return $result;
    }

    /**
     * Get the display value (masked if needed).
     */
    private function getDisplayValue(): string
    {
        $value = $this->value ?? '';

        if ($value === '' && $this->placeholder !== null) {
            // Show placeholder in placeholder color
            if ($this->placeholderColor !== null) {
                return $this->placeholderColor->toFg(ColorProfile::TrueColor) . $this->placeholder . Ansi::reset();
            }
            return $this->placeholder;
        }

        if ($this->masked && $value !== '') {
            return str_repeat('●', Width::string($value));
        }

        return $value;
    }

    /**
     * Get the style characters for the input border.
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
     * Calculate the natural dimensions of this input.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $useWidth = $this->width ?? 30;

        $width = max($useWidth, 3);
        $height = 3; // Top border, middle, bottom border

        if ($this->error !== null) {
            $errorLines = $this->wrapText($this->error, $width - 2);
            $height += count($errorLines);
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the input value.
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
            masked: $this->masked,
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
            masked: $this->masked,
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
            masked: $this->masked,
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
            masked: $this->masked,
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
            masked: $this->masked,
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
            masked: $this->masked,
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
            masked: $this->masked,
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
            masked: $this->masked,
            style: $this->style,
        );
    }

    /**
     * Set the masked state (for passwords).
     */
    public function withMasked(bool $masked): self
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
            masked: $masked,
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
            masked: $this->masked,
            style: $style,
        );
    }

    /**
     * Set the input width.
     */
    public function withWidth(int $width): self
    {
        $clone = clone $this;
        $clone->width = $width;
        return $clone;
    }
}
