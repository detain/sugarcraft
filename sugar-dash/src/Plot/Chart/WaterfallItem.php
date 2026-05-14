<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Plot\Chart;

use SugarCraft\Core\Util\Color;

final class WaterfallItem
{
    public function __construct(
        public readonly string $label,
        public readonly float $value,
        public readonly WaterfallBarType $type = WaterfallBarType::Positive,
        public readonly ?Color $color = null,
    ) {}

    public static function positive(string $label, float $value): self
    {
        return new self($label, $value, WaterfallBarType::Positive);
    }

    public static function negative(string $label, float $value): self
    {
        return new self($label, $value, WaterfallBarType::Negative);
    }

    public static function total(string $label, float $value): self
    {
        return new self($label, $value, WaterfallBarType::Total);
    }

    public static function subtotal(string $label, float $value): self
    {
        return new self($label, $value, WaterfallBarType::Subtotal);
    }
}