<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\Color;
use SugarCraft\Core\Util\ColorProfile;

/**
 * An analog-style meter/gauge component.
 *
 * Displays a ratio as a vertical meter with a needle indicator,
 * similar to VU meters or analog voltmeters. The meter shows
 * a vertical scale with tick marks and a needle pointing to
 * the current value.
 *
 * Mirrors analog meter concepts adapted to PHP with
 * wither-style immutable setters.
 */
final class Meter implements \SugarCraft\Dash\Foundation\Sizer
{
    private ?int $sizerWidth = null;
    private ?int $sizerHeight = null;

    /**
     * Meter scale characters.
     */
    private const SCALE_CHARS = [
        0 => '۝', // High mark
        1 => '｜', // Major tick
        2 => '·', // Minor tick
    ];

    /**
     * Needle characters (pointing left, centered on position).
     */
    private const NEEDLE = '❮';

    public function __construct(
        private readonly float $ratio,
        private readonly int $meterHeight = 12,
        private readonly int $meterWidth = 5,
        private readonly bool $showNeedle = true,
        private readonly bool $showScale = true,
        private readonly bool $showLabel = true,
        private readonly ?Color $meterColor = null,
        private readonly ?Color $needleColor = null,
        private readonly ?Color $scaleColor = null,
    ) {}

    /**
     * Create a new analog meter with default styling.
     *
     * Default: purple meter, red needle, 12 rows tall, 5 chars wide.
     */
    public static function new(float $ratio): self
    {
        return new self(
            ratio: max(0.0, min(1.0, $ratio)),
            meterHeight: 12,
            meterWidth: 5,
            showNeedle: true,
            showScale: true,
            showLabel: true,
            meterColor: Color::hex('#874BFD'),
            needleColor: Color::hex('#FF6B6B'),
            scaleColor: Color::hex('#888888'),
        );
    }

    /**
     * Set the allocated dimensions for this meter.
     */
    public function setSize(int $width, int $height): \SugarCraft\Dash\Foundation\Sizer
    {
        $clone = clone $this;
        $clone->sizerWidth = $width;
        $clone->sizerHeight = $height;
        return $clone;
    }

    /**
     * Render the analog meter.
     */
    public function render(): string
    {
        $ratio = max(0.0, min(1.0, $this->ratio));
        $meterHeight = $this->meterHeight;
        $meterWidth = $this->meterWidth;

        // Ensure minimum dimensions
        $meterHeight = max(5, $meterHeight);
        $meterWidth = max(3, $meterWidth);

        $result = '';
        $needleY = (int) round((1.0 - $ratio) * ($meterHeight - 1));
        $needleY = max(1, min($meterHeight - 2, $needleY));

        for ($y = 0; $y < $meterHeight; $y++) {
            $row = '';

            // Scale column (left side)
            if ($this->showScale) {
                $scaleRatio = 1.0 - ($y / ($meterHeight - 1));
                $isMajorTick = ($y % 3 === 0);
                $tickChar = $isMajorTick ? '』' : '·';

                if ($this->scaleColor !== null) {
                    $row .= $this->scaleColor->toFg(ColorProfile::TrueColor);
                }
                $row .= $tickChar;
                if ($this->scaleColor !== null) {
                    $row .= Ansi::reset();
                }
            }

            // Meter body column
            $isActive = ($y <= $needleY);
            $bodyChar = ($y === 0 || $y === $meterHeight - 1) ? '─' : '│';

            if ($isActive && $this->meterColor !== null) {
                $row .= $this->meterColor->toFg(ColorProfile::TrueColor);
                $row .= '█';
                $row .= Ansi::reset();
            } elseif ($this->meterColor !== null) {
                $row .= $this->meterColor->toFg(ColorProfile::TrueColor);
                $row .= '░';
                $row .= Ansi::reset();
            } else {
                $row .= $isActive ? '█' : '░';
            }

            // Needle column
            if ($this->showNeedle && $y === $needleY) {
                if ($this->needleColor !== null) {
                    $row .= $this->needleColor->toFg(ColorProfile::TrueColor);
                }
                $row .= self::NEEDLE;
                if ($this->needleColor !== null) {
                    $row .= Ansi::reset();
                }
            } else {
                $row .= ' ';
            }

            // Right side indicator column
            if ($y === $needleY) {
                // Value indicator
                if ($this->needleColor !== null) {
                    $row .= $this->needleColor->toFg(ColorProfile::TrueColor);
                }
                $row .= '●';
                if ($this->needleColor !== null) {
                    $row .= Ansi::reset();
                }
            } else {
                $row .= ' ';
            }

            $result .= $row . "\n";
        }

        // Add percentage label at bottom
        if ($this->showLabel) {
            $percentage = (int) round($ratio * 100);
            $label = sprintf(' %d%% ', $percentage);

            // Center the label under the meter
            $labelWidth = mb_strlen($label, 'UTF-8');
            $padding = max(0, (int) floor(($meterWidth - $labelWidth + 2) / 2));
            $label = str_repeat(' ', $padding) . $label;

            if ($this->needleColor !== null) {
                $label = $this->needleColor->toFg(ColorProfile::TrueColor) . $label . Ansi::reset();
            }
            $result .= $label;
        }

        return rtrim($result, "\n");
    }

    /**
     * Calculate the natural dimensions of this meter.
     *
     * @return array{0:int,1:int} [width, height]
     */
    public function getInnerSize(): array
    {
        $labelHeight = $this->showLabel ? 1 : 0;
        return [$this->meterWidth, $this->meterHeight + $labelHeight];
    }

    // ─── Withers ──────────────────────────────────────────────────

    /**
     * Set the meter height.
     */
    public function withHeight(int $height): self
    {
        return new self(
            ratio: $this->ratio,
            meterHeight: max(5, $height),
            meterWidth: $this->meterWidth,
            showNeedle: $this->showNeedle,
            showScale: $this->showScale,
            showLabel: $this->showLabel,
            meterColor: $this->meterColor,
            needleColor: $this->needleColor,
            scaleColor: $this->scaleColor,
        );
    }

    /**
     * Set the meter width.
     */
    public function withWidth(int $width): self
    {
        return new self(
            ratio: $this->ratio,
            meterHeight: $this->meterHeight,
            meterWidth: max(3, $width),
            showNeedle: $this->showNeedle,
            showScale: $this->showScale,
            showLabel: $this->showLabel,
            meterColor: $this->meterColor,
            needleColor: $this->needleColor,
            scaleColor: $this->scaleColor,
        );
    }

    /**
     * Show or hide the needle.
     */
    public function withShowNeedle(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            meterHeight: $this->meterHeight,
            meterWidth: $this->meterWidth,
            showNeedle: $show,
            showScale: $this->showScale,
            showLabel: $this->showLabel,
            meterColor: $this->meterColor,
            needleColor: $this->needleColor,
            scaleColor: $this->scaleColor,
        );
    }

    /**
     * Show or hide the scale marks.
     */
    public function withShowScale(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            meterHeight: $this->meterHeight,
            meterWidth: $this->meterWidth,
            showNeedle: $this->showNeedle,
            showScale: $show,
            showLabel: $this->showLabel,
            meterColor: $this->meterColor,
            needleColor: $this->needleColor,
            scaleColor: $this->scaleColor,
        );
    }

    /**
     * Show or hide the percentage label.
     */
    public function withShowLabel(bool $show): self
    {
        return new self(
            ratio: $this->ratio,
            meterHeight: $this->meterHeight,
            meterWidth: $this->meterWidth,
            showNeedle: $this->showNeedle,
            showScale: $this->showScale,
            showLabel: $show,
            meterColor: $this->meterColor,
            needleColor: $this->needleColor,
            scaleColor: $this->scaleColor,
        );
    }

    /**
     * Set the ratio value.
     */
    public function withRatio(float $ratio): self
    {
        return new self(
            ratio: max(0.0, min(1.0, $ratio)),
            meterHeight: $this->meterHeight,
            meterWidth: $this->meterWidth,
            showNeedle: $this->showNeedle,
            showScale: $this->showScale,
            showLabel: $this->showLabel,
            meterColor: $this->meterColor,
            needleColor: $this->needleColor,
            scaleColor: $this->scaleColor,
        );
    }

    /**
     * Set the meter body color.
     */
    public function withMeterColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            meterHeight: $this->meterHeight,
            meterWidth: $this->meterWidth,
            showNeedle: $this->showNeedle,
            showScale: $this->showScale,
            showLabel: $this->showLabel,
            meterColor: $color,
            needleColor: $this->needleColor,
            scaleColor: $this->scaleColor,
        );
    }

    /**
     * Set the needle color.
     */
    public function withNeedleColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            meterHeight: $this->meterHeight,
            meterWidth: $this->meterWidth,
            showNeedle: $this->showNeedle,
            showScale: $this->showScale,
            showLabel: $this->showLabel,
            meterColor: $this->meterColor,
            needleColor: $color,
            scaleColor: $this->scaleColor,
        );
    }

    /**
     * Set the scale color.
     */
    public function withScaleColor(?Color $color): self
    {
        return new self(
            ratio: $this->ratio,
            meterHeight: $this->meterHeight,
            meterWidth: $this->meterWidth,
            showNeedle: $this->showNeedle,
            showScale: $this->showScale,
            showLabel: $this->showLabel,
            meterColor: $this->meterColor,
            needleColor: $this->needleColor,
            scaleColor: $color,
        );
    }
}
