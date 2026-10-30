<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\OptionsSource;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Config\Settings\Validator\AbsolutePathValidator;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Permissions\PermissionGate;
use SugarCraft\Code\Permissions\PermissionMode;
use SugarCraft\Code\Permissions\ReadOnlyCommands;

/**
 * The "Permissions" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
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
                ->withEnvVar('CANDY_CODE_PERMISSION_MODE')
                ->withCliFlag('--permission-mode')
                ->withEnumValues(array_map(static fn (PermissionMode $m): string => $m->value, PermissionMode::cases()))
                ->withOptionsSource(OptionsSource::PermissionModes)
                ->withLabel(Lang::t('settings.permissionMode.label'))
                ->withHelp(Lang::t('settings.permissionMode.help'))
                // D5 as amended by C-SEC-7.a: the TUI and `-p` ask by default;
                // only the spawned daemon has nobody to ask, so only it keeps
                // the schema default.
                ->withDefaultText('`default` (TUI, `-p`); `bypass-permissions` (daemon)')
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            SettingDefinition::new('permissionRules', SettingType::Json, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withStrict()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.permissionRules.label'))
                ->withHelp(Lang::t('settings.permissionRules.help'))
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            // Roadmap 5.11-2: user tier only (never a project's to switch on),
            // because every review is a paid call on the title model.
            //
            // C-SEC-7.e (owner ruling t212, option 3): the stored default stays
            // false — an untouched config file keeps its byte-identical shape.
            // The SHIPPED default is implicit ON: Bootstrap::permissionGate()
            // engages the reviewer whenever the key is absent AND a reviewable
            // model exists, announcing once per session. This literal is what a
            // config editor displays as the value's type-default; the gate is
            // what decides engagement. false is a real opt-out and never engages.
            SettingDefinition::new('autoReview', SettingType::Bool, false)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withLabel(Lang::t('settings.autoReview.label'))
                ->withHelp(Lang::t('settings.autoReview.help'))
                ->withReaderSymbol(Bootstrap::class . '::permissionGate'),
            // C-SEC-7.d: what the auto reviewer reviews and what it reviews
            // it with. User tier only like the switch itself — a repository
            // may neither widen the paid-call scope nor point the reviewer at
            // a model of its choosing. `shell` is the ISS-013 law unchanged;
            // `writes` and `all` trade money for coverage and are the
            // operator's decision, not the checkout's. No env var and no CLI
            // flag on either: a stray spelling in a shared shell profile must
            // not start a paid review loop.
            SettingDefinition::new(PermissionGate::AUTO_REVIEW_SCOPE_SETTING, SettingType::Enum, 'shell')
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnumValues(PermissionGate::AUTO_REVIEW_SCOPES)
                ->withApplyMode(ApplyMode::Live)
                ->withLabel(Lang::t('settings.autoReviewScope.label'))
                ->withHelp(Lang::t('settings.autoReviewScope.help'))
                ->withReaderSymbol(PermissionGate::class . '::autoReviewScope'),
            SettingDefinition::new('autoReviewModel', SettingType::String, '')
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withLabel(Lang::t('settings.autoReviewModel.label'))
                ->withHelp(Lang::t('settings.autoReviewModel.help'))
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
                ->withHelp(Lang::t('settings.permissions.autoStrikeLimit.help'))
                ->withReaderSymbol(PermissionGate::class . '::autoBreakerLimits')
                ->withReadBy('`PermissionGate::evaluateAuto()`, `autoBreaker()` → `autoBreakerLimits()`'),
            SettingDefinition::new(PermissionGate::TOTAL_LIMIT_SETTING, SettingType::Int, PermissionGate::TOTAL_BLOCK_THRESHOLD)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 1000)
                ->withLabel(Lang::t('settings.permissions.autoTotalLimit.label'))
                ->withHelp(Lang::t('settings.permissions.autoTotalLimit.help'))
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
                ->withHelp(Lang::t('settings.permissions.autoAllowReadOnly.help'))
                ->withReaderSymbol(ReadOnlyCommands::class . '::autoAllowEnabled')
                ->withReadBy('`PermissionGate::evaluateDefault()` / `evaluateAcceptEdits()` → `readOnlyAutoAllow()` → `ReadOnlyCommands::autoAllowEnabled()`'),
            // C-SEC-8.b: opt-IN secret redaction of settled tool output.
            // Off by default: results land in history, sessions.db and the
            // event log raw, exactly as they always have, and nothing here
            // alters what the tool itself executed. On, Runtime folds every
            // result through SecretRedactor once it has settled — after the
            // UTF-8 and tag scrubs, before the ToolFinished event — so the
            // bytes persisted and replayed to the model carry [REDACTED]
            // where a credential sat. User tier only, like the auto-review
            // knobs: a setting that changes what a transcript keeps must not
            // be flipped by a checked-out project (and turning it ON is the
            // safe direction — a project file that could set it would be an
            // argument for hiding output, not a user choice to protect their
            // own secrets). /export adds no redaction of its own: it prints
            // exactly what was stored, so anything saved before turning this
            // on stays raw and a turn saved after it exports redacted; the
            // export promise is about the LOCAL copy, and redaction never
            // rewrites persisted history. No env var and no CLI flag: a
            // launch-time
            // escape hatch would let one shell line silently change what
            // later turns persist. Read per turn (NextTurn) by
            // EngineBackend::newRuntime.
            SettingDefinition::new('redactToolOutput', SettingType::Bool, false)
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel(Lang::t('settings.redactToolOutput.label'))
                ->withHelp(Lang::t('settings.redactToolOutput.help'))
                ->withReaderSymbol(EngineBackend::class . '::newRuntime'),
            SettingDefinition::new('secretEnvAllowlist', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withLabel(Lang::t('settings.secretEnvAllowlist.label'))
                ->withHelp(Lang::t('settings.secretEnvAllowlist.help'))
                ->withReaderSymbol(Bootstrap::class . '::installSecretEnvAllowlist')
                ->withReadBy('`Bootstrap::tools()` → `installSecretEnvAllowlist()`'),
            SettingDefinition::new('trustedProjectHooks', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel(Lang::t('settings.trustedProjectHooks.label'))
                ->withHelp(Lang::t('settings.trustedProjectHooks.help'))
                ->withReaderSymbol(Bootstrap::class . '::trustedRootsForThisProcess'),
            SettingDefinition::new('trustedProjectMcp', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel(Lang::t('settings.trustedProjectMcp.label'))
                ->withHelp(Lang::t('settings.trustedProjectMcp.help'))
                ->withReaderSymbol(Bootstrap::class . '::projectMcpIsTrusted'),
            SettingDefinition::new('trustedProjectCommands', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel(Lang::t('settings.trustedProjectCommands.label'))
                ->withHelp(Lang::t('settings.trustedProjectCommands.help'))
                ->withReaderSymbol(Bootstrap::class . '::projectCommandShellIsTrusted'),
            SettingDefinition::new('trustedProjectSettings', SettingType::StringList, [])
                ->withCategory(SettingCategory::Permissions)
                ->withRiskClass(RiskClass::Security)
                ->withApplyMode(ApplyMode::Frozen)
                ->withUi(UiEditability::List)
                ->withValidators(AbsolutePathValidator::new())
                ->withLabel(Lang::t('settings.trustedProjectSettings.label'))
                ->withHelp(Lang::t('settings.trustedProjectSettings.help'))
                ->withReaderSymbol(Bootstrap::class . '::projectSettingsTrusted'),
        ];
    }
}
