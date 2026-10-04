<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

use SugarCraft\Crush\Config\LayeredSettings;

/**
 * The SESSION TIER (roadmap N-P3, Appendix N §4.3): settings changed for this
 * process only — nothing is written to disk, and everything is gone at exit.
 *
 * Written by {@see SettingsWriter} on {@see SettingsTier::Session} and read by
 * `Bootstrap::mergedConfig()`, which lays it ABOVE `config.json` (layer 4) and
 * below the environment and flags — the order {@see SettingSource} states.
 *
 * WHY PROCESS STATE, LIKE `Bootstrap::$projectRootForSettings`. The readers that
 * matter are five frames below anything that holds a `Chat`: `EngineBackend`
 * re-reads the merged config at every turn start, inside the turn child it
 * `pcntl_fork()`s. A fork copies this array, so the turn child and every Task
 * sub-agent it runs see the session's values with no plumbing. A `/bg` daemon
 * is a separate PROCESS (`proc_open`), not a fork, so it starts from the files
 * alone — the settings view says so on this tier.
 *
 * LAYERED KEYS ONLY. A key outside {@see LayeredSettings::LAYERED_KEYS}
 * (`permissionMode`, the trust lists, `server.*`) is read straight from
 * `config.json` by its own reader, so a session value for it would sit here
 * doing nothing; {@see all()} filters, and the writer refuses them first.
 */
final class SessionSettings
{
    /** @var array<string, mixed> */
    private static array $values = [];

    private function __construct()
    {
    }

    /**
     * The overlay, restricted to the layered keys.
     *
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_intersect_key(self::$values, array_flip(LayeredSettings::LAYERED_KEYS));
    }

    /**
     * Set `$set` and drop `$unset` — a dropped key falls back to the files.
     *
     * @param array<string, mixed> $set
     * @param list<string> $unset
     */
    public static function apply(array $set, array $unset = []): void
    {
        self::$values = SettingsWriter::patched(self::$values, $set, $unset);
    }

    /**
     * Forget every session value. For tests, which share one process: a value
     * one test leaves here would be read by every later `readUserConfig()`.
     */
    public static function reset(): void
    {
        self::$values = [];
    }
}
