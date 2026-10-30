<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Code\Config\Settings\UiSettings;

/**
 * The away stamp behind the focus recap (C-TUI-3.g, GAP-UI-N4) — the
 * terminal-agnostic half of "recap when I get back".
 *
 * sugar-core reports focus only when `ProgramOptions::$reportFocus` is on
 * ({@see \SugarCraft\Code\Chat::programOptions()}); every
 * `FocusLostMsg`/`FocusGainedMsg` then lands in
 * `Chat::handleRunControlMessages()`, which stamps and consumes through
 * here. The stamp is process-global on purpose: sugar-core's `Program`
 * owns one terminal, and an absence that outlives a tab switch inside the
 * process is still the absence the user just lived through.
 *
 * Only the LAST loss counts — {@see focusLost()} re-stamps unconditionally,
 * so a loss/gain pair that raced a compositor's double-report measures the
 * final gap. {@see focusGained()} always consumes: the second regain of a
 * re-stamped absence must not re-count the first one (one recap per
 * absence, the bound that keeps the automatic call from spending twice on
 * the user's key).
 */
final class AwayTracker
{
    /**
     * The settings key gating the automatic recap. Named here, at the
     * reader, so the schema definition ({@see
     * \SugarCraft\Code\Config\Settings\Definitions\InterfaceSettings}) and
     * the focus arm cannot drift to different spellings.
     */
    public const SETTING_KEY = 'sessions.awayRecap';

    /**
     * How long an absence must run to count as away — the three minutes
     * Claude Code recaps past. Shorter gaps show the user a transcript they
     * watched settle; a recap row there is noise that cost a call.
     */
    public const AWAY_SECONDS = 180.0;

    /**
     * Settled user turns a recap needs to have substance. Under this the
     * model would summarise a greeting; auto-title asks far earlier because
     * naming a session needs only one question, recapping needs a story.
     */
    public const MIN_USER_TURNS = 3;

    private static ?float $lostAt = null;

    private function __construct()
    {
    }

    /**
     * Stamp an absence. `$at` is the injectable clock (microtime seconds);
     * production calls with no argument.
     */
    public static function focusLost(?float $at = null): void
    {
        self::$lostAt = $at ?? \microtime(true);
    }

    /**
     * End the absence: the length in seconds when the regain closes one of
     * at least {@see AWAY_SECONDS}, null for a shorter gap or for a regain
     * with no stamp to close. The stamp is consumed either way — a counted
     * absence must never be countable twice.
     */
    public static function focusGained(?float $at = null): ?float
    {
        $lostAt = self::$lostAt;
        self::$lostAt = null;

        if ($lostAt === null) {
            return null;
        }

        $elapsed = ($at ?? \microtime(true)) - $lostAt;

        return $elapsed >= self::AWAY_SECONDS ? $elapsed : null;
    }

    /**
     * Whether the automatic recap may speak at all. The schema's read point
     * for {@see SETTING_KEY}.
     */
    public static function recapEnabled(): bool
    {
        return UiSettings::bool(self::SETTING_KEY);
    }

    /** Drop a held stamp. Test seam; the regain path already consumes. */
    public static function reset(): void
    {
        self::$lostAt = null;
    }
}
