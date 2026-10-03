<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * The settings editor's tabs, and the grouping of the generated key table.
 *
 * Each case that holds keys has ONE definitions file under `Definitions/`, so a
 * step adding a key edits that category's file and nothing shared (DH-KEYS).
 * A case with no file yet is a tab the editor will show empty — the design's
 * category list is adopted whole so a later key lands in a category that
 * already exists rather than reopening this enum.
 *
 * LABELS ARE LITERAL ENGLISH (decision D7): sugar-crush has no `Lang` class
 * yet, and i18n is deferred until after the roadmap. {@see labelKey()} is kept
 * so that step can swap the source without renaming anything.
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
    case Advanced = 'advanced';

    public function label(): string
    {
        return match ($this) {
            self::ModelProvider => 'Model & Provider',
            self::AgentLoop => 'Agent loop',
            self::Context => 'Context & Compaction',
            self::Permissions => 'Permissions',
            self::Tools => 'Tools',
            self::MemoryRules => 'Memory & Rules',
            self::Skills => 'Skills',
            self::Subagents => 'Sub-agents',
            self::Git => 'Git & Automation',
            self::Interface => 'Interface',
            self::HooksMcp => 'Hooks & MCP',
            self::Advanced => 'Advanced',
        };
    }

    /** The i18n key the label will move to (D7: not resolved yet). */
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
