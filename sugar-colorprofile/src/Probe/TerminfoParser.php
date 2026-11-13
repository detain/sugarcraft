<?php

declare(strict_types=1);

namespace SugarCraft\ColorProfile\Probe;

/**
 * Name-position terminfo capability matcher.
 *
 * An `infocmp -1` dump lists one capability per line, optionally indented or
 * colon-prefixed, each as `name` plus a type marker and value (`Tc,`,
 * `rgb=\E[38;2;...m,`, `Sixel#1,`). The only reliable reading is the NAME
 * token at the start of each entry: matching capability words anywhere in the
 * output (the pre-fix `/\bTc\b/` regex) false-positives when the word appears
 * inside a description or another capability's value — `des=xterm with Tc
 * support,` names `des`, not `Tc`.
 *
 * This matcher is the hardened form of TerminalProbe::terminfoHasSixel(),
 * extracted so the Sixel, TrueColor and async paths share ONE parser instead
 * of three drifting copies.
 */
final class TerminfoParser
{
    /**
     * Capability names whose presence means Sixel graphics support.
     *
     * The termcap `ss1`/`ss2` entries are deliberately NOT in this set: in
     * X3.64/ncurses terms they initialize the legacy STATUS LINE (scrolled /
     * advanced), a decoration any terminal may ship sixel or not — a dump
     * carrying only `Ss1` says nothing at all about sixel graphics. The
     * accepted spellings are the boolean `sixel` (covers `Sixel`, `Sixel#1`,
     * `Sixel=true`), the abbreviated `sixl`, and the numeric
     * `SixelScreenMode` the xterm family uses — listed exactly as dumps
     * spell them, plus the snake-cased `sixel_screen_mode` some vendor
     * databases ship. Names match EXACT after lowercasing: there is no
     * underscore folding (CP-27).
     */
    public const SIXEL_NAMES = ['sixel', 'sixl', 'sixelscreenmode', 'sixel_screen_mode'];

    /**
     * Capability names whose presence means 24-bit direct color support.
     * Matched exact after lowercasing — an underscore-spliced forgery like
     * `t_c` is NOT a truecolor cap (CP-27).
     */
    public const TRUECOLOR_NAMES = ['tc', 'rgb'];

    private function __construct()
    {
    }

    /**
     * Whether the terminfo dump declares any of $names as a capability name
     * (compared lowercased, name position only).
     *
     * Matching is EXACT on the lowercased name: no underscore folding. The
     * fold this replaces let `t_c,` and `r_gb,` in the name position mint
     * TrueColor from glued-letter forgeries — under the name-position law a
     * capability must be its OWN token (CP-27, finding -069; the legitimate
     * snake-cased spellings are rostered by name in SIXEL_NAMES, so nothing
     * a real terminfo(5) database ships lost an answer).
     *
     * @param list<string> $names
     */
    public static function hasCapName(string $output, array $names): bool
    {
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            $line = ltrim($line, " \t:");
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (preg_match('/^([A-Za-z0-9_]+)/', $line, $m) !== 1) {
                continue;
            }
            $name = strtolower($m[1]);
            if (in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }
}
