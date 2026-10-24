<?php

declare(strict_types=1);

namespace SugarCraft\Dashboard\Plot\Chart;

use SugarCraft\Core\Util\Color;

final readonly class OHLCPoint
{
    public function __construct(
        public string $label,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public ?Color $color = null,
    ) {}

    public static function bullish(string $label, float $open, float $high, float $low, float $close): self
    {
        return new self($label, $open, $high, $low, $close, Color::hex('#A6E3A1'));
    }

    public static function bearish(string $label, float $open, float $high, float $low, float $close): self
    {
        return new self($label, $open, $high, $low, $close, Color::hex('#F38BA8'));
    }

    public function isBullish(): bool
    {
        return $this->close >= $this->open;
    }

    public function range(): float
    {
        return $this->high - $this->low;
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::range()} instead. REMOVED-IN: 2.0.0.
     */
    public function getRange(): float { return $this->range(); }

    public function bodySize(): float
    {
        return abs($this->close - $this->open);
    }

    /**
     * BC forwarder for the pre-rename accessor name.
     *
     * @deprecated The `get`-prefixed form breaks the house bare-accessor
     *     convention; kept only as a BC forwarder. Use
     *     {@see self::bodySize()} instead. REMOVED-IN: 2.0.0.
     */
    public function getBodySize(): float { return $this->bodySize(); }
}
