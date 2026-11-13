<?php

declare(strict_types=1);

namespace SugarCraft\Image\Support;

/**
 * Single source of truth for locating the `stty` binary.
 *
 * The query-mode helpers in {@see \SugarCraft\Image\Detect} previously
 * handed a bare `stty` argv[0] through the shell's PATH search
 * — a strictly wider surface than the fixed absolute path they could
 * have used. The spawn now names an absolute binary resolved here; the
 * shell, if it runs at all, runs a fixed literal. `null` means no
 * gate-passing stty exists: every caller degrades exactly as it did when
 * `stty -g` answered empty.
 *
 * The override in {@see ENV_OVERRIDE} follows the house grammar
 * ({@see ResolvedBinary::locate()} — absolute-as-is-or-refused, bare
 * name authorises one PATH lookup, relative refused), serving both the
 * operator who lives somewhere the roster misses and the test seam the
 * verified-restore ladder needs to run hermetically off a real tty.
 * The roster leg is memoised per process — filesystem layout does not
 * move under a probe — while the override leg is re-read every call so
 * a moved knob is honored immediately; {@see reset()} clears the memo.
 */
final class SttyBinary
{
    /**
     * Operator-controlled override naming the stty binary — same
     * absolute-or-bare-name grammar as every other tool here.
     */
    public const ENV_OVERRIDE = 'SUGAR_IMAGE_STTY';

    /** Candidate absolute paths, checked in order (POSIX stty homes). */
    private const CANDIDATES = [
        '/bin/stty',
        '/usr/bin/stty',
    ];

    private static ?string $rosterPath = null;

    private static bool $rosterResolved = false;

    /**
     * Absolute path to stty, or null when no gate-passing binary resolves.
     */
    public static function path(): ?string
    {
        $override = getenv(self::ENV_OVERRIDE);
        if ($override !== false && $override !== '') {
            // An empty answer here means REFUSED; a broken override
            // never falls back to the roster (doctrine: mask nothing).
            return ResolvedBinary::locate(self::ENV_OVERRIDE, []);
        }

        if (!self::$rosterResolved) {
            self::$rosterPath     = ResolvedBinary::locate(null, self::CANDIDATES);
            self::$rosterResolved = true;
        }

        return self::$rosterPath;
    }

    /** Clear the roster memo (test isolation). The override leg is never cached. */
    public static function reset(): void
    {
        self::$rosterPath     = null;
        self::$rosterResolved = false;
    }
}
