<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Color;

/**
 * A metric card for the metrics grid.
 */
final readonly class MetricCard
{
    public function __construct(
        public string $label,
        public ?string $value = null,
        public ?string $trendValue = null,
        public ?string $trend = null,
        public ?Color $color = null,
    ) {}

    /**
     * Create a card from a number value.
     */
    public static function fromNumber(string $label, float $number, int $decimalPlaces = 0): self
    {
        $formatted = number_format($number, $decimalPlaces);
        return new self(label: $label, value: $formatted);
    }

    /**
     * Create a percentage card.
     */
    public static function percent(string $label, float $value): self
    {
        $formatted = number_format($value, 1) . '%';
        return new self(label: $label, value: $formatted);
    }

    /**
     * Create a currency card.
     */
    public static function currency(string $label, float $value, string $symbol = '$'): self
    {
        $formatted = $symbol . number_format($value, 2);
        return new self(label: $label, value: $formatted);
    }
}
