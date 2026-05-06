<?php

declare(strict_types=1);

namespace CandyCore\Sprinkles;

/**
 * Underline-line style — drives the SGR 4:N sub-parameter that
 * modern terminals (kitty, WezTerm, iTerm2, recent xterm) interpret
 * as a distinct line shape under the text.
 *
 * Mirrors upstream lipgloss `UnderlineStyle`.
 *
 * - {@see Single} (4:1) — the default underline most terminals draw.
 * - {@see Double} (4:2) — two parallel lines (good for emphasis).
 * - {@see Curly}  (4:3) — wavy line (spell-check style).
 * - {@see Dotted} (4:4) — dotted line.
 * - {@see Dashed} (4:5) — dashed line.
 *
 * Terminals that don't recognise the sub-parameter fall back to a
 * plain underline (SGR 4) — every value still reads as "underlined"
 * even if the line shape doesn't match.
 */
enum UnderlineStyle: int
{
    case None   = 0;
    case Single = 1;
    case Double = 2;
    case Curly  = 3;
    case Dotted = 4;
    case Dashed = 5;
}
