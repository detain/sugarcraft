<?php

declare(strict_types=1);

namespace SugarCraft\Markdown\Html;

/**
 * RFC 3986 reference resolution (§5.2.2 strict parser, §5.2.4 dot-segment
 * removal), used to make a page's relative links absolute against the URL it
 * was fetched from.
 *
 * Pure string work: nothing is fetched and nothing is vetted. The result
 * names whatever scheme or authority the reference carries, so a caller that
 * follows it must apply its own policy to the result.
 */
final class UrlReference
{
    /**
     * Resolve $reference against the absolute $base, per RFC 3986 §5.2.2
     * (strict parser), with §5.2.4 dot-segment removal. The fragment is
     * dropped: it is never sent to a server, so it cannot change what a hop
     * fetches.
     *
     * The result is NOT vetted here. Whatever scheme or authority it names —
     * `file:x`, `//169.254.169.254/` — is for the caller to vet before it
     * follows the URL, exactly as it would vet an absolute reference; resolving
     * never makes a disallowed target reachable.
     *
         */
    public static function resolve(string $base, string $reference): string
    {
        $r = self::splitReference($reference);
        $b = self::splitReference($base);

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
     * empty one — which §5.2.2 distinguishes.
     *
     * @return array{scheme: ?string, authority: ?string, path: string, query: ?string}
     */
    private static function splitReference(string $reference): array
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
