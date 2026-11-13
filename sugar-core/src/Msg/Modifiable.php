<?php

declare(strict_types=1);

namespace SugarCraft\Core\Msg;

use SugarCraft\Core\Modifiers;

/**
 * One idiom for reading modifier state off BOTH event families —
 * key events ({@see KeyMsg}, bundling booleans into a {@see Modifiers}
 * value) and mouse events ({@see MouseMsg}, storing the same three
 * booleans directly). Before this interface existed, a consumer that
 * cared about "ctrl pressed" had to branch on the event class, and the
 * mouse family had no bundler at all.
 *
 * The contract is deliberately tiny and additive: the booleans stay
 * public and unchanged (zero breakage), this interface just names the
 * one way to ASK.
 *
 * sc-23 (finding sugar-core-024): ship-default was "shared predicate",
 * not "collapse both families onto the bitmask field" — the field
 * collapse would be a breaking wire-shape change to every constructor
 * call site in the fleet for zero additional reading power.
 */
interface Modifiable
{
    /**
     * Bundle this event's modifier state into a single value.
     */
    public function modifiers(): Modifiers;

    /**
     * True when the given modifier bit ({@see Modifiers::SHIFT},
     * {@see Modifiers::ALT} or {@see Modifiers::CTRL}) is set on this
     * event. Unknown bits throw rather than reading as "not held".
     */
    public function has(int $modifier): bool;
}
