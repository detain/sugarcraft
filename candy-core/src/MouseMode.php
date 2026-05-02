<?php

declare(strict_types=1);

namespace CandyCore\Core;

/**
 * How the program asks the terminal to report mouse events.
 *
 * - {@see Off}        — no mouse reporting at all.
 * - {@see CellMotion} — press, release, and motion-while-button-held.
 * - {@see AllMotion}  — press, release, and every move regardless of button.
 *
 * All modes are reported in SGR encoding (CSI 1006), so coordinates beyond
 * column/row 223 work correctly.
 */
enum MouseMode: string
{
    case Off        = 'off';
    case CellMotion = 'cell_motion';
    case AllMotion  = 'all_motion';
}
