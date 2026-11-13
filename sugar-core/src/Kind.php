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
    /**
     * Wall-clock tick at a given interval. Requires a positive numeric
     * `seconds` param (sugar-core-009: missing/zero/negative is a named
     * refusal, so the timer can never busy-spin).
     */
    case Tick;
    /**
     * Keyboard subscription. Shipped as a fixed-interval POLL (default
     * 100 ms), not an edge-triggered key event (sugar-core-013); the
     * optional `interval` param is the tuning seam. Event-driven rework
     * is owner-gated.
     */
    case Key;
    /** System signal subscription. */
    case Signal;
    /** Arbitrary custom subscription. */
    case Custom;
}
