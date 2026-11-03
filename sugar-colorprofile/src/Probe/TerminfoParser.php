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
    /** Capability names whose presence means Sixel graphics support. */
    public const SIXEL_NAMES = ['sixel', 'sixl', 'sixelscreenmode', 'ss1', 'ss2'];

    /** Capability names whose presence means 24-bit direct color support. */
    public const TRUECOLOR_NAMES = ['tc', 'rgb'];

    private function __construct()
    {
    }

    /**
     * Whether the terminfo dump declares any of $names as a capability name
     * (compared lowercased, name position only).
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
            $name = str_replace('_', '', strtolower($m[1]));
            if (in_array($name, $names, true)) {
                return true;
            }
        }

        return false;
    }
}
