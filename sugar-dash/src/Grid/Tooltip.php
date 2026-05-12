<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A tooltip component.
 *
 * Displays a floating tooltip with:
 * - Customizable text content
 * - Position indicators (top, bottom, left, right)
 * - Border styling with box-drawing characters
 * - Background and foreground colors
 *
 * Mirrors the tooltip concept from bubble-tea/lipgloss but adapted
 * to PHP with wither-style immutable setters.
 */
final class Tooltip implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly string $text,
        private readonly string $position = 'top',
        private readonly ?Color $backgroundColor = null,
        private readonly ?Color $foregroundColor = null,
        private readonly ?Color $borderColor = null,
    ) {}

    /**
     * Create a new tooltip with default styling.
     *
     * Default: purple background, white text, top position.
     */
    public static function new(string $text): self
    {
        return new self(
            text: $text,
            position: 'top',
            backgroundColor: Color::hex('#874BFD'),
            foregroundColor: Color::hex('#FFFFFF'),
            borderColor: null,
        );
    }

    /**
     * Create a tooltip with a dark background.
     */
    public static function dark(string $text): self
    {
        return new self(
            text: $text,
            position: 'top',
            backgroundColor: Color::hex('#1E1E2E'),
            foregroundColor: Color::hex('#CDD6F4'),
            borderColor: null,
        );
    }

    /**
     * Create a tooltip with an info style.
     */
    public static function info(string $text): self
    {
        return new self(
            text: $text,
            position: 'top',
            backgroundColor: Color::hex('#3B82F6'),
            foregroundColor: Color::hex('#FFFFFF'),
            borderColor: null,
        );
    }

    /**
     * Create a tooltip with a warning style.
     */
    public static function warning(string $text): self
    {
        return new self(
            text: $text,
            position: 'top',
            backgroundColor: Color::hex('#F59E0B'),
            foregroundColor: Color::hex('#FFFFFF'),
            borderColor: null,
        );
    }

    /**
     * Create a tooltip with a danger/error style.
     */
    public static function danger(string $text): self
    {
        return new self(
            text: $text,
            position: 'top',
            backgroundColor: Color::hex('#EF4444'),
            foregroundColor: Color::hex('#FFFFFF'),
            borderColor: null,
        );
    }

    /**
     * Set the allocated dimensions for this tooltip.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the tooltip as a string.
     */
    public function render(): string
    {
        $textWidth = Width::string($this->text);
        $totalWidth = $this->width !== null && $this->width > $textWidth
            ? $this->width
            : $textWidth + 4; // padding for border and internal spacing

        $positionArrow = $this->getPositionArrow($totalWidth);

        // Build the tooltip box
        $result = '';

        // Apply colors
        if ($this->backgroundColor !== null) {
            $result .= $this->backgroundColor->toBg(ColorProfile::TrueColor);
        }
        if ($this->foregroundColor !== null) {
            $result .= $this->foregroundColor->toFg(ColorProfile::TrueColor);
        }

        // Top border
        $innerWidth = max(1, $totalWidth - 2);
        $result .= '┌' . str_repeat('─', $innerWidth) . '┐' . "\n";

        // Text line with padding
        $textPad = $innerWidth - $textWidth;
        $leftPad = (int) floor($textPad / 2);
        $rightPad = $textPad - $leftPad;
        $result .= '│' . str_repeat(' ', $leftPad) . $this->text . str_repeat(' ', $rightPad) . '│' . "\n";

        // Bottom border
        $result .= '└' . str_repeat('─', $innerWidth) . '┘';

        // Position indicator (arrow)
        $result .= "\n" . $positionArrow;

        // Reset colors
        if ($this->backgroundColor !== null || $this->foregroundColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Get the position indicator arrow.
     */
    private function getPositionArrow(int $width): string
    {
        $centerX = (int) floor($width / 2);

        return match ($this->position) {
            'top' => str_repeat(' ', $centerX) . '▲',
            'bottom' => str_repeat(' ', $centerX) . '▼',
            'left' => '◀' . str_repeat(' ', $width),
            'right' => str_repeat(' ', $width) . '▶',
            default => '',
        };
    }

    /**
     * Calculate the natural dimensions of this tooltip.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $textWidth = Width::string($this->text);
        $width = $this->width !== null ? max($this->width, $textWidth + 4) : $textWidth + 4;
        $height = 4; // top border + text + bottom border + position arrow

        return [$width, $height];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the tooltip text.
     */
    public function withText(string $text): self
    {
        return new self(
            text: $text,
            position: $this->position,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
        );
    }

    /**
     * Set the position of the tooltip indicator.
     */
    public function withPosition(string $position): self
    {
        return new self(
            text: $this->text,
            position: $position,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
        );
    }

    /**
     * Set the background color.
     */
    public function withBackgroundColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            position: $this->position,
            backgroundColor: $color,
            foregroundColor: $this->foregroundColor,
            borderColor: $this->borderColor,
        );
    }

    /**
     * Set the foreground (text) color.
     */
    public function withForegroundColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            position: $this->position,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $color,
            borderColor: $this->borderColor,
        );
    }

    /**
     * Set the border color.
     */
    public function withBorderColor(?Color $color): self
    {
        return new self(
            text: $this->text,
            position: $this->position,
            backgroundColor: $this->backgroundColor,
            foregroundColor: $this->foregroundColor,
            borderColor: $color,
        );
    }
}
