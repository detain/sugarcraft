<?php

declare(strict_types=1);

namespace SugarCraft\Style;

/**
 * Underline-line style — drives the SGR 4:N sub-parameter that
 * terminals such as kitty, WezTerm, iTerm2 and recent xterm interpret
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
 * Terminals that understand SGR `4:N` render the styled underline;
 * terminals that don't typically ignore the extended form and render no
 * underline at all, since no plain SGR 4 companion is emitted. For a
 * guaranteed plain underline do not set a style: bare underline() alone
 * emits SGR 4.
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
