<?php

declare(strict_types=1);

namespace SugarCraft\Log\Support;

/**
 * SAPI-safe access to the process STDIO channels.
 *
 * [PORT-01] Under php-fpm (and other web SAPIs) the `STDOUT`/`STDERR`
 * constants are NOT defined — every bare `\STDERR` read (including inside
 * `is_resource(\STDERR)` guards, where the ARGUMENT crashes before the guard
 * can run) would fatal the error-reporting path itself. The fallback is an
 * explicit `php://stdout` / `php://stderr` fopen, NOT `php://output`: output
 * buffering captures php://output, and the notice channel must bypass it.
 *
 * Handles are cached once resolved and re-resolved when a cached handle has
 * been closed — the announce path may legitimately see a freed STDERR.
 * Resolution failure yields null, which every caller treats as "stay silent".
 */
final class Stdio
{
    /** @var resource|null */
    private static $out;

    /** @var resource|null */
    private static $err;

    /**
     * @return resource|null the process output channel, or null when unavailable
     */
    public static function out()
    {
        return self::resolve('STDOUT', 'php://stdout', self::$out);
    }

    /**
     * @return resource|null the process error channel, or null when unavailable
     */
    public static function err()
    {
        return self::resolve('STDERR', 'php://stderr', self::$err);
    }

    /**
     * @param resource|null $cache
     *
     * @return resource|null
     */
    private static function resolve(string $constant, string $fallback, &$cache)
    {
        if (\is_resource($cache)) {
            return $cache;
        }

        if (\defined($constant)) {
            $native = \constant($constant);
            if (\is_resource($native)) {
                $cache = $native;

                return $cache;
            }
        }

        $reopened = @\fopen($fallback, 'w');
        $cache = \is_resource($reopened) ? $reopened : null;

        return $cache;
    }

    private function __construct()
    {
    }
}
