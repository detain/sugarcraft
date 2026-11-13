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
 * PATH lookups are MEMOIZED with a 60-second TTL. Every decoder open
 * (and every Player seek/resize reopen) calls Probe::ffmpeg() → a shell
 * `command -v` fork — the identical answer the whole time. The memo makes
 * the probe per-playback-cheap without freezing it per-process forever: an
 * ffmpeg installed mid-session (the dev editing their PATH while a long
 * reel runs) is picked up inside a minute. TTL is a compromise value —
 * sugar-image's Detect.php carries the same ask un-implemented (its own
 * lane owns that cache; recorded as a seam upstream). Subclass
 * overrides of which() bypass the memo entirely (they replace the lookup,
 * not its cache); PATH-flipping test concerns clear it via
 * {@see clearBinaryCacheForTesting()}.
 *
 * SV-M17: the memo distinguishes "ran, answered nothing" (a genuine miss —
 * cached for the full TTL) from "could not execute" (shell_exec returned
 * null — a transient fork hiccup under CI load): a failure is only given
 * {@see EXEC_RETRY_SECONDS} of grace, so one hiccup cannot pin
 * `decoder.ffmpeg_missing` for a minute on a machine that HAS ffmpeg.
 * The expiry clock is monotonic hrtime by default and scriptable via
 * {@see setClockForTesting()} (same shape as Player's pacing seam).
 *
 * Mirrors the approach of seatedro/glyph and maxcurzi/tplay binary probes.
 */
class Probe
{
    /** Memo lifetime in seconds — long enough to absorb a playback's reopens, short enough that a mid-session install converges. */
    public const CACHE_TTL_SECONDS = 60.0;

    /**
     * Grace a FAILED EXECUTION (shell_exec returned null — the lookup never
     * ran, so there is no answer) keeps in the memo. SV-M17: deliberately
     * tiny — treating a fork hiccup like a genuine 60s absence pinned
     * `decoder.ffmpeg_missing` for a minute on machines that have ffmpeg.
     */
    public const EXEC_RETRY_SECONDS = 1.0;

    /** @var array<string, array{value: ?string, at: float, ttl: float}> memoized PATH lookups, keyed by bare command name */
    private static array $lookups = [];

    /** Monotonic clock seam (seconds); null = hrtime. Scripted by tests for expiry arms. */
    private static ?\Closure $clock = null;

    /**
     * Inject a deterministic clock (seconds since arbitrary origin) for the
     * memo-expiry tests; null restores the live monotonic hrtime clock.
     */
    public static function setClockForTesting(?\Closure $clock): void
    {
        self::$clock = $clock;
    }

    /** The memo expiry clock: scripted seam when set, monotonic hrtime otherwise. */
    private static function now(): float
    {
        return self::$clock !== null ? (float) (self::$clock)() : hrtime(true) / 1_000_000_000;
    }
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
     * Windows: "where.exe <cmd>" — returns the first match from PATH, and the
     * lookup is only consulted under a cmd-family ComSpec (SV-X94: on
     * PowerShell-default hosts the `2>NUL` redirection is not cmd semantics
     * and misfires, so a non-cmd family refuses the probe instead).
     * Unix:    "command -v <cmd>" — returns the full path from PATH.
     *
     * If the command already contains a path separator, only verifies that it
     * is an existing executable file (no PATH lookup). On Windows
     * executability is an EXTENSION property — SV-X94: the extension-blind
     * stat bit made an `ffmpeg.txt` pass as a binary, so `.exe/.bat/.cmd` is
     * required there. The Windows gate runs through {@see isWindows()} (a
     * subclass seam) so both arms are unit-testable on any host.
     *
     * @see sugar-core/src/Util/Editor.php:154
     */
    protected static function which(string $cmd): ?string
    {
        if ($cmd === '') {
            return null;
        }

        $windows = static::isWindows();
        $hasSep = str_contains($cmd, DIRECTORY_SEPARATOR) || ($windows && str_contains($cmd, '/'));
        if ($hasSep) {
            if ($windows && preg_match('~\.(exe|bat|cmd)$~i', $cmd) !== 1) {
                return null;
            }

            return is_file($cmd) && is_executable($cmd) ? $cmd : null;
        }

        if ($windows) {
            $comspec = (string) getenv('ComSpec');
            if (preg_match('~cmd\.exe$~i', $comspec) !== 1) {
                // Documented refuse (SV-X94): without cmd semantics the probe
                // would emit PowerShell noise, and `where.exe` itself may be
                // shadowed by an alias. "No binary answer" is the honest value.
                return null;
            }
            $shell = 'where.exe ' . escapeshellarg($cmd) . ' 2>NUL';
        } else {
            $shell = 'command -v ' . escapeshellarg($cmd) . ' 2>/dev/null';
        }

        // TTL memo: fresh entries answer without forking a shell at all;
        // expired ones re-lookup and overwrite. Path-separator commands above
        // stay uncached — an is_file() check costs less than the memo book.
        $now = self::now();
        $memo = self::$lookups[$cmd] ?? null;
        if ($memo !== null && $now - $memo['at'] < $memo['ttl']) {
            return $memo['value'];
        }

        $out = static::runShellLookup($shell);
        if (!is_string($out)) {
            // SV-M17: the lookup COULD NOT EXECUTE (shell_exec returns null
            // only when the command itself fails to run — fork exhaustion
            // under CI load). That is not an answer; caching it like a
            // genuine absence pinned `decoder.ffmpeg_missing` for a full
            // minute on machines that have ffmpeg. A tiny retry window
            // keeps a persistent failure from re-forking per open.
            self::$lookups[$cmd] = ['value' => null, 'at' => $now, 'ttl' => self::EXEC_RETRY_SECONDS];

            return null;
        }

        $first = strtok(trim($out), "\r\n");
        // strtok returns false when string is empty, otherwise a non-empty token
        $value = $first ?: null;

        self::$lookups[$cmd] = ['value' => $value, 'at' => $now, 'ttl' => self::CACHE_TTL_SECONDS];

        return $value;
    }

    /**
     * Execute a `command -v`/`where` lookup and hand back the raw shell_exec
     * answer: string output (possibly empty = "ran, found nothing") or null
     * (= could not execute). Split out as the protected seam so tests can
     * script the failure-vs-absence distinction without a real fork storm.
     */
    protected static function runShellLookup(string $shell): ?string
    {
        return @shell_exec($shell);
    }

    /**
     * The OS-shape seam (SV-X94): true on Windows hosts. Subclass-overridable
     * so the Windows detection arms — extension gate, cmd-family refusal —
     * are unit-testable on a Linux/CI host without lying about the platform
     * anything else sees.
     */
    protected static function isWindows(): bool
    {
        return DIRECTORY_SEPARATOR === '\\';
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
