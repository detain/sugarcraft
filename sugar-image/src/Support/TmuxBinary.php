<?php

declare(strict_types=1);

namespace SugarCraft\Image\Support;

/**
 * Single source of truth for locating the `tmux` binary.
 *
 * Applies the {@see ResolvedBinary} doctrine (the `ChafaBinary` grammar,
 * generalized) to the two tmux exec sites — {@see
 * \SugarCraft\Image\TmuxPassthrough::runTmux()} and the feature-format
 * query in {@see \SugarCraft\Image\Detect} — which previously spawned a
 * bare `argv[0] => 'tmux'` and let the OS PATH search decide which
 * program ran. A hijacked `tmux` there executes with the full app env,
 * TMUX and TMUX_TMPDIR included — positioned directly against the user's
 * own server. Resolution order and refusal semantics are {@see
 * ResolvedBinary::locate()}'s; `null` means "no tmux tier" and every
 * caller degrades exactly as it does for a missing binary today.
 *
 * Unlike chafa (a render hot path that memoises once per process), a
 * tmux spawn is a low-frequency probe, so the override is re-read on
 * every call rather than cached: an operator or test that moves
 * {@see ENV_OVERRIDE} between calls is honored immediately, while the
 * roster leg — which no override can change — keeps a process memo
 * because the filesystem layout the first call sees is the layout every
 * later call reports. {@see reset()} clears that roster memo (test
 * isolation; the override leg is never memoised and needs no clearing).
 */
final class TmuxBinary
{
    /**
     * Operator-controlled override naming the tmux binary — an absolute
     * path, or a bare name that authorises the PATH lookup for that one
     * explicit value. Configuration is trusted operator input, so naming
     * a binary here is consent to run it; the default (unset) carries no
     * PATH duty at all.
     */
    public const ENV_OVERRIDE = 'SUGAR_IMAGE_TMUX';

    /** Candidate absolute paths, checked in order. */
    private const CANDIDATES = [
        '/usr/bin/tmux',
        '/usr/local/bin/tmux',
        '/opt/homebrew/bin/tmux',
    ];

    private static ?string $rosterPath = null;

    private static bool $rosterResolved = false;

    /**
     * Absolute path to tmux, or null when no gate-passing binary resolves.
     */
    public static function path(): ?string
    {
        $override = getenv(self::ENV_OVERRIDE);
        if ($override !== false && $override !== '') {
            // Every override grammar lives in locate(); an empty answer
            // here means REFUSED, and must not quietly fall back to the
            // roster (a masked misconfiguration is worse than no tmux).
            return ResolvedBinary::locate(self::ENV_OVERRIDE, []);
        }

        if (!self::$rosterResolved) {
            self::$rosterPath   = ResolvedBinary::locate(null, self::CANDIDATES);
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
