<?php

declare(strict_types=1);

namespace SugarCraft\Code\Server\Http;

use Psr\Http\Message\ServerRequestInterface;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Protocol\WebStrings;

/**
 * The language a browser-facing server page answers in (S-V08).
 *
 * A request carries its own language (`Accept-Language`); the server
 * process's launch locale is the operator's, not the visitor's. This walks
 * the header in preference order through {@see WebStrings::negotiate()} —
 * the same containment ladder the `server.strings` bundle uses, so a tag can
 * only SELECT among shipped catalogues — and renders one key under the
 * winner. A malformed or absent header means English.
 *
 * Scope law (see {@see Responses}): this is for the human-readable HTML/prose
 * a browser navigation lands on. JSON API refusals keep a machine `kind`.
 */
final class RequestLocale
{
    private function __construct()
    {
    }

    /** The catalogue tag the request's `Accept-Language` selects; `en` when none matches. */
    public static function tag(ServerRequestInterface $request): string
    {
        $ranked = [];
        foreach (\explode(',', $request->getHeaderLine('Accept-Language')) as $i => $part) {
            $bits = \explode(';', \trim($part));
            $tag = \trim($bits[0]);
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach (\array_slice($bits, 1) as $param) {
                if (\preg_match('/^\s*q\s*=\s*([0-9.]+)\s*$/i', $param, $m) === 1) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0.0) {
                $ranked[] = [$q, $i, $tag];
            }
        }
        \usort($ranked, static fn (array $a, array $b): int => [$b[0], $a[1]] <=> [$a[0], $b[1]]);

        foreach (\array_slice($ranked, 0, 8) as [, , $tag]) {
            try {
                $chosen = WebStrings::negotiate($tag);
            } catch (\InvalidArgumentException) {
                continue;
            }
            // negotiate() floors at `en`; a preferred tag that only reached the
            // floor must not mask a later one that has a real catalogue.
            if ($chosen !== 'en' || \strtolower(\explode('-', \str_replace('_', '-', $tag))[0]) === 'en') {
                return $chosen;
            }
        }

        return 'en';
    }

    /**
     * @param array<string, scalar> $params
     */
    public static function t(ServerRequestInterface $request, string $key, array $params = []): string
    {
        return Lang::inLocale(self::tag($request), static fn (): string => Lang::t($key, $params));
    }
}
