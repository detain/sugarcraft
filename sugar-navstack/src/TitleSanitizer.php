<?php

declare(strict_types=1);

namespace SugarCraft\NavStack;

use SugarCraft\Core\Util\Sanitize;

/**
 * Shared neutralizer for untrusted crumb titles.
 *
 * ONE implementation used by every sink that prints stack titles —
 * {@see Breadcrumb} (terminal render), {@see NavStack::view()} and
 * {@see NavStack::viewHtml()}. Previously duplicated in two classes, which
 * meant a hardening to one sink silently left the other exposed.
 *
 * The sweep is deliberately hostile-input safe for zone-marked frames:
 *  - {@see Sanitize::untrustedForMarkedFrames()} strips ANSI escapes, C0/C1
 *    controls, private-use bytes AND the sugar-mouse zone sentinels
 *    (U+E000/U+E001). Without the sentinel sweep an untrusted title forged
 *    as `\x{E000}crumb-0\x{E001}` (Shell::pushDirectory / Url::parse
 *    segments are user-controlled) would open a fake zone that shadows the
 *    legitimate `crumb-0` — the scanner registers earliest-first, so a
 *    forged crumb hijacks (or, on a duplicate id, permanently crashes)
 *    mouse dispatch for the whole frame.
 *  - {@see Sanitize::stripInvisibleFormatting()} removes the bidi/zero-width
 *    spoofing characters that make a title render at a different logical
 *    position than its measured width accounts for (joiners survive in
 *    legitimate contexts).
 *  - CR/LF/tab collapse keeps the single-line breadcrumb single-line
 *    (Sanitize itself preserves those whitespace controls).
 *
 * Must run BEFORE any width math, truncation, or zone-wrapping, so measured
 * widths match painted cells.
 *
 * @internal Library-internal; not part of the public API surface.
 */
final class TitleSanitizer
{
    private function __construct()
    {
    }

    public static function sanitize(string $title): string
    {
        return \str_replace(
            ["\r\n", "\r", "\n", "\t"],
            ' ',
            Sanitize::stripInvisibleFormatting(Sanitize::untrustedForMarkedFrames($title))
        );
    }
}
