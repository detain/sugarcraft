<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A tag / chip component for displaying labels and metadata.
 *
 * Features:
 * - Compact inline display
 * - Multiple styles (solid, soft, outlined)
 * - Customizable foreground and background colors
 * - Optional delete/close indicator
 * - Optional icon prefix
 *
 * Mirrors tag/chip UI concepts adapted to PHP with wither-style immutable setters.
 */
final class Tag implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $label,
        private readonly ?Color $foregroundColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly string $style = 'solid',
        private readonly string $icon = '',
    ) {}

    /**
     * Create a new tag with default styling.
     *
     * Default: purple tag with white text.
     */
    public static function new(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#874BFD'),
            style: 'solid',
            icon: '',
        );
    }

    /**
     * Create a success-style tag.
     */
    public static function success(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#22C55E'),
            style: 'solid',
            icon: '',
        );
    }

    /**
     * Create a warning-style tag.
     */
    public static function warning(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#F59E0B'),
            style: 'solid',
            icon: '',
        );
    }

    /**
     * Create a danger-style tag.
     */
    public static function danger(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#EF4444'),
            style: 'solid',
            icon: '',
        );
    }

    /**
     * Create an info-style tag.
     */
    public static function info(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#3B82F6'),
            style: 'solid',
            icon: '',
        );
    }

    /**
     * Create a soft-style tag (lighter background).
     */
    public static function soft(string $label, Color $backgroundColor): self
    {
        // Calculate a lighter foreground from the background
        $fg = Color::hex('#FFFFFF');

        return new self(
            label: $label,
            foregroundColor: $fg,
            backgroundColor: $backgroundColor,
            style: 'soft',
            icon: '',
        );
    }

    /**
     * Set the allocated dimensions for this tag.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the tag as a string.
     */
    public function render(): string
    {
        $content = $this->icon !== '' ? $this->icon . ' ' . $this->label : $this->label;
        $contentWidth = Width::string($content);

        $useWidth = $this->width ?? null;
        $totalWidth = $useWidth !== null && $useWidth > $contentWidth ? $useWidth : $contentWidth;

        $horizontalPad = $totalWidth - $contentWidth;
        $leftPad = (int) floor($horizontalPad / 2);
        $rightPad = $horizontalPad - $leftPad;

        $leftStr = str_repeat(' ', $leftPad);
        $rightStr = str_repeat(' ', $rightPad);

        $result = '';

        if ($this->style === 'outlined') {
            // Outlined style with border
            $topBorder = '┌' . str_repeat('─', $totalWidth - 2) . '┐';
            $middle = '│' . $leftStr . $content . $rightStr . '│';
            $bottomBorder = '└' . str_repeat('─', $totalWidth - 2) . '┘';

            if ($this->foregroundColor !== null) {
                $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $topBorder . "\n" . $middle . "\n" . $bottomBorder;
        } elseif ($this->style === 'soft') {
            // Soft style - subtle background
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
            }
            if ($this->foregroundColor !== null) {
                $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $leftStr . $content . $rightStr;
        } else {
            // Solid style - filled background
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
            }
            if ($this->foregroundColor !== null) {
                $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
            }
            $result .= $leftStr . $content . $rightStr;
        }

        // Reset ANSI
        if ($this->foregroundColor !== null || $this->backgroundColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this tag.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $content = $this->icon !== '' ? $this->icon . ' ' . $this->label : $this->label;
        $contentWidth = Width::string($content);

        $width = $this->width !== null ? max($this->width, $contentWidth) : $contentWidth;
        $height = $this->style === 'outlined' ? 3 : 1;

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the tag label.
     */
    public function withLabel(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
            icon: $this->icon,
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
            style: $this->style,
            icon: $this->icon,
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
            style: $this->style,
            icon: $this->icon,
        );
    }

    /**
     * Set the tag style.
     */
    public function withStyle(string $style): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            style: $style,
            icon: $this->icon,
        );
    }

    /**
     * Set the icon prefix.
     */
    public function withIcon(string $icon): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            style: $this->style,
            icon: $icon,
        );
    }
}
