<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * Where the settings editor writes a change (roadmap N-P2/N-P3/N-P5, Appendix N §4.4).
 *
 *  - {@see You}: the user's `config.json` — `Bootstrap::userConfigPath()`, so
 *    `--config` moves it — through `Bootstrap::writeUserConfig()`, the same
 *    file `/theme` and `/model` already write. It outranks
 *    `~/.sugar-crush/settings.json`, so a saved value sticks, and
 *    `settings.json` itself is never written.
 *  - {@see ProjectLocal}: `<root>/.sugar-crush/settings.local.json`, only the
 *    project-settable keys, and only for a project the operator has already
 *    trusted — the file the merge would read back.
 *  - {@see ProjectShared} (N-P5): `<root>/.sugar-crush/settings.json`, the
 *    COMMITTED project file — the same keys and the same trust gate as the
 *    local one, one precedence step lower, and shared with everyone who clones
 *    the repository (and trusts it). The save preview says so.
 *  - {@see Session}: nothing is written; the value lives in
 *    {@see SessionSettings} for the rest of this process, above every file,
 *    and is gone at exit. Only keys that take effect without a restart may be
 *    set here — a session value for a key read once at launch would never be
 *    used.
 */
enum SettingsTier: string
{
    case You = 'you';
    case ProjectLocal = 'project-local';
    case ProjectShared = 'project-shared';
    case Session = 'session';

    public function label(): string
    {
        return match ($this) {
            self::You => 'You (all projects)',
            self::ProjectLocal => 'This project (local)',
            self::ProjectShared => 'This project (shared)',
            self::Session => 'This session only',
        };
    }

    /** The label in the few cells a narrow title bar has. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::You => 'you',
            self::ProjectLocal => 'project local',
            self::ProjectShared => 'project shared',
            self::Session => 'session',
        };
    }

    /** The layer a value written on this tier is read back from. */
    public function source(): SettingSource
    {
        return match ($this) {
            self::You => SettingSource::UserConfig,
            self::ProjectLocal => SettingSource::ProjectLocal,
            self::ProjectShared => SettingSource::ProjectShared,
            self::Session => SettingSource::Session,
        };
    }

    /** A project file, local or shared: only the project-settable keys, and only once the project is trusted. */
    public function isProject(): bool
    {
        return $this === self::ProjectLocal || $this === self::ProjectShared;
    }

    /** The following tier, for a cycling selector. */
    public function next(): self
    {
        return match ($this) {
            self::You => self::ProjectLocal,
            self::ProjectLocal => self::ProjectShared,
            self::ProjectShared => self::Session,
            self::Session => self::You,
        };
    }
}
