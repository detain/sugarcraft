<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Core\Util\Width;

/**
 * A toggle switch component.
 *
 * Features:
 * - On/off states with visual indicators
 * - Custom labels for on/off states
 * - Configurable width
 * - Color customization
 *
 * Mirrors toggle UI concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Toggle implements Sizer
{
    private ?int $width = null;
    private ?int $height = null;

    public function __construct(
        private readonly bool $on = false,
        private readonly ?string $onLabel = null,
        private readonly ?string $offLabel = null,
        private readonly ?Color $onColor = null,
        private readonly ?Color $offColor = null,
        private readonly ?Color $trackColor = null,
    ) {}

    /**
     * Create a new toggle with default styling.
     */
    public static function new(bool $on = false): self
    {
        return new self(
            on: $on,
            onLabel: null,
            offLabel: null,
            onColor: Color::hex('#22C55E'),
            offColor: Color::hex('#6B7280'),
            trackColor: null,
        );
    }

    /**
     * Create an "on" toggle.
     */
    public static function on(): self
    {
        return self::new(true);
    }

    /**
     * Create an "off" toggle.
     */
    public static function off(): self
    {
        return self::new(false);
    }

    /**
     * Set the allocated dimensions for this toggle.
     */
    public function setSize(int $width, int $height): Sizer
    {
        $clone = clone $this;
        $clone->width = $width;
        $clone->height = $height;
        return $clone;
    }

    /**
     * Render the toggle as a string.
     */
    public function render(): string
    {
        $onLabel = $this->onLabel ?? 'ON';
        $offLabel = $this->offLabel ?? 'OFF';

        $result = '';

        if ($this->on) {
            // ON state
            if ($this->trackColor !== null) {
                $result .= $this->trackColor->toBg(ColorProfile::TrueColor);
            }
            if ($this->onColor !== null) {
                $result .= $this->onColor->toFg(ColorProfile::TrueColor);
            }
            $result .= '●';
            $result .= Ansi::reset();
            $result .= ' ' . $onLabel;
        } else {
            // OFF state
            if ($this->trackColor !== null) {
                $result .= $this->trackColor->toBg(ColorProfile::TrueColor);
            }
            if ($this->offColor !== null) {
                $result .= $this->offColor->toFg(ColorProfile::TrueColor);
            }
            $result .= '○';
            $result .= Ansi::reset();
            $result .= ' ' . $offLabel;
        }

        return $result;
    }

    /**
     * Calculate the natural dimensions of this toggle.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $onLabel = $this->onLabel ?? 'ON';
        $offLabel = $this->offLabel ?? 'OFF';

        $contentWidth = 1 + 1 + max(Width::string($onLabel), Width::string($offLabel));

        return [$contentWidth, 1];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the on/off state.
     */
    public function withOn(bool $on): self
    {
        return new self(
            on: $on,
            onLabel: $this->onLabel,
            offLabel: $this->offLabel,
            onColor: $this->onColor,
            offColor: $this->offColor,
            trackColor: $this->trackColor,
        );
    }

    /**
     * Set custom labels.
     */
    public function withLabels(string $on, string $off): self
    {
        return new self(
            on: $this->on,
            onLabel: $on,
            offLabel: $off,
            onColor: $this->onColor,
            offColor: $this->offColor,
            trackColor: $this->trackColor,
        );
    }

    /**
     * Set the "on" color.
     */
    public function withOnColor(?Color $color): self
    {
        return new self(
            on: $this->on,
            onLabel: $this->onLabel,
            offLabel: $this->offLabel,
            onColor: $color,
            offColor: $this->offColor,
            trackColor: $this->trackColor,
        );
    }

    /**
     * Set the "off" color.
     */
    public function withOffColor(?Color $color): self
    {
        return new self(
            on: $this->on,
            onLabel: $this->onLabel,
            offLabel: $this->offLabel,
            onColor: $this->onColor,
            offColor: $color,
            trackColor: $this->trackColor,
        );
    }

    /**
     * Set the track background color.
     */
    public function withTrackColor(?Color $color): self
    {
        return new self(
            on: $this->on,
            onLabel: $this->onLabel,
            offLabel: $this->offLabel,
            onColor: $this->onColor,
            offColor: $this->offColor,
            trackColor: $color,
        );
    }
}
