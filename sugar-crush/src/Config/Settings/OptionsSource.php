<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings;

/**
 * Where a list- or enum-shaped key's choices come from at runtime, named
 * rather than held as a closure so a {@see SettingDefinition} stays a plain
 * value. The editor's options provider resolves each case to live names
 * (theme names, configured providers, discovered skills, …).
 */
enum OptionsSource: string
{
    case Themes = 'themes';
    case Providers = 'providers';
    case Skills = 'skills';
    case RulePacks = 'rule-packs';
    case Tools = 'tools';
    case PermissionModes = 'permission-modes';
    case McpServers = 'mcp-servers';
}
