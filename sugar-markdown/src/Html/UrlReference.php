<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Html;

/**
 * RFC 3986 reference resolution: a relative reference (a link's href, a
 * redirect's Location) against the absolute URL it was found at.
 *
 * Implements §5.2.2 (the strict parser, so an absent query is told apart
 * from an empty one) with §5.2.4 dot-segment removal. The fragment is
 * dropped: it is never sent to a server, so it cannot change what a request
 * fetches.
 *
 * The result is NOT vetted. Whatever scheme or authority it names faces the
 * caller's own guard exactly like an absolute reference would, which is what
 * keeps resolution from becoming a way around such a guard.
 */
final class UrlReference
{
    private function __construct()
    {
    }

    /**
     * Resolve $reference against the absolute $base.
     */
    public static function resolve(string $base, string $reference): string
    {
        $r = self::split($reference);
        $b = self::split($base);

        $scheme = $r['scheme'] ?? $b['scheme'];
        if ($r['scheme'] !== null || $r['authority'] !== null) {
            $authority = $r['authority'];
            $path = self::removeDotSegments($r['path']);
            $query = $r['query'];
        } else {
            $authority = $b['authority'];
            $query = $r['query'];
            if ($r['path'] === '') {
                $path = $b['path'];
                $query ??= $b['query'];
            } elseif (str_starts_with($r['path'], '/')) {
                $path = self::removeDotSegments($r['path']);
            } elseif ($b['authority'] !== null && $b['path'] === '') {
                // §5.2.3 merge: an authority with an empty path is "/".
                $path = self::removeDotSegments('/' . $r['path']);
            } else {
                $slash = strrpos($b['path'], '/');
                $directory = $slash === false ? '' : substr($b['path'], 0, $slash + 1);
                $path = self::removeDotSegments($directory . $r['path']);
            }
        }

        return ($scheme !== null ? $scheme . ':' : '')
            . ($authority !== null ? '//' . $authority : '')
            . $path
            . ($query !== null ? '?' . $query : '');
    }

    /**
     * RFC 3986 Appendix B's component regex. parse_url() is not used because
     * it is not a reference parser: it reads `g:h`-style and `//`-relative
     * input by its own rules, and it cannot tell an absent query from an
     * empty one, which §5.2.2 distinguishes.
     *
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string}
     */
    private static function split(string $reference): array
    {
        preg_match('~^(?:([^:/?#]+):)?(?://([^/?#]*))?([^?#]*)(?:\?([^#]*))?~', $reference, $m, PREG_UNMATCHED_AS_NULL);

        return [
            'scheme' => $m[1] ?? null,
            'authority' => $m[2] ?? null,
            'path' => (string) ($m[3] ?? ''),
            'query' => $m[4] ?? null,
        ];
    }

    /**
     * RFC 3986 §5.2.4, the loop exactly as specified.
     */
    private static function removeDotSegments(string $input): string
    {
        $output = '';
        while ($input !== '') {
            if (str_starts_with($input, '../')) {
                $input = substr($input, 3);
            } elseif (str_starts_with($input, './')) {
                $input = substr($input, 2);
            } elseif (str_starts_with($input, '/./')) {
                $input = substr($input, 2);
            } elseif ($input === '/.') {
                $input = '/';
            } elseif (str_starts_with($input, '/../') || $input === '/..') {
                $input = '/' . substr($input, $input === '/..' ? 3 : 4);
                $cut = strrpos($output, '/');
                $output = $cut === false ? '' : substr($output, 0, $cut);
            } elseif ($input === '.' || $input === '..') {
                $input = '';
            } else {
                $next = strpos($input, '/', 1);
                $segment = $next === false ? $input : substr($input, 0, $next);
                $output .= $segment;
                $input = $next === false ? '' : substr($input, $next);
            }
        }

        return $output;
    }
}
