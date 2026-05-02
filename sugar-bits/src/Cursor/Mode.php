<?php

declare(strict_types=1);

namespace CandyCore\Bits\Cursor;

/**
 * How a {@see Cursor} should render the cell under it:
 *
 * - {@see Blink}  — alternates highlighted / plain on a timer.
 * - {@see Static} — always highlighted while focused.
 * - {@see Hidden} — never highlighted (use to dim a focused field).
 */
enum Mode: string
{
    case Blink  = 'blink';
    case Static = 'static';
    case Hidden = 'hidden';
}
