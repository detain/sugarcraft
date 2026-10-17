<?php

declare(strict_types=1);

namespace SugarCraft\Core;

/**
 * Phase of a mouse event reported by a {@see Msg\MouseMsg}.
 *
 * - `Press`   — initial button-down.
 * - `Release` — button-up.
 * - `Motion`  — cursor moved while a button is held (drag), or any
 *               cursor motion when "all-motion" mouse mode is on.
 */
enum MouseAction: string
{
    case Press   = 'press';
    case Release = 'release';
    case Motion  = 'motion';
}
