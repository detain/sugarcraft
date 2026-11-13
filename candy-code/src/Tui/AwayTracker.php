<?php

declare(strict_types=1);

namespace SugarCraft\Code\Tui;

use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\UiSettings;
use SugarCraft\Code\Support\EnvBool;

/**
 * The away stamp behind the focus recap (C-TUI-3.g, GAP-UI-N4) — the
 * terminal-agnostic half of "recap when I get back".
 *
 * sugar-core reports focus only when `ProgramOptions::$reportFocus` is on
 * ({@see \SugarCraft\Code\Chat::programOptions()}); the decoder's reports —
 * `BlurMsg` for focus-out (InputReader's final 'O'; a `FocusLostMsg` is
 * never built by it), `FocusGainedMsg` for focus-in — then land in
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
     * Stamp an absence. `$at` is the injectable clock (seconds); production
     * calls with no argument and reads the monotonic timer, so an operator's
     * clock adjustment (NTP slew, DST shim) can never invent or erase away
     * time the way the wall clock could.
     */
    public static function focusLost(?float $at = null): void
    {
        self::$lostAt = $at ?? \hrtime(true) / 1_000_000_000;
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

        $elapsed = ($at ?? \hrtime(true) / 1_000_000_000) - $lostAt;

        return $elapsed >= self::AWAY_SECONDS ? $elapsed : null;
    }

    /**
     * Whether the automatic recap may speak at all. The schema's read point
     * for {@see SETTING_KEY}. C-M17: CANDY_CODE_DISABLE_AWAY_RECAP is the
     * shell's word over every file, and the user tier alone decides — a
     * project may switch this off, never on (same form as
     * {@see \SugarCraft\Code\Permissions\ReadOnlyCommands::autoAllowEnabled()}).
     */
    public static function recapEnabled(): bool
    {
        if (EnvBool::isSet(\getenv('CANDY_CODE_DISABLE_AWAY_RECAP'))) {
            return false;
        }

        if (!UiSettings::bool(self::SETTING_KEY)) {
            return false;
        }

        return self::userTierPermits(
            static fn(): array => Bootstrap::readUserConfigWithoutProjectTier(),
        );
    }

    /**
     * The user tier's word on the recap. An absent key means enabled (the
     * shipped default); a tier that cannot be READ at all means INERT: this
     * is a narrowing key, so an unreadable file may be the very one holding
     * `false`, and guessing "on" would re-enable a recap the user switched
     * off. Same inert posture as the other swallows in this layer.
     *
     * @internal exposed so the failure arm is testable without a corrupt tier.
     * @param \Closure():array<string,mixed> $read
     */
    public static function userTierPermits(\Closure $read): bool
    {
        try {
            $userTier = $read()[self::SETTING_KEY] ?? true;
        } catch (\Throwable) {
            return false;
        }

        return $userTier !== false;
    }

    /** Drop a held stamp. Test seam; the regain path already consumes. */
    public static function reset(): void
    {
        self::$lostAt = null;
    }
}
