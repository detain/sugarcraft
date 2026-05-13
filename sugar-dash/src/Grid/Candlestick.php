<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Grid;

use SugarCraft\Core\Util\Color;

final class Candlestick
{
    public function __construct(
        public readonly string $label,
        public readonly float $open,
        public readonly float $high,
        public readonly float $low,
        public readonly float $close,
        public readonly ?Color $color = null,
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
}
