<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\Validator\SpendCapValidator;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Runtime;

/**
 * The "Agent loop" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class AgentLoopSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::AgentLoop;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('parallelToolCalls', SettingType::Bool, true)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar('CANDY_CODE_DISABLE_PARALLEL_TOOL_CALLS')
                ->withLabel(Lang::t('settings.parallelToolCalls.label'))
                ->withHelp(Lang::t('settings.parallelToolCalls.help'))
                ->withReaderSymbol(EngineBackend::class . '::complete'),
            SettingDefinition::new('parallelToolDeadlineSeconds', SettingType::Int, Runtime::PARALLEL_TOOL_DEADLINE_SECONDS)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar('CANDY_CODE_PARALLEL_TOOL_DEADLINE')
                ->withRange(1)
                ->withLabel(Lang::t('settings.parallelToolDeadlineSeconds.label'))
                ->withHelp(Lang::t('settings.parallelToolDeadlineSeconds.help'))
                ->withReaderSymbol(EngineBackend::class . '::complete'),
            SettingDefinition::new('maxToolSteps', SettingType::Int)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                // N-P3: a save re-applies it to the running engine
                // (`Chat::applySettings()` → `EngineBackend::withMaxSteps()`),
                // held until a running turn ends.
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1)
                ->withLabel(Lang::t('settings.maxToolSteps.label'))
                ->withHelp(Lang::t('settings.maxToolSteps.help'))
                ->withReaderSymbol(Bootstrap::class . '::resolvedMaxToolSteps')
                ->withReadBy('`Bootstrap::backend()`, `Chat::applySettings()` → `resolvedMaxToolSteps()`'),
            // Roadmap N-P4g: the persistent `/budget`. User tier only (Spend):
            // a checkout may neither impose a cap on the operator's turns nor
            // lift one. A present value that is not a ceiling refuses the launch,
            // as `$CANDY_CODE_MAX_COST` does.
            SettingDefinition::new(Bootstrap::MAX_COST_SETTING, SettingType::Float)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                // Live: Chat::applySettings() re-resolves the cap a save names,
                // into the same field `/budget` sets.
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar('CANDY_CODE_MAX_COST')
                ->withValidators(SpendCapValidator::new())
                ->withLabel(Lang::t('settings.maxCostUsd.label'))
                ->withHelp(Lang::t('settings.maxCostUsd.help'))
                ->withDefaultText('unset (no cap)')
                ->withReaderSymbol(Bootstrap::class . '::maxCostUsd')
                ->withReadBy('`Bootstrap::workspace()`, `Chat::applySettings()` → `maxCostUsd()` → `persistedMaxCostUsd()`'),
        ];
    }
}
