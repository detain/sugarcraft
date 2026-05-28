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

    /** Button held and mouse moved — not a click, suppress duplicates. */
    case Drag = 'drag';

    /** Scroll wheel activated — pass-through, not a click. */
    case Scroll = 'scroll';
}
