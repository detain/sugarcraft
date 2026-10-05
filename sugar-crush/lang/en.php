<?php

declare(strict_types=1);

/**
 * English source-of-truth catalogue for sugar-crush's `crush` namespace
 * ({@see \SugarCraft\Crush\Lang}). Every other `lang/<locale>.php` carries
 * exactly these keys with the same `{placeholders}` (tests/LangParityTest.php).
 *
 * The settings tabs are seeded first because their keys already exist:
 * {@see \SugarCraft\Crush\Config\Settings\SettingCategory::labelKey()} names
 * each one, and LangParityTest pins every value here to the literal
 * {@see \SugarCraft\Crush\Config\Settings\SettingCategory::label()} still
 * returns (decision D7), so swapping that method onto Lang::t() changes
 * nothing in English.
 */
return [
    'settings.category.model' => 'Model & Provider',
    'settings.category.loop' => 'Agent loop',
    'settings.category.context' => 'Context & Compaction',
    'settings.category.permissions' => 'Permissions',
    'settings.category.tools' => 'Tools',
    'settings.category.memory' => 'Memory & Rules',
    'settings.category.skills' => 'Skills',
    'settings.category.subagents' => 'Sub-agents',
    'settings.category.git' => 'Git & Automation',
    'settings.category.interface' => 'Interface',
    'settings.category.hooks' => 'Hooks & MCP',
    'settings.category.server' => 'Server',
    'settings.category.advanced' => 'Advanced',
];
