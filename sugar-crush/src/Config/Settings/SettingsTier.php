<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * Where the settings editor writes a change (roadmap N-P2/N-P3, Appendix N §4.4).
 *
 *  - {@see You}: the user's `config.json` — `Bootstrap::userConfigPath()`, so
 *    `--config` moves it — through `Bootstrap::writeUserConfig()`, the same
 *    file `/theme` and `/model` already write. It outranks
 *    `~/.sugar-crush/settings.json`, so a saved value sticks, and
 *    `settings.json` itself is never written.
 *  - {@see ProjectLocal}: `<root>/.sugar-crush/settings.local.json`, only the
 *    project-settable keys, and only for a project the operator has already
 *    trusted — the file the merge would read back.
 *  - {@see Session}: nothing is written; the value lives in
 *    {@see SessionSettings} for the rest of this process, above every file,
 *    and is gone at exit. Only keys that take effect without a restart may be
 *    set here — a session value for a key read once at launch would never be
 *    used.
 *
 * The committed project file is a later phase (N-P5); it joins this enum when
 * something writes it.
 */
enum SettingsTier: string
{
    case You = 'you';
    case ProjectLocal = 'project-local';
    case Session = 'session';

    public function label(): string
    {
        return match ($this) {
            self::You => 'You (all projects)',
            self::ProjectLocal => 'This project (local)',
            self::Session => 'This session only',
        };
    }

    /** The layer a value written on this tier is read back from. */
    public function source(): SettingSource
    {
        return match ($this) {
            self::You => SettingSource::UserConfig,
            self::ProjectLocal => SettingSource::ProjectLocal,
            self::Session => SettingSource::Session,
        };
    }

    /** The following tier, for a cycling selector. */
    public function next(): self
    {
        return match ($this) {
            self::You => self::ProjectLocal,
            self::ProjectLocal => self::Session,
            self::Session => self::You,
        };
    }
}
