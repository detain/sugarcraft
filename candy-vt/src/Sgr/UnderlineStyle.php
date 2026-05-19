<?php

declare(strict_types=1);

namespace SugarCraft\Vt\Sgr;

/**
 * SGR underline style — single, double, curly, dotted, dashed, or none.
 *
 * CSI 4:N maps to these values:
 *   4:0 = none (underline off)
 *   4:1 = single
 *   4:2 = double
 *   4:3 = curly ( rarely called "dotted underline" in some docs)
 *   4:4 = dotted
 *   4:5 = dashed
 *
 * Mirrors charmbracelet/x/vt SGR underline-style enumeration.
 */
enum UnderlineStyle: int
{
    case None = 0;
    case Single = 1;
    case Double = 2;
    case Curly = 3;
    case Dotted = 4;
    case Dashed = 5;
}
