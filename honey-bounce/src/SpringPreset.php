<?php

declare(strict_types=1);

namespace SugarCraft\Bounce;

/**
 * Predefined spring parameter sets for common animation moods.
 *
 * Each preset encodes tension / friction / mass values that translate
 * to physically meaningful angularFrequency and dampingRatio inside
 * {@see SpringConfig}.
 *
 * @see https://developer.apple.com/documentation/uikit/uiviewpropertyanimator
 *   for the canonical real-world preset values (translated from UIKit).
 */
enum SpringPreset
{
    case Gentle;
    case Wobbly;
    case Stiff;
    case Slow;
    case Molasses;

    /**
     * Resolve this preset to a fully constructed SpringConfig.
     */
    public function resolve(): SpringConfig
    {
        return match ($this) {
            self::Gentle   => new SpringConfig(
                tension: 100.0,
                friction: 10.0,
                mass: 1.0,
            ),
            self::Wobbly  => new SpringConfig(
                tension: 180.0,
                friction: 12.0,
                mass: 1.0,
            ),
            self::Stiff   => new SpringConfig(
                tension: 500.0,
                friction: 20.0,
                mass: 1.0,
            ),
            self::Slow    => new SpringConfig(
                tension:  50.0,
                friction:  6.0,
                mass: 1.0,
            ),
            self::Molasses => new SpringConfig(
                tension:  30.0,
                friction:  4.0,
                mass: 1.0,
            ),
        };
    }
}
