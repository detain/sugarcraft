<?php

declare(strict_types=1);

namespace SugarCraft\Charts\Support;

/**
 * The NO_COLOR / CLICOLOR decision ladder, shared by every emitter.
 *
 * Extracted from Legend::colorSuppressed() (CH-36, sugar-charts-031) so
 * the de-facto standard governs ALL style emission, not just the legend
 * indicator. Semantics are the legend's original ones, unchanged:
 *
 *  - CLICOLOR_FORCE / FORCE_COLOR set to a non-empty value other than
 *    "0" force color ON, overriding everything else;
 *  - NO_COLOR set to ANY non-empty value — including "0" — disables
 *    color (the standard says presence is the signal, not the value);
 *  - CLICOLOR=0 disables color by BSD convention;
 *  - otherwise color is emitted.
 *
 * {@see self::guard()} is the emission-side half: it removes CSI SGR
 * sequences (color + attribute codes) from a finished render. It is
 * deliberately NOT a general escape stripper — picture payloads
 * (DCS/Sixel, OSC/iTerm2, APC/Kitty) survive it intact, which is why
 * Picture routes the ladder through its text placeholder only.
 */
final class ColorLadder
{
    /** SGR family: CSI parameter bytes terminated by 'm' (includes resets). */
    private const SGR_PATTERN = "/\x1b\[[0-9;]*m/";

    public static function suppressed(): bool
    {
        foreach (['CLICOLOR_FORCE', 'FORCE_COLOR'] as $forceVar) {
            $force = getenv($forceVar);
            if ($force !== false && $force !== '' && $force !== '0') {
                return false;
            }
        }

        $noColor = getenv('NO_COLOR');
        if ($noColor !== false && $noColor !== '') {
            return true;
        }

        return getenv('CLICOLOR') === '0';
    }

    /**
     * Ladder-adjust a composed render: byte-identical passthrough when
     * color is allowed, SGR-free text when suppressed.
     */
    public static function guard(string $output): string
    {
        if (!self::suppressed()) {
            return $output;
        }
        return self::stripSgr($output);
    }

    /**
     * Remove the SGR family (CSI … m) from any string — the mechanism
     * {@see self::guard()} applies when the ladder says "suppressed";
     * exposed so the parity test can name the exact removed set.
     */
    public static function stripSgr(string $output): string
    {
        return (string) preg_replace(self::SGR_PATTERN, '', $output);
    }

    /**
     * True when $output carries any SGR sequence — the matrix test's
     * discriminator (color emitted at all).
     */
    public static function carriesSgr(string $output): bool
    {
        return preg_match(self::SGR_PATTERN, $output) === 1;
    }
}
