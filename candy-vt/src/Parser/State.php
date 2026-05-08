<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Parser;

/**
 * VT500 ANSI parser states.
 *
 * Mirrors charmbracelet/x/ansi/parser State constants exactly. Order
 * is load-bearing — the transition table is indexed by `state << 8 | byte`.
 *
 * @see https://github.com/charmbracelet/x/blob/main/ansi/parser/const.go
 */
enum State: int
{
    case Ground = 0;
    case CsiEntry = 1;
    case CsiIntermediate = 2;
    case CsiParam = 3;
    case DcsEntry = 4;
    case DcsIntermediate = 5;
    case DcsParam = 6;
    case DcsString = 7;
    case Escape = 8;
    case EscapeIntermediate = 9;
    case OscString = 10;
    case SosString = 11;
    case PmString = 12;
    case ApcString = 13;
    case Utf8 = 14;
}
