<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Context\Pruning;

/**
 * How much of a session's context pruning happens on its own (roadmap 3.B-2,
 * DCP §13.2 B "PruningMode", §4.13 manual mode).
 *
 * - {@see Auto}: the deterministic strategies run at every turn start
 *   ({@see TurnStartPruning}), the model may prune its own tool outputs
 *   through the `Prune` tool (roadmap 3.B-3,
 *   {@see \SugarCraft\Crush\Tools\BuiltIn\Prune}), and every tool result
 *   carries its ref tag ({@see RefTag}) so it can be named.
 * - {@see Manual}: nothing is pruned on its own — no strategies, and the
 *   model is not offered `Prune`; `/sweep` still works, and the ref tags are
 *   still shown.
 * - {@see Off}: no strategies, no ref tags, and `/sweep` refuses. What the
 *   ledger already holds still applies, and the over-budget relief inside a
 *   turn (roadmap 2.2-1 / 2.4-1) is not pruning by choice but overflow
 *   protection, so it still runs.
 *
 * A session's mode is the one `/pruning` set on its ledger
 * ({@see ContextLedger::$mode}); a session that never set one follows
 * {@see configured()}.
 */
enum PruningMode: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Off = 'off';

    /** The environment variable that overrides the settings key. */
    public const ENV = 'SUGARCRUSH_CONTEXT_PRUNING';

    /** The user-config key. */
    public const SETTING = 'contextPruning.mode';

    /** The mode a session follows when none is set anywhere. */
    public const DEFAULT = self::Auto;

    /**
     * The mode configured for every session that has not chosen its own:
     * {@see ENV} when it names a mode, else the {@see SETTING} key of the
     * user config, else {@see DEFAULT}. A value that names no mode is
     * ignored rather than read as `off`, so a typo cannot quietly stop all
     * pruning.
     *
     * @param array<string, mixed>|null $userConfig the user config, already
     *        read; null reads it ({@see \SugarCraft\Crush\Cli\Bootstrap::readUserConfig()})
     */
    public static function configured(?array $userConfig = null, string|false|null $env = null): self
    {
        $env ??= getenv(self::ENV);
        $fromEnv = is_string($env) ? self::fromSetting($env) : null;
        if ($fromEnv !== null) {
            return $fromEnv;
        }

        if ($userConfig === null) {
            try {
                $userConfig = \SugarCraft\Crush\Cli\Bootstrap::readUserConfig();
            } catch (\Throwable) {
                // An unreadable config is the default, as every other
                // per-turn setting reads it.
                $userConfig = [];
            }
        }
        $setting = $userConfig[self::SETTING] ?? null;

        return (is_string($setting) ? self::fromSetting($setting) : null) ?? self::DEFAULT;
    }

    /** The mode $value names (case and surrounding space ignored), or null. */
    public static function fromSetting(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    /** Whether the deterministic strategies run at a turn's start. */
    public function runsStrategies(): bool
    {
        return $this === self::Auto;
    }

    /**
     * Whether the model is offered its own `Prune` tool (roadmap 3.B-3): only
     * when pruning happens on its own. The roadmap's default — `Prune` auto,
     * `Compress` manual — so a session set to `manual` prunes only by hand.
     */
    public function allowsModelPruning(): bool
    {
        return $this === self::Auto;
    }

    /** Whether tool results carry their ref tags. */
    public function showsRefs(): bool
    {
        return $this !== self::Off;
    }

    /** Whether a person may prune by hand (`/sweep`). */
    public function allowsManualPruning(): bool
    {
        return $this !== self::Off;
    }
}
