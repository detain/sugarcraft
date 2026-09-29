<?php

declare(strict_types=1);

namespace SugarCraft\Dash\Components\Card;

use SugarCraft\Core\Util\Color;

/**
 * Trend direction for metrics.
 */
enum MetricTrend: string
{
    case Up = 'up';
    case Down = 'down';
    case Neutral = 'neutral';

    /**
     * Get the symbol for this trend.
     */
    public function symbol(): string
    {
        return match ($this) {
            self::Up => '▲',
            self::Down => '▼',
            self::Neutral => '●',
        };
    }

    /**
     * Get the default color for this trend.
     */
    public function defaultColor(): Color
    {
        return match ($this) {
            self::Up => Color::hex('#A6E3A1'),
            self::Down => Color::hex('#F38BA8'),
            self::Neutral => Color::hex('#6C7086'),
        };
    }

    /**
     * Determine trend from a delta value.
     */
    public static function fromDelta(float $delta, float $threshold = 0.0): self
    {
        if ($delta > $threshold) {
            return self::Up;
        }
        if ($delta < -$threshold) {
            return self::Down;
        }
        return self::Neutral;
    }
}
