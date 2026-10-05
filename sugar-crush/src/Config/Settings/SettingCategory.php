<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

use SugarCraft\Crush\Lang;

/**
 * The settings editor's tabs, and the grouping of the generated key table.
 *
 * Each case that holds keys has ONE definitions file under `Definitions/`, so a
 * step adding a key edits that category's file and nothing shared (DH-KEYS).
 * A case with no file yet is a tab the editor will show empty — the design's
 * category list is adopted whole so a later key lands in a category that
 * already exists rather than reopening this enum.
 *
 * A tab's label is user-facing text: {@see label()} resolves {@see labelKey()}
 * through `Lang::t()` (audit 15b-14), so the English lives in `lang/en.php`.
 * The generated key table pins `en` ({@see SettingsDocGenerator}).
 */
enum SettingCategory: string
{
    case ModelProvider = 'model';
    case AgentLoop = 'loop';
    case Context = 'context';
    case Permissions = 'permissions';
    case Tools = 'tools';
    case MemoryRules = 'memory';
    case Skills = 'skills';
    case Subagents = 'subagents';
    case Git = 'git';
    case Interface = 'interface';
    case HooksMcp = 'hooks';
    case Server = 'server';
    case Advanced = 'advanced';

    /** The tab's label in the active locale. */
    public function label(): string
    {
        // One literal key per arm rather than Lang::t($this->labelKey()): the
        // catalogue census (LangParityTest) can only see a literal key.
        return match ($this) {
            self::ModelProvider => Lang::t('settings.category.model'),
            self::AgentLoop => Lang::t('settings.category.loop'),
            self::Context => Lang::t('settings.category.context'),
            self::Permissions => Lang::t('settings.category.permissions'),
            self::Tools => Lang::t('settings.category.tools'),
            self::MemoryRules => Lang::t('settings.category.memory'),
            self::Skills => Lang::t('settings.category.skills'),
            self::Subagents => Lang::t('settings.category.subagents'),
            self::Git => Lang::t('settings.category.git'),
            self::Interface => Lang::t('settings.category.interface'),
            self::HooksMcp => Lang::t('settings.category.hooks'),
            self::Server => Lang::t('settings.category.server'),
            self::Advanced => Lang::t('settings.category.advanced'),
        };
    }

    /** The `lang/` key {@see label()} resolves. */
    public function labelKey(): string
    {
        return 'settings.category.' . $this->value;
    }

    /** Tab order: declaration order, so reordering the cases reorders the tabs. */
    public function order(): int
    {
        return (int) array_search($this, self::cases(), true);
    }
}
