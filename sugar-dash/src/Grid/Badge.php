<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A status badge / tag component.
 *
 * Displays a labeled badge with customizable styling:
 * - Multiple styles (solid, outlined, subtle)
 * - Custom colors for foreground and background
 * - Optional padding around the label
 * - Rounded or square appearance
 *
 * Mirrors the badge/tag concept from lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Badge implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $label,
        private readonly ?Color $foregroundColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly bool $outlined = false,
        private readonly string $padding = ' ',
    ) {}

    /**
     * Create a new badge with default styling.
     *
     * Default: purple badge with white text.
     */
    public static function new(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#874BFD'),
            outlined: false,
            padding: ' ',
        );
    }

    /**
     * Create a success-style badge.
     */
    public static function success(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#22C55E'),
            outlined: false,
            padding: ' ',
        );
    }

    /**
     * Create a warning-style badge.
     */
    public static function warning(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#F59E0B'),
            outlined: false,
            padding: ' ',
        );
    }

    /**
     * Create a danger/error-style badge.
     */
    public static function danger(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#EF4444'),
            outlined: false,
            padding: ' ',
        );
    }

    /**
     * Create an info-style badge.
     */
    public static function info(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#3B82F6'),
            outlined: false,
            padding: ' ',
        );
    }

    /**
     * Set the allocated dimensions for this badge.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the badge as a string.
     */
    public function render(): string
    {
        $paddedLabel = $this->padding . $this->label . $this->padding;
        $labelWidth = Width::string($paddedLabel);

        // Use allocated width if set and larger than content
        $totalWidth = $this->width !== null && $this->width > $labelWidth
            ? $this->width
            : $labelWidth;

        // Calculate horizontal padding to center or align
        $horizontalPad = $totalWidth - $labelWidth;
        $leftPad = (int) floor($horizontalPad / 2);
        $rightPad = $horizontalPad - $leftPad;

        $leftStr = str_repeat(' ', $leftPad);
        $rightStr = str_repeat(' ', $rightPad);

        $result = '';

        if ($this->outlined) {
            // Outlined style: border with color, text with foreground color
            $topBorder = '┌' . str_repeat('─', $totalWidth - 2) . '┐';
            $middle = '│' . $leftStr . $paddedLabel . $rightStr . '│';
            $bottomBorder = '└' . str_repeat('─', $totalWidth - 2) . '┘';

            if ($this->foregroundColor !== null) {
                $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $topBorder . "\n" . $middle . "\n" . $bottomBorder;
        } else {
            // Filled style: background color, foreground text
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
            }
            if ($this->foregroundColor !== null) {
                $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $leftStr . $paddedLabel . $rightStr;
        }

        // Ensure ANSI reset at the end if colors were used
        if ($this->foregroundColor !== null || $this->backgroundColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this badge.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $labelWidth = Width::string($this->padding . $this->label . $this->padding);
        $width = $this->width !== null ? max($this->width, $labelWidth) : $labelWidth;
        $height = $this->outlined ? 3 : 1;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set a custom label.
     */
    public function withLabel(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            outlined: $this->outlined,
            padding: $this->padding,
        );
    }

    /**
     * Set the foreground (text) color.
     */
    public function withForegroundColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $color,
            backgroundColor: $this->backgroundColor,
            outlined: $this->outlined,
            padding: $this->padding,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $color,
            outlined: $this->outlined,
            padding: $this->padding,
        );
    }

    /**
     * Set the outlined style.
     */
    public function withOutlined(bool $outlined): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            outlined: $outlined,
            padding: $this->padding,
        );
    }

    /**
     * Set the padding character(s) around the label.
     */
    public function withPadding(string $padding): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            outlined: $this->outlined,
            padding: $padding,
        );
    }
}
