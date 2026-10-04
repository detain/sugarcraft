<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Config\Settings\Validator\ExecutableValidator;

/**
 * The "Hooks & MCP" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class HooksMcpSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::HooksMcp;
    }

    public static function definitions(): array
    {
        return [
            // Step 3.E: extension => lint command (or false to turn a default
            // off) for the post-edit lint hook. User-tier only: every value is
            // a shell command the hook runs after an edit, so a project-tier
            // one would be code execution on clone-and-launch (`statusLine`'s
            // argument).
            SettingDefinition::new('lintCommands', SettingType::Map, [])
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel('Lint commands')
                ->withHelp('Post-edit lint command per file extension ({"php": "…", "js": false}); `php -l` is built in.')
                ->withReaderSymbol(Bootstrap::class . '::hooks')
                ->withReadBy('`Bootstrap::hooks()` → `LintRunner::withCommands()`'),
            SettingDefinition::new('claudeMcpBinary', SettingType::Path)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withApplyMode(ApplyMode::Frozen)
                ->withValidators(ExecutableValidator::new())
                ->withLabel('Claude MCP binary')
                ->withHelp('Absolute path of the `claude` binary the claude-mcp transport may spawn.')
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
            SettingDefinition::new('claudeMcpArgs', SettingType::StringList)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Exec)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withLabel('Claude MCP arguments')
                ->withHelp('Arguments for that binary; unset uses the transport default.')
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
            SettingDefinition::new('claudeMcpEnv', SettingType::Map)
                ->withCategory(SettingCategory::HooksMcp)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::Complex)
                ->withLabel('Claude MCP environment')
                ->withHelp('Literal environment variables handed to that binary.')
                ->withReaderSymbol(Bootstrap::class . '::claudeMcpGrant'),
        ];
    }
}
