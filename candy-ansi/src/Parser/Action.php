<?php

declare(strict_types=1);

namespace SugarCraft\Ansi\Parser;

/**
 * VT500 ANSI parser actions.
 *
 * Mirrors charmbracelet/x/ansi/parser Action constants exactly. Numeric
 * values are load-bearing — the transition table packs `(action << 4) | nextState`
 * into a single byte.
 *
 * @see https://github.com/charmbracelet/x/blob/main/ansi/parser/const.go
 */
enum Action: int
{
    case None = 0;
    case Clear = 1;
    case Collect = 2;
    case Prefix = 3;
    case Dispatch = 4;
    case Execute = 5;
    case Start = 6;
    case Put = 7;
    case Param = 8;
    case Print = 9;
}
