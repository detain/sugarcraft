<?php

declare(strict_types=1);

namespace SugarCraft\CellBuffer\Diff;

use SugarCraft\CellBuffer\Style;
use SugarCraft\Core\Util\Ansi;

/**
 * Shared SGR (Select Graphic Rendition) sequence emitter.
 *
 * Produces ANSI SGR escape sequences from a {@see Style} object.
 * Used by both {@see \SugarCraft\CellBuffer\Buffer::toAnsi()} (full repaint path)
 * and {@see DiffEncoder} (delta/diff path) to ensure byte-identical output
 * and to centralise the INVISIBLE (conceal) attribute handling.
 *
 * The SGR PARAMETER spelling is composed from sugar-core's
 * {@see \SugarCraft\Core\Util\Ansi::fgRgbParams()}/{@see Ansi::bgRgbParams()}
 * family — the same builders sugar-image's OutputOptimizer composes — so a
 * pen value can never be spelled two ways across the monorepo. The run/pen
 * POLICY (full SGR per style change here, per-channel deltas there) stays
 * local by design.
 *
 * Mirrors the SGR emission logic shared by charmbracelet/lipgloss's
 * style.go and xterm's SGR sequences.
 */
final class SgrEmitter
{
    /**
     * Emit the SGR sequence for a style (or reset if null).
     *
     * @return string Raw ANSI SGR bytes, e.g. "\x1b[0;38;2;255;0;0m"
     */
    public static function emit(?Style $style): string
    {
        if ($style === null) {
            return "\x1b[0m";
        }

        $codes = ['0'];

        // Palette-slot identity wins over the RGB fallback when present:
        // a slot-emitted pen survives theme re-mapping on the host. With
        // no slot set the bytes are the historic RGB spelling, unchanged.
        if ($style->fgSlot() !== null) {
            $slot = $style->fgSlot();
            $codes[] = $slot < 8 ? (string) (30 + $slot)
                : ($slot < 16 ? (string) (82 + $slot)
                    : Ansi::fg256Params($slot));
        } elseif ($style->fg() !== null) {
            $rgb = $style->fg();
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $codes[] = Ansi::fgRgbParams($r, $g, $b);
        }

        if ($style->bgSlot() !== null) {
            $slot = $style->bgSlot();
            $codes[] = $slot < 8 ? (string) (40 + $slot)
                : ($slot < 16 ? (string) (92 + $slot)
                    : Ansi::bg256Params($slot));
        } elseif ($style->bg() !== null) {
            $rgb = $style->bg();
            $r = ($rgb >> 16) & 0xFF;
            $g = ($rgb >> 8) & 0xFF;
            $b = $rgb & 0xFF;
            $codes[] = Ansi::bgRgbParams($r, $g, $b);
        }

        $attrs = $style->attrs();
        if ($attrs !== 0) {
            if ($attrs & Style::ATTR_BOLD)       { $codes[] = '1'; }
            if ($attrs & Style::ATTR_FAINT)     { $codes[] = '2'; }
            if ($attrs & Style::ATTR_ITALIC)    { $codes[] = '3'; }
            if ($attrs & Style::ATTR_UNDERLINE) { $codes[] = '4'; }
            if ($attrs & Style::ATTR_BLINK)     { $codes[] = '5'; }
            if ($attrs & Style::ATTR_REVERSE)  { $codes[] = '7'; }
            if ($attrs & Style::ATTR_INVISIBLE) { $codes[] = '8'; }
            if ($attrs & Style::ATTR_STRIKE)   { $codes[] = '9'; }
            if ($attrs & Style::ATTR_OVERLINE) { $codes[] = '53'; }
        }

        // Underline colour (SGR 58) rides after the attribute run whenever
        // an underline pen is set — independent of the ATTR_UNDERLINE bit,
        // exactly as Style::fromSgr() parses 58 without requiring a 4. The
        // old gate dropped the pen on parse->emit->parse round-trips: a
        // style carrying only the colour encoded as a bare reset and the
        // colour silently vanished.
        if ($style->ulSlot() !== null) {
            $codes[] = '58';
            $codes[] = '5';
            $codes[] = (string) $style->ulSlot();
        } elseif ($style->ul() !== null) {
            $ul = $style->ul();
            $codes[] = '58';
            $codes[] = '2';
            $codes[] = (string) (($ul >> 16) & 0xFF);
            $codes[] = (string) (($ul >> 8) & 0xFF);
            $codes[] = (string) ($ul & 0xFF);
        }

        return "\x1b[" . implode(';', $codes) . "m";
    }
}
