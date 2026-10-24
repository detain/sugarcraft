<?php

declare(strict_types=1);

namespace SugarCraft\Image\Renderer;

/**
 * Single source of truth for locating the `chafa` binary.
 *
 * Before this gate the renderer spawned a bare `chafa` argv[0] and let
 * the OS PATH search decide which program ran — an unguarded hijack
 * surface: any `chafa` that lands earlier on PATH (a dropped file, a
 * hostile dot-entry) executes with this process's privileges. The
 * discovery hardening mirrors sugar-colorprofile's
 * `SugarCraft\ColorProfile\Probe\InfocmpBinary`: PATH is never
 * consulted implicitly; only absolute, gate-passing paths execute.
 *
 * Resolution order:
 *
 *  1. The operator override in {@see ENV_OVERRIDE}, when non-empty:
 *     - a bare name (no slash) — an explicitly-named binary — is
 *       looked up on PATH, and only PATH directories that are
 *       themselves absolute pass (relative entries are the classic
 *       hijack door and are skipped);
 *     - an absolute path is used as-is when it is a file and
 *       executable, and is otherwise REFUSED outright — a broken
 *       override never silently falls back to the roster, because
 *       that would mask a misconfiguration behind a different
 *       binary's behaviour;
 *     - any other value (a relative path containing a slash) is
 *       refused: relative program names are exactly what the gate
 *       exists to reject.
 *  2. A fixed roster of absolute candidates, checked in order, each
 *     gated on `is_file() && is_executable()`.
 *  3. `null` — no chafa tier. Callers degrade exactly as they do for a
 *     missing binary today.
 *
 * The result is memoised per process — the filesystem layout the first
 * call sees is the layout every later call reports. {@see reset()}
 * clears the memo (test isolation, and long-running tools that install
 * chafa mid-session via {@see ChafaRenderer::reset()}).
 */
final class ChafaBinary
{
    /**
     * Operator-controlled override naming the chafa binary — an
     * absolute path, or a bare name that authorises the PATH lookup
     * for that one explicit value. Configuration is trusted input
     * (docs/TRUST-BOUNDARY.md §4), so naming a binary here is consent
     * to run it; the default (unset) carries no PATH duty at all.
     */
    public const ENV_OVERRIDE = 'SUGAR_IMAGE_CHAFA';

    /** Candidate absolute paths, checked in order. */
    private const CANDIDATES = [
        '/usr/bin/chafa',
        '/usr/local/bin/chafa',
        '/opt/homebrew/bin/chafa',
    ];

    private static ?string $path = null;

    /**
     * Absolute path to chafa, or null when no gate-passing binary resolves.
     */
    public static function path(): ?string
    {
        if (self::$path !== null) {
            return self::$path === '' ? null : self::$path;
        }

        $resolved = self::resolve();
        self::$path = $resolved ?? '';

        return $resolved;
    }

    /**
     * The roster as shipped — pinned by tests so the candidate shape
     * (absolute, basename `chafa`, order) cannot drift silently.
     *
     * @internal
     *
     * @return list<string>
     */
    public static function rosterCandidates(): array
    {
        return self::CANDIDATES;
    }

    /**
     * Clear the memoised answer (for testing only).
     *
     * @internal
     */
    public static function reset(): void
    {
        self::$path = null;
    }

    private static function resolve(): ?string
    {
        $override = getenv(self::ENV_OVERRIDE);
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

        foreach (self::CANDIDATES as $candidate) {
            if (self::usable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private static function usable(string $candidate): bool
    {
        return \is_file($candidate) && \is_executable($candidate);
    }

    /**
     * PATH lookup for an explicitly-named bare override only. Relative
     * PATH entries (and the empty entry PATH treats as cwd) are
     * skipped: they are exactly the shape a hijack rides in on.
     */
    private static function lookupOnPath(string $name): ?string
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
}
