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
use SugarCraft\Crush\Lang;
use SugarCraft\Crush\Permissions\PermissionGate;
use SugarCraft\Crush\Permissions\PermissionMode;
use SugarCraft\Crush\Permissions\ReadOnlyCommands;

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
                ->withLabel(Lang::t('settings.permissionMode.label'))
                ->withHelp('How tool calls are gated. Never taken from a project file.')
                // D5: the TUI asks by default; `-p` and the daemon have nobody
                // to ask, so they keep the schema default.
                ->withDefaultText('`default` (TUI); `bypass-permissions` (`-p`, daemon)')
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            SettingDefinition::new('permissionRules', SettingType::Json, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withStrict()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.permissionRules.label'))
                ->withHelp('Ordered allow / deny / ask rules matched against each tool call.')
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            // Roadmap 5.11-2: user tier only (never a project's to switch on),
            // because every review is a paid call on the title model.
            SettingDefinition::new('autoReview', SettingType::Bool, false)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withLabel(Lang::t('settings.autoReview.label'))
                ->withHelp('Under auto, a call the safety classifier flags (other than a security finding, which always asks) is reviewed by the title model, which allows it, asks you or denies it.')
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            // Roadmap N-P4g: Auto's circuit breaker, promoted from the gate's
            // constants. User tier only (Security): a repository raising them
            // would let a run of blocked calls go on longer before a person
            // is asked. Read on use through UiSettings, so the next turn after
            // a save compares against the new numbers.
            SettingDefinition::new(PermissionGate::STRIKE_LIMIT_SETTING, SettingType::Int, PermissionGate::STRIKE_THRESHOLD)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 100)
                ->withLabel(Lang::t('settings.permissions.autoStrikeLimit.label'))
                ->withHelp('Under auto, this many blocked calls of one category in a row turn the next one into a question.')
                ->withReaderSymbol(PermissionGate::class . '::autoBreakerLimits')
                ->withReadBy('`PermissionGate::evaluateAuto()`, `autoBreaker()` → `autoBreakerLimits()`'),
            SettingDefinition::new(PermissionGate::TOTAL_LIMIT_SETTING, SettingType::Int, PermissionGate::TOTAL_BLOCK_THRESHOLD)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 1000)
                ->withLabel(Lang::t('settings.permissions.autoTotalLimit.label'))
                ->withHelp('Under auto, once this many calls have been blocked in a session every further block asks you instead.')
                ->withReaderSymbol(PermissionGate::class . '::autoBreakerLimits')
                ->withReadBy('`PermissionGate::evaluateAuto()`, `autoBreaker()` → `autoBreakerLimits()`'),
            // User decision 2026-10-11: under default and accept-edits a Bash
            // line made entirely of read-only commands runs unasked. Turning
            // it off only adds questions, so a trusted project may (Narrowing)
            // — and only off: the reader ANDs the value without the project
            // tier, so a project cannot switch back on what the user turned off.
            SettingDefinition::new(ReadOnlyCommands::SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel(Lang::t('settings.permissions.autoAllowReadOnly.label'))
                ->withHelp('Under default and accept-edits, run a Bash line made only of read-only commands (ls, cat, grep, git log, …; no file redirection, no substitution, cd only inside the project) without asking. A project may only switch it off.')
                ->withReaderSymbol(ReadOnlyCommands::class . '::autoAllowEnabled')
                ->withReadBy('`PermissionGate::evaluateDefault()` / `evaluateAcceptEdits()` → `readOnlyAutoAllow()` → `ReadOnlyCommands::autoAllowEnabled()`'),
            SettingDefinition::new('secretEnvAllowlist', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.secretEnvAllowlist.label'))
                ->withHelp('Credential-shaped variable names (or globs) Bash, Grep and script hooks still inherit.')
                ->withReaderSymbol(Bootstrap::class . '::installSecretEnvAllowlist')
                ->withReadBy('`Bootstrap::tools()` → `installSecretEnvAllowlist()`'),
            SettingDefinition::new('trustedProjectHooks', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel(Lang::t('settings.trustedProjectHooks.label'))
                ->withHelp('Project roots whose .sugar-crush/hooks.yaml may run.')
                ->withReaderSymbol(Bootstrap::class . '::trustedRootsForThisProcess'),
            SettingDefinition::new('trustedProjectMcp', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel(Lang::t('settings.trustedProjectMcp.label'))
                ->withHelp('Project roots whose .mcp.json servers may be launched.')
                ->withReaderSymbol(Bootstrap::class . '::projectMcpIsTrusted'),
            SettingDefinition::new('trustedProjectCommands', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel(Lang::t('settings.trustedProjectCommands.label'))
                ->withHelp('Project roots whose slash commands may run !`cmd` shell blocks.')
                ->withReaderSymbol(Bootstrap::class . '::projectCommandShellIsTrusted'),
            SettingDefinition::new('trustedProjectSettings', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel(Lang::t('settings.trustedProjectSettings.label'))
                ->withHelp('Project roots whose .sugar-crush/settings*.json may set the project-tier keys.')
                ->withReaderSymbol(Bootstrap::class . '::projectSettingsTrusted'),
        ];
    }
}
