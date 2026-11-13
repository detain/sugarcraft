<?php

declare(strict_types=1);

namespace SugarCraft\Top\View;

use SugarCraft\Core\Util\Sanitize;

/**
 * The shared sink for untrusted identifier text: process names, commands and
 * users, container and guest names, engines. Panels scrub invalid UTF-8,
 * replace control bytes with spaces, and — since candy-top-096 — render the
 * invisible bidi and zero-width format characters visibly through sugar-core's
 * display policy, so a locally named process, PTY, container or VM cannot
 * silently reverse or blank a column ("Trojan Source" spoofing against the
 * repo's own documented tool).
 */
final class Untrusted
{
    private function __construct()
    {
    }

    /** Scrub, space-fold the control bytes, then mark the invisible formatters. */
    public static function text(string $s): string
    {
        $s = mb_scrub($s, 'UTF-8');
        $s = preg_replace('/[\x{00}-\x{1F}\x{7F}-\x{9F}]/u', ' ', $s) ?? '';

        return Sanitize::untrustedForDisplay($s);
    }
}
