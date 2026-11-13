<?php

declare(strict_types=1);

namespace SugarCraft\Image\Support;

/**
 * Shared argv[0]-resolution doctrine for this library's external tools.
 *
 * The rule `Renderer\ChafaBinary` established for chafa and
 * `ColorProfile\Probe\InfocmpBinary` established for infocmp, generalized:
 * a bare program name is NEVER handed to a spawn — the OS PATH search
 * would let any file planted earlier on PATH execute with this process's
 * privileges. Only absolute, gate-passing paths reach argv[0]. This is the
 * pure resolution engine; the per-tool memoised facades
 * ({@see \SugarCraft\Image\Support\TmuxBinary},
 * {@see \SugarCraft\Image\Support\SttyBinary}) own their rosters and
 * caches on top of it.
 *
 * Grammar (identical to ChafaBinary's, by design — one vocabulary):
 *
 *  1. The operator override, when an override env name is given and the
 *     env value is non-empty:
 *     - a bare name (no slash) authorises a PATH lookup for that one
 *       explicitly-named binary, and only absolute PATH directories pass
 *       (relative entries, and the empty entry PATH treats as cwd, are
 *       the classic hijack door and are skipped);
 *     - an absolute path is used as-is when it is a file and executable,
 *       and is otherwise REFUSED outright — a broken override never
 *       silently falls back to the roster, because that would mask a
 *       misconfiguration behind a different binary's behaviour;
 *     - any other value (a relative path containing a slash) is refused:
 *       relative program names are exactly what the gate exists to reject.
 *  2. A fixed roster of absolute candidates, checked in order, each
 *     gated on `is_file() && is_executable()`.
 *  3. `null` — nothing resolved; callers degrade exactly as they do for a
 *     missing binary.
 */
final class ResolvedBinary
{
    /**
     * Resolve one external tool to an absolute executable path.
     *
     * @param ?string $overrideEnv operator override env var name, or null when the tool takes no override
     * @param list<string> $candidates absolute roster, checked in order
     * @return ?string absolute path that passed the gate, null when nothing resolves
     */
    public static function locate(?string $overrideEnv, array $candidates): ?string
    {
        if ($overrideEnv !== null) {
            $override = getenv($overrideEnv);
            if ($override !== false && $override !== '') {
                if (!str_contains($override, '/')) {
                    // An explicitly-named bare binary is the ONE case
                    // where PATH is consulted.
                    return self::lookupOnPath($override);
                }

                // Absolute-and-usable, or refused. No fallback, ever.
                return str_starts_with($override, '/') && self::usable($override)
                    ? $override
                    : null;
            }
        }

        foreach ($candidates as $candidate) {
            if (self::usable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * PATH lookup for an explicitly-named bare override only. Relative
     * PATH entries are skipped: they are exactly the shape a hijack
     * rides in on.
     */
    public static function lookupOnPath(string $name): ?string
    {
        $path = getenv('PATH');
        if ($path === false || $path === '') {
            return null;
        }

        foreach (explode(PATH_SEPARATOR, $path) as $dir) {
            if ($dir === '' || !str_starts_with($dir, '/')) {
                continue;
            }

            $candidate = rtrim($dir, '/') . '/' . $name;
            if (self::usable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** The gate both doors share: an existing, executable file. */
    public static function usable(string $candidate): bool
    {
        return \is_file($candidate) && \is_executable($candidate);
    }
}
