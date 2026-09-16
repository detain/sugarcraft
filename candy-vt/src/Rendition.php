<?php

declare(strict_types=1);

namespace SugarCraft\Vt;

/**
 * Per-line width/height rendition carried by a {@see Cell}.
 *
 * DEC's "hash" family (`ESC # n`) selects a LINE attribute, so the state
 * travels with every cell on the affected row rather than living on a
 * per-row side table. Stamping it on the cell means a scroll carries the
 * attribute with its glyphs for free — emulator and renderer share the one
 * {@see \SugarCraft\Vt\Buffer\Buffer} grid, which moves cells wholesale on
 * scroll, so no separate line-attribute bookkeeping can drift out of sync
 * with the content.
 *
 * The four cases map one-to-one onto the four DEC escapes:
 *  - {@see Rendition::DoubleTop}   — `ESC # 3` DECDHL, double-height top half
 *  - {@see Rendition::DoubleBottom}— `ESC # 4` DECDHL, double-height bottom half
 *  - {@see Rendition::None}        — `ESC # 5` DECSWL, single width/height
 *  - {@see Rendition::DoubleWidth} — `ESC # 6` DECDWL, double width
 *
 * Mirrors charmbracelet/x/vt line rendition flags (VT510 §DEC Special Lines).
 */
enum Rendition: int
{
    /** Normal single-width single-height line (also DECSWL `ESC # 5`). */
    case None = 0;

    /** DECDHL top half (`ESC # 3`) — line drawn in the upper half of a double row. */
    case DoubleTop = 1;

    /** DECDHL bottom half (`ESC # 4`) — line drawn in the lower half of a double row. */
    case DoubleBottom = 2;

    /** DECDWL (`ESC # 6`) — glyphs on the line occupy two columns. */
    case DoubleWidth = 3;

    /** True when the rendition draws the line taller than one row (either DECDHL half). */
    public function isDoubleHeight(): bool
    {
        return $this === self::DoubleTop || $this === self::DoubleBottom;
    }
}
