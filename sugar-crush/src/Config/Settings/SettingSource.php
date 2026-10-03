<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

use SugarCraft\Crush\Config\LayeredSettings;

/**
 * Where an effective value came from, LOWEST PRECEDENCE FIRST — the case order
 * IS the precedence {@see SettingsResolver} applies, so it is stated once,
 * here, and nowhere else:
 *
 *   default < project settings.json < project settings.local.json
 *           < ~/.sugar-crush/settings.json < config.json < session < env < flag
 *
 * That is today's file order ({@see \SugarCraft\Crush\Config\LayeredSettings})
 * with the session overlay the settings design reserves inserted above the files
 * and below the environment.
 */
enum SettingSource: string
{
    case Default = 'default';
    case ProjectShared = 'project';
    case ProjectLocal = 'project-local';
    case UserSettings = 'user-settings';
    case UserConfig = 'user-config';
    case Session = 'session';
    case Env = 'env';
    case Flag = 'flag';

    /** Higher wins. */
    public function precedence(): int
    {
        return (int) array_search($this, self::cases(), true);
    }

    /** Literal English (D7). */
    public function label(): string
    {
        return match ($this) {
            self::Default => 'default',
            self::ProjectShared => 'project settings.json',
            self::ProjectLocal => 'project settings.local.json',
            // Assembled from LayeredSettings rather than spelled: a whole
            // dot-path literal here would be one more site for
            // ProjectTierRefusalInventoryTest's census to classify, for a label.
            self::UserSettings => '~/' . LayeredSettings::dir() . '/' . LayeredSettings::USER_FILE,
            self::UserConfig => 'config.json',
            self::Session => 'this session',
            self::Env => 'environment',
            self::Flag => 'command-line flag',
        };
    }

    /** Whether this source is a file a repository ships. */
    public function isProjectTier(): bool
    {
        return $this === self::ProjectShared || $this === self::ProjectLocal;
    }
}
