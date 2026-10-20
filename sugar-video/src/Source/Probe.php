<?php

declare(strict_types=1);

namespace SugarCraft\Video\Source;

/**
 * Probes the host environment for FFmpeg toolchain binaries.
 *
 * Mirrors the which() pattern from sugar-core/src/Util/Editor.php:154 —
 * Windows uses "where", Unix uses "command -v". Every external-CLI argument
 * passes through escapeshellarg() to prevent injection.
 *
 * Returns null/false when a binary is absent — never throws. The `which()`
 * method is protected so tests can subclass and override it to simulate a
 * missing-binary environment.
 *
 * F-17: PATH lookups are MEMOIZED with a 60-second TTL. Every decoder open
 * (and every Player seek/resize reopen) calls Probe::ffmpeg() → a shell
 * `command -v` fork — the identical answer the whole time. The memo makes
 * the probe per-playback-cheap without freezing it per-process forever: an
 * ffmpeg installed mid-session (the dev editing their PATH while a long
 * reel runs) is picked up inside a minute. TTL is a compromise value —
 * sugar-image's Detect.php carries the same ask un-implemented (its own
 * lane owns that cache; recorded as a seam in the S7.4 report). Subclass
 * overrides of which() bypass the memo entirely (they replace the lookup,
 * not its cache); PATH-flipping test concerns clear it via
 * {@see clearBinaryCacheForTesting()}.
 *
 * Mirrors the approach of seatedro/glyph and maxcurzi/tplay binary probes.
 */
class Probe
{
    /** F-17 memo lifetime in seconds — long enough to absorb a playback's reopens, short enough that a mid-session install converges. */
    public const CACHE_TTL_SECONDS = 60.0;

    /** @var array<string, array{value: ?string, at: float}> memoized PATH lookups, keyed by bare command name */
    private static array $lookups = [];
    /**
     * Path to the ffmpeg binary, or null if not found.
     *
     * @see sugar-core/src/Util/Editor.php:154
     */
    public static function ffmpeg(): ?string
    {
        return static::which('ffmpeg');
    }

    /**
     * Path to the ffprobe binary, or null if not found.
     *
     * @see sugar-core/src/Util/Editor.php:154
     */
    public static function ffprobe(): ?string
    {
        return static::which('ffprobe');
    }

    /**
     * Path to the ffplay binary, or null if not found.
     *
     * @see sugar-core/src/Util/Editor.php:154
     */
    public static function ffplay(): ?string
    {
        return static::which('ffplay');
    }

    /**
     * Path to the mpv binary, or null if not found.
     *
     * @see sugar-core/src/Util/Editor.php:154
     */
    public static function mpv(): ?string
    {
        return static::which('mpv');
    }

    /**
     * True when the ffmpeg binary is available on this host.
     */
    public static function hasFFmpeg(): bool
    {
        return static::ffmpeg() !== null;
    }

    /**
     * Locate a binary by name using the host OS conventions.
     *
     * Windows: "where <cmd>" — returns the first match from PATH.
     * Unix:    "command -v <cmd>" — returns the full path from PATH.
     *
     * If the command already contains a path separator, only verifies
     * that it is an existing executable file (no PATH lookup).
     *
     * @see sugar-core/src/Util/Editor.php:154
     */
    protected static function which(string $cmd): ?string
    {
        if ($cmd === '') {
            return null;
        }

        $hasSep = str_contains($cmd, DIRECTORY_SEPARATOR)
            || (DIRECTORY_SEPARATOR === '\\' && str_contains($cmd, '/'));
        if ($hasSep) {
            return is_file($cmd) && is_executable($cmd) ? $cmd : null;
        }

        $shell = DIRECTORY_SEPARATOR === '\\'
            ? 'where ' . escapeshellarg($cmd) . ' 2>NUL'
            : 'command -v ' . escapeshellarg($cmd) . ' 2>/dev/null';

        // F-17 memo: fresh entries answer without forking a shell at all;
        // expired ones re-lookup and overwrite. Path-separator commands above
        // stay uncached — an is_file() check costs less than the memo book.
        $now = hrtime(true) / 1_000_000_000;
        $memo = self::$lookups[$cmd] ?? null;
        if ($memo !== null && $now - $memo['at'] < self::CACHE_TTL_SECONDS) {
            return $memo['value'];
        }

        $out = @shell_exec($shell);
        if (!is_string($out)) {
            $value = null;
        } else {
            $first = strtok(trim($out), "\r\n");
            // strtok returns false when string is empty, otherwise a non-empty token
            $value = $first ?: null;
        }

        self::$lookups[$cmd] = ['value' => $value, 'at' => $now];

        return $value;
    }

    /**
     * Drop every memoized lookup. For production callers the TTL converges
     * on its own; this exists for tests that rewrite PATH (the
     * HidesPathBinaries/StubbedPathBinaries concerns) and for any host that
     * wants an immediate re-probe after mutating PATH itself.
     */
    public static function clearBinaryCacheForTesting(): void
    {
        self::$lookups = [];
    }
}
