<?php

declare(strict_types=1);

namespace SugarCraft\Spring;

/**
 * Predefined spring parameter sets for common animation moods.
 *
 * Each preset encodes tension / friction / mass values that translate
 * to physically meaningful angularFrequency and dampingRatio inside
 * {@see SpringConfig}. The five triples are TUNED BY HAND for feel
 * (the react-spring/RN folk-table lineage); they are not verbatim
 * transcriptions of any published table — UIKit's animator, for
 * instance, exposes duration/damping curves, not tension/friction/mass.
 */
enum SpringPreset
{
    case Gentle;
    case Wobbly;
    case Stiff;
    case Slow;
    case Molasses;

    /**
     * Resolve this preset to a fully constructed SpringConfig. The
     * returned instance is SHARED per preset (immutable by type).
     *
     * SPR-21 (finding 080): one config per preset, remembered — enums
     * cannot declare properties, so the memo is a static local: keys are
     * `$this->name`, structurally bounded to the five cases, and callers
     * resolving inside a loop stop allocating.
     */
    public function resolve(): SpringConfig
    {
        /** @var array<string, SpringConfig> $resolved */
        static $resolved = [];

        return $resolved[$this->name] ??= match ($this) {
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
