<?php

declare(strict_types=1);

namespace SugarCraft\Reel\Source;

/**
 * Probes the host environment for FFmpeg toolchain binaries.
 *
 * Mirrors the which() pattern from candy-core/src/Util/Editor.php:154 —
 * Windows uses "where", Unix uses "command -v". Every external-CLI argument
 * passes through escapeshellarg() to prevent injection.
 *
 * Returns null/false when a binary is absent — never throws. The `which()`
 * method is protected so tests can subclass and override it to simulate a
 * missing-binary environment.
 *
 * Mirrors the approach of seatedro/glyph and maxcurzi/tplay binary probes.
 */
class Probe
{
    /**
     * Path to the ffmpeg binary, or null if not found.
     *
     * @see candy-core/src/Util/Editor.php:154
     */
    public static function ffmpeg(): ?string
    {
        return static::which('ffmpeg');
    }

    /**
     * Path to the ffprobe binary, or null if not found.
     *
     * @see candy-core/src/Util/Editor.php:154
     */
    public static function ffprobe(): ?string
    {
        return static::which('ffprobe');
    }

    /**
     * Path to the ffplay binary, or null if not found.
     *
     * @see candy-core/src/Util/Editor.php:154
     */
    public static function ffplay(): ?string
    {
        return static::which('ffplay');
    }

    /**
     * Path to the mpv binary, or null if not found.
     *
     * @see candy-core/src/Util/Editor.php:154
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
     * @see candy-core/src/Util/Editor.php:154
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
        $out = @shell_exec($shell);
        if (!is_string($out)) {
            return null;
        }
        $first = strtok(trim($out), "\r\n");
        // strtok returns false when string is empty, otherwise a non-empty token
        return $first ?: null;
    }
}
