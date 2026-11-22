<?php

declare(strict_types=1);

namespace SugarCraft\Mouse;

/**
 * Grapheme-cluster extraction for the zone scanner: one user-perceived
 * character per call, never one code point.
 *
 * {@see Scan::parse()} advances its column by the display width of each
 * cluster, so splitting a cluster into its code points measures it wrong:
 * a ZWJ family emoji, a regional-indicator flag or a base letter with a
 * combining accent would advance several columns instead of one or two, and
 * every zone to its right would register shifted.
 *
 * With ext-intl the boundary is ICU's UAX #29 answer (`grapheme_extract`).
 * Without it, {@see nextWithoutIntl()} asks PCRE's own UAX #29 matcher (`\X`,
 * always compiled into PHP) over a short window of whole code points, so a
 * host without ext-intl still measures per cluster instead of falling back to
 * per code point. Only bytes that are not valid UTF-8 degrade to a single
 * lead-byte-sized unit — the same posture the scanner always had for them;
 * a valid cluster followed by such bytes still measures as one cluster.
 */
final class Grapheme
{
    /**
     * Code points a fallback window holds. The longest clusters in real
     * frames — ZWJ family sequences and emoji tag flags — run to about ten
     * code points; the margin keeps a pathological run bounded.
     */
    private const WINDOW = 32;

    private function __construct()
    {
    }

    /**
     * The grapheme cluster starting at byte offset $i of $s ('' when $i is
     * at or past the end).
     */
    public static function next(string $s, int $i): string
    {
        // Function-local memo of the extension probe: availability is fixed
        // for the process, so function_exists() runs once, not per cluster.
        // Deliberately not a class property: the scanner carries no static
        // scan state, and this flag cannot change any result.
        static $hasGraphemeExtract = null;
        $hasGraphemeExtract ??= function_exists('grapheme_extract');

        if ($i >= strlen($s)) {
            return '';
        }
        if ($hasGraphemeExtract === true) {
            $next = 0;
            $cluster = grapheme_extract($s, 1, GRAPHEME_EXTR_COUNT, $i, $next);
            if (is_string($cluster) === true && $cluster !== '') {
                return $cluster;
            }
        }
        return self::nextWithoutIntl($s, $i);
    }

    /**
     * The cluster at $i found without ext-intl: PCRE `\X` over a window of
     * up to {@see WINDOW} whole code points. Public so the fallback can be
     * verified on hosts that do have ext-intl.
     */
    public static function nextWithoutIntl(string $s, int $i): string
    {
        $len = strlen($s);
        if ($i >= $len) {
            return '';
        }

        $first = self::scalarLength($s, $i);
        $end = $i + $first;
        if ($s[$i] === "\r" && ($s[$i + 1] ?? '') === "\n") {
            return "\r\n";
        }
        // Only an ASCII LEAD followed by ASCII (or by nothing) is complete as
        // is: no ASCII code point is Prepend, and a following ASCII byte only
        // joins a cluster whose last code point is Prepend (UAX #29 GB9b,
        // e.g. U+0600 + "a" is one cluster). A non-ASCII lead therefore keeps
        // following ASCII bytes in its window, so \X can apply GB9b.
        if ($end >= $len || (ord($s[$i]) < 0x80 && ord($s[$end]) < 0x80)) {
            return substr($s, $i, $first);
        }

        for ($taken = 1; $taken < self::WINDOW && $end < $len; $taken++) {
            $end += self::scalarLength($s, $end);
        }
        $window = substr($s, $i, min($end, $len) - $i);

        // A /u match fails on the whole window when ANY byte in it is not
        // valid UTF-8, so a later stray byte would cut a valid cluster down to
        // its lead unit. No cluster spans an invalid unit, so matching the
        // valid prefix gives the same boundary ICU would.
        if (preg_match('//u', $window) !== 1) {
            $window = self::validPrefix($window);
        }
        if ($window !== '' && preg_match('/\A\X/u', $window, $match) === 1 && $match[0] !== '') {
            return $match[0];
        }
        // The lead unit itself is not valid UTF-8: it alone is the answer.
        return substr($s, $i, $first);
    }

    /** The leading whole scalars of $window up to its first invalid UTF-8 unit. */
    private static function validPrefix(string $window): string
    {
        $len = strlen($window);
        $end = 0;
        while ($end < $len) {
            $unit = self::scalarLength($window, $end);
            if (preg_match('//u', substr($window, $end, $unit)) !== 1) {
                break;
            }
            $end += $unit;
        }
        return substr($window, 0, $end);
    }

    /** Byte length of the UTF-8 unit whose lead byte sits at $i (1 for a stray byte). */
    private static function scalarLength(string $s, int $i): int
    {
        $b = ord($s[$i]);
        return match (true) {
            ($b & 0x80) === 0    => 1,
            ($b & 0xe0) === 0xc0 => 2,
            ($b & 0xf0) === 0xe0 => 3,
            ($b & 0xf8) === 0xf0 => 4,
            default              => 1,
        };
    }
}
