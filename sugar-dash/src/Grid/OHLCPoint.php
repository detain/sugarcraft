<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

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

    public function getRange(): float
    {
        return $this->high - $this->low;
    }

    public function getBodySize(): float
    {
        return abs($this->close - $this->open);
    }
}
