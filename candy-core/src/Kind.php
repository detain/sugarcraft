<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Subscription kind taxonomy.
 *
 * Mirrors Elm/Bubble Tea subscription categories.
 */
enum Kind
{
    /** Wall-clock tick at a given interval. */
    case Tick;
    /** Keyboard event subscription. */
    case Key;
    /** System signal subscription. */
    case Signal;
    /** Arbitrary custom subscription. */
    case Custom;
}
