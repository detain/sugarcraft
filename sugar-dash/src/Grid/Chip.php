<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A chip component for compact, selectable items.
 *
 * Features:
 * - Selectable/active states
 * - Optional delete/remove button
 * - Compact rounded appearance
 * - Group support via ChipGroup
 *
 * Mirrors chip UI concepts from Material/Primer adapted to PHP
 * with wither-style immutable setters.
 */
final class Chip implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $label,
        private readonly ?Color $foregroundColor = null,
        private readonly ?Color $backgroundColor = null,
        private readonly bool $selected = false,
        private readonly bool $deletable = false,
        private readonly string $deleteIcon = '×',
    ) {}

    /**
     * Create a new chip with default styling.
     */
    public static function new(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#4B5563'),
            backgroundColor: Color::hex('#E5E7EB'),
            selected: false,
            deletable: false,
            deleteIcon: '×',
        );
    }

    /**
     * Create a primary-style chip.
     */
    public static function primary(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#3B82F6'),
            selected: false,
            deletable: false,
            deleteIcon: '×',
        );
    }

    /**
     * Create a success-style chip.
     */
    public static function success(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#22C55E'),
            selected: false,
            deletable: false,
            deleteIcon: '×',
        );
    }

    /**
     * Create a warning-style chip.
     */
    public static function warning(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#F59E0B'),
            selected: false,
            deletable: false,
            deleteIcon: '×',
        );
    }

    /**
     * Create a danger-style chip.
     */
    public static function danger(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: Color::hex('#FFFFFF'),
            backgroundColor: Color::hex('#EF4444'),
            selected: false,
            deletable: false,
            deleteIcon: '×',
        );
    }

    /**
     * Set the allocated dimensions for this chip.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the chip as a string.
     */
    public function render(): string
    {
        $deleteStr = $this->deletable ? ' ' . $this->deleteIcon : '';
        $content = $this->label . $deleteStr;
        $contentWidth = Width::string($content);

        $useWidth = $this->width ?? null;
        $totalWidth = $useWidth !== null && $useWidth > $contentWidth ? $useWidth : $contentWidth;

        $horizontalPad = $totalWidth - $contentWidth;
        $leftPad = (int) floor($horizontalPad / 2);
        $rightPad = $horizontalPad - $leftPad;

        $leftStr = str_repeat(' ', $leftPad);
        $rightStr = str_repeat(' ', $rightPad);

        $result = '';

        if ($this->selected) {
            // Selected state: uses foreground as background
            if ($this->foregroundColor !== null) {
                $result .= $this->foregroundColor->toBg(ColorProfile::TrueColor);
            }
            // Use contrasting text color for selected state
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toFg(ColorProfile::TrueColor);
            } else {
                $result .= Color::hex('#FFFFFF')->toFg(ColorProfile::TrueColor);
            }
        } else {
            // Normal state
            if ($this->backgroundColor !== null) {
                $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
            }
            if ($this->foregroundColor !== null) {
                $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
            }
        }

        $result .= $leftStr . $content . $rightStr;

        // Reset ANSI
        if ($this->foregroundColor !== null || $this->backgroundColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this chip.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $deleteStr = $this->deletable ? ' ' . $this->deleteIcon : '';
        $content = $this->label . $deleteStr;
        $contentWidth = Width::string($content);

        $width = $this->width !== null ? max($this->width, $contentWidth) : $contentWidth;

        return [$width, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the chip label.
     */
    public function withLabel(string $label): self
    {
        return new self(
            label: $label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            selected: $this->selected,
            deletable: $this->deletable,
            deleteIcon: $this->deleteIcon,
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
            selected: $this->selected,
            deletable: $this->deletable,
            deleteIcon: $this->deleteIcon,
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
            selected: $this->selected,
            deletable: $this->deletable,
            deleteIcon: $this->deleteIcon,
        );
    }

    /**
     * Set the selected state.
     */
    public function withSelected(bool $selected): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            selected: $selected,
            deletable: $this->deletable,
            deleteIcon: $this->deleteIcon,
        );
    }

    /**
     * Set the deletable state.
     */
    public function withDeletable(bool $deletable): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            selected: $this->selected,
            deletable: $deletable,
            deleteIcon: $this->deleteIcon,
        );
    }

    /**
     * Set the delete icon character.
     */
    public function withDeleteIcon(string $icon): self
    {
        return new self(
            label: $this->label,
            foregroundColor: $this->foregroundColor,
            backgroundColor: $this->backgroundColor,
            selected: $this->selected,
            deletable: $this->deletable,
            deleteIcon: $icon,
        );
    }
}
