<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Mouse action type — mirrors bubblezone's mouse-action enum.
 *
 * Press / Release form a click pair; Drag fires during a held button;
 * Scroll is a separate axis (not a click).
 */
enum MouseAction: string
{
    /** Button pressed down — starts a potential click. */
    case Press = 'press';

    /** Button released — completes a click if it matches the last Press. */
    case Release = 'release';

    /** Button held and mouse moved — not a click; {@see ZoneClickTracker} suppresses the duplicate presses it fires. */
    case Drag = 'drag';

    /** Scroll wheel activated — not a click; the trackers ignore it. */
    case Scroll = 'scroll';

    /**
     * Button numbers as the terminal wire reports them (the X10/SGR `b`
     * field low bits), kept here so a hand-built {@see MouseEvent} can read
     * naturally.  This is the PRIMITIVE vocabulary: core owns its own
     * MouseButton enum, sugar-input a third spelling, and
     * {@see \SugarCraft\Mouse\Zones\Zone::toMouseEvent()} is the bridge
     * from the core message types to this one.
     */
    public const BUTTON_LEFT = 0;
    public const BUTTON_MIDDLE = 1;
    public const BUTTON_RIGHT = 2;

    /**
     * xterm reports wheel ticks as pseudo-buttons in the same `b` field:
     * every `1006`-style protocol decoder needs this pair, so it lives here
     * spelled, not as a magic number at each use site.
     */
    public const BUTTON_WHEEL_UP = 64;
    public const BUTTON_WHEEL_DOWN = 65;
}
