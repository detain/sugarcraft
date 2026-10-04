<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * Where the settings editor writes a change (roadmap N-P2, Appendix N §4.4).
 *
 *  - {@see You}: the user's `config.json` — `Bootstrap::userConfigPath()`, so
 *    `--config` moves it — through `Bootstrap::writeUserConfig()`, the same
 *    file `/theme` and `/model` already write. It outranks
 *    `~/.sugar-crush/settings.json`, so a saved value sticks, and
 *    `settings.json` itself is never written.
 *  - {@see ProjectLocal}: `<root>/.sugar-crush/settings.local.json`, only the
 *    project-settable keys, and only for a project the operator has already
 *    trusted — the file the merge would read back.
 *
 * The committed project file and the in-memory session tier are later phases
 * (N-P5, N-P3); they join this enum when something writes them.
 */
enum SettingsTier: string
{
    case You = 'you';
    case ProjectLocal = 'project-local';

    public function label(): string
    {
        return match ($this) {
            self::You => 'You (all projects)',
            self::ProjectLocal => 'This project (local)',
        };
    }

    /** The layer a value written on this tier is read back from. */
    public function source(): SettingSource
    {
        return match ($this) {
            self::You => SettingSource::UserConfig,
            self::ProjectLocal => SettingSource::ProjectLocal,
        };
    }

    /** The other tier, for a cycling selector. */
    public function next(): self
    {
        return $this === self::You ? self::ProjectLocal : self::You;
    }
}
