<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * An on/off switch component with slider-style display.
 *
 * Features:
 * - Bracketed [O ] / [ O] style display
 * - ON/OFF text labels
 * - Color customization for both states
 * - Compact single-line display
 *
 * Mirrors switch UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Switch implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly bool $on = false,
        private readonly ?Color $onColor = null,
        private readonly ?Color $offColor = null,
        private readonly ?Color $textColor = null,
    ) {}

    /**
     * Create a new switch with default styling.
     */
    public static function new(bool $on = false): self
    {
        return new self(
            on: $on,
            onColor: Color::hex('#22C55E'),
            offColor: Color::hex('#6B7280'),
            textColor: null,
        );
    }

    /**
     * Create an "on" switch.
     */
    public static function on(): self
    {
        return self::new(true);
    }

    /**
     * Create an "off" switch.
     */
    public static function off(): self
    {
        return self::new(false);
    }

    /**
     * Set the allocated dimensions for this switch.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the switch as a string.
     *
     * Format: [O ] for off, [ O] for on (with colors)
     */
    public function render(): string
    {
        $result = '';

        // Opening bracket
        if ($this->textColor !== null) {
            $result .= $this->textColor->toFg(ColorProfile::TrueColor);
        }
        $result .= '[';

        if ($this->on) {
            // ON state: space + O
            if ($this->onColor !== null) {
                $result .= $this->onColor->toFg(ColorProfile::TrueColor);
            }
            $result .= ' O';

            // Closing bracket
            if ($this->textColor !== null) {
                $result .= Ansi::reset();
                $result .= $this->textColor->toFg(ColorProfile::TrueColor);
            }
            $result .= ']';
        } else {
            // OFF state: O + space
            if ($this->offColor !== null) {
                $result .= $this->offColor->toFg(ColorProfile::TrueColor);
            }
            $result .= 'O ';

            // Closing bracket
            if ($this->textColor !== null) {
                $result .= Ansi::reset();
                $result .= $this->textColor->toFg(ColorProfile::TrueColor);
            }
            $result .= ']';
        }

        // Reset ANSI
        if ($this->textColor !== null) {
            $result .= Ansi::reset();
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this switch.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        // [ O] or [O ] format = 4 characters
        return [4, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the on/off state.
     */
    public function withOn(bool $on): self
    {
        return new self(
            on: $on,
            onColor: $this->onColor,
            offColor: $this->offColor,
            textColor: $this->textColor,
        );
    }

    /**
     * Set the "on" color.
     */
    public function withOnColor(?Color $color): self
    {
        return new self(
            on: $this->on,
            onColor: $color,
            offColor: $this->offColor,
            textColor: $this->textColor,
        );
    }

    /**
     * Set the "off" color.
     */
    public function withOffColor(?Color $color): self
    {
        return new self(
            on: $this->on,
            onColor: $this->onColor,
            offColor: $color,
            textColor: $this->textColor,
        );
    }

    /**
     * Set the text/bracket color.
     */
    public function withTextColor(?Color $color): self
    {
        return new self(
            on: $this->on,
            onColor: $this->onColor,
            offColor: $this->offColor,
            textColor: $color,
        );
    }
}
