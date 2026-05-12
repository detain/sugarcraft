<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A form label component.
 *
 * Features:
 * - Display text labels for form fields
 * - Optional required indicator
 * - Optional help text
 * - Customizable color
 * - Optional icon prefix
 * - Horizontal or vertical layout support
 *
 * Mirrors form label UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Label implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $text,
        private readonly bool $required = false,
        private readonly ?string $helpText = null,
        private readonly ?Color $color = null,
        private readonly string $requiredIndicator = ' *',
    ) {}

    /**
     * Create a new label with default styling.
     *
     * Default: dark text, no required indicator.
     */
    public static function new(string $text): self
    {
        return new self(
            text: $text,
            required: false,
            helpText: null,
            color: Color::hex('#D1D5DB'),
            requiredIndicator: ' *',
        );
    }

    /**
     * Create a required label.
     */
    public static function required(string $text): self
    {
        return new self(
            text: $text,
            required: true,
            helpText: null,
            color: Color::hex('#D1D5DB'),
            requiredIndicator: ' *',
        );
    }

    /**
     * Create a label with help text.
     */
    public static function withHelp(string $text, string $helpText): self
    {
        return new self(
            text: $text,
            required: false,
            helpText: $helpText,
            color: Color::hex('#D1D5DB'),
            requiredIndicator: ' *',
        );
    }

    /**
     * Set the allocated dimensions for this label.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the label as a string.
     */
    public function render(): string
    {
        $useWidth = $this->width ?? 0;

        $result = '';

        // Apply color if set
        if ($this->color !== null) {
            $result .= $this->color->toFg(ColorProfile::TrueColor);
        }

        // Build the label text
        $labelText = $this->text;
        if ($this->required) {
            $labelText .= $this->requiredIndicator;
        }

        // Wrap text if width is allocated and text exceeds it
        $contentWidth = $useWidth > 0 ? $useWidth : Width::string($labelText);

        if ($useWidth > 0 && Width::string($labelText) > $useWidth) {
            $wrapped = $this->wrapText($labelText, $useWidth);
            $result .= implode("\n", $wrapped);
        } else {
            $result .= $labelText;
        }

        // Add help text on a new line if present
        if ($this->helpText !== null) {
            $result .= "\n";
            $helpTextWidth = $useWidth > 0 ? $useWidth - 2 : Width::string($this->helpText);
            if ($helpTextWidth > 0 && Width::string($this->helpText) > $helpTextWidth) {
                $wrapped = $this->wrapText($this->helpText, $helpTextWidth);
                $result .= '  ' . implode("\n  ", $wrapped);
            } else {
                $result .= '  ' . $this->helpText;
            }
        }

        // Reset ANSI
        if ($this->color !== null) {
            $result .= Ansi::reset();
        }

        return $result;
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
     * Calculate the natural dimensions of this label.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $labelText = $this->text;
        if ($this->required) {
            $labelText .= $this->requiredIndicator;
        }

        $width = Width::string($labelText);
        $height = 1;

        if ($this->helpText !== null) {
            $height++;
            $width = max($width, Width::string($this->helpText) + 2);
        }

        if ($this->width !== null) {
            $width = max($width, $this->width);
        }

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the label text.
     */
    public function withText(string $text): self
    {
        return new self(
            text: $text,
            required: $this->required,
            helpText: $this->helpText,
            color: $this->color,
            requiredIndicator: $this->requiredIndicator,
        );
    }

    /**
     * Set the required state.
     */
    public function withRequired(bool $required): self
    {
        return new self(
            text: $this->text,
            required: $required,
            helpText: $this->helpText,
            color: $this->color,
            requiredIndicator: $this->requiredIndicator,
        );
    }

    /**
     * Set the help text.
     */
    public function withHelpText(?string $helpText): self
    {
        return new self(
            text: $this->text,
            required: $this->required,
            helpText: $helpText,
            color: $this->color,
            requiredIndicator: $this->requiredIndicator,
        );
    }

    /**
     * Set the label color.
     */
    public function withColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            required: $this->required,
            helpText: $this->helpText,
            color: $color,
            requiredIndicator: $this->requiredIndicator,
        );
    }

    /**
     * Set the required indicator text.
     */
    public function withRequiredIndicator(string $indicator): self
    {
        return new self(
            text: $this->text,
            required: $this->required,
            helpText: $this->helpText,
            color: $this->color,
            requiredIndicator: $indicator,
        );
    }
}
