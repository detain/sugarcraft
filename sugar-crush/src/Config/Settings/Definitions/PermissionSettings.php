<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\OptionsSource;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Config\Settings\Validator\AbsolutePathValidator;
use SugarCraft\Crush\Permissions\PermissionMode;

/**
 * The "Permissions" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class PermissionSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Permissions;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('permissionMode', SettingType::Enum, PermissionMode::BypassPermissions->value)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withStrict()
                ->withEnvVar('SUGARCRUSH_PERMISSION_MODE')
                ->withCliFlag('--permission-mode')
                ->withEnumValues(array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases()))
                ->withOptionsSource(OptionsSource::PermissionModes)
                ->withLabel('Permission mode')
                ->withHelp('How tool calls are gated. Never taken from a project file.')
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            SettingDefinition::new('permissionRules', SettingType::Json, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withStrict()
                ->withUi(UiEditability::Complex)
                ->withLabel('Permission rules')
                ->withHelp('Ordered allow / deny / ask rules matched against each tool call.')
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            SettingDefinition::new('secretEnvAllowlist', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withLabel('Secret env allowlist')
                ->withHelp('Credential-shaped variable names (or globs) Bash, Grep and script hooks still inherit.')
                ->withReaderSymbol(Bootstrap::class . '::installSecretEnvAllowlist')
                ->withReadBy('`Bootstrap::tools()` → `installSecretEnvAllowlist()`'),
            SettingDefinition::new('trustedProjectHooks', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel('Trusted project hooks')
                ->withHelp('Project roots whose .sugar-crush/hooks.yaml may run.')
                ->withReaderSymbol(Bootstrap::class . '::trustedRootsForThisProcess'),
            SettingDefinition::new('trustedProjectMcp', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel('Trusted project MCP')
                ->withHelp('Project roots whose .mcp.json servers may be launched.')
                ->withReaderSymbol(Bootstrap::class . '::projectMcpIsTrusted'),
            SettingDefinition::new('trustedProjectCommands', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel('Trusted project commands')
                ->withHelp('Project roots whose slash commands may run !`cmd` shell blocks.')
                ->withReaderSymbol(Bootstrap::class . '::projectCommandShellIsTrusted'),
            SettingDefinition::new('trustedProjectSettings', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel('Trusted project settings')
                ->withHelp('Project roots whose .sugar-crush/settings*.json may set the project-tier keys.')
                ->withReaderSymbol(Bootstrap::class . '::projectSettingsTrusted'),
        ];
    }
}
