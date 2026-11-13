<?php

declare(strict_types=1);

namespace SugarCraft\ScriptUi\Runtime;

use SugarCraft\Core\Util\Ansi;
use SugarCraft\Core\Util\ColorProfile;
use SugarCraft\Style\Style;

/**
 * The single decoration door for hand-composed SGR inside interactive
 * frames.
 *
 * Mirrors charmbracelet/gum's lipgloss/termenv posture: emphasis
 * (reverse video, bold, colour) is a capability, not a constant. The
 * cursor row, the fuzzy-match emphasis, and the pager highlight were
 * forged with raw {@see Ansi::sgr()} calls that bypassed the resolved
 * colour tier, so `--no-ansi` and `TERM=dumb` frames still carried
 * escape bytes while every `Style`-built element in the same frame went
 * plain. Routing through here makes the ambient gate authoritative for
 * both families: a `NoTty` capability emits no escape sequences at all.
 */
final class Emphasis
{
    /**
     * The SGR sequence for the requested codes, or '' when decoration is
     * suppressed. `Ascii` keeps decoration (it drops COLOUR only — same
     * contract sugar-style's tier docs state); `NoTty` strips to plain
     * bytes so a frame painted under it is escape-free.
     */
    public static function sgr(int ...$codes): string
    {
        if (Style::defaultProfile() === ColorProfile::NoTty) {
            return '';
        }

        return Ansi::sgr(...$codes);
    }

    /** The reset terminator matching {@see sgr()}'s opener. */
    public static function reset(): string
    {
        return self::sgr(Ansi::RESET);
    }

    /**
     * Under a `NoTty` capability, remove SGR sequences a delegate rendered
     * — sugar-forms' ItemList forges its own cursor reverse-video with raw
     * escapes (foreign rider: the gate belongs there too), while the
     * README's `--no-ansi` promise covers the WHOLE frame, not just the
     * parts candy-scriptui paints itself. Every non-NoTty tier passes the
     * text through byte-identical.
     */
    public static function stripDecor(string $text): string
    {
        if (Style::defaultProfile() !== ColorProfile::NoTty) {
            return $text;
        }

        return (string) preg_replace('/\x1b\[[0-9;]*m/', '', $text);
    }
}
