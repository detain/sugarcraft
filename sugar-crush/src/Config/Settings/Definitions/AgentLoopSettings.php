<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\Validator\SpendCapValidator;
use SugarCraft\Crush\Runtime;

/**
 * The "Agent loop" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
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
                ->withEnvVar('SUGARCRUSH_DISABLE_PARALLEL_TOOL_CALLS')
                ->withLabel('Parallel tool calls')
                ->withHelp('Run a turn\'s read-only tool calls concurrently.')
                ->withReaderSymbol(EngineBackend::class . '::complete'),
            SettingDefinition::new('parallelToolDeadlineSeconds', SettingType::Int, Runtime::PARALLEL_TOOL_DEADLINE_SECONDS)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar('SUGARCRUSH_PARALLEL_TOOL_DEADLINE')
                ->withRange(1)
                ->withLabel('Parallel tool deadline (s)')
                ->withHelp('Wall-clock ceiling for one batch of concurrently dispatched tool calls.')
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
                ->withLabel('Max tool steps')
                ->withHelp('Provider calls one turn may make; unset keeps the engine default.')
                ->withReaderSymbol(Bootstrap::class . '::resolvedMaxToolSteps')
                ->withReadBy('`Bootstrap::backend()`, `Chat::applySettings()` → `resolvedMaxToolSteps()`'),
            // Roadmap N-P4g: the persistent `/budget`. User tier only (Spend):
            // a checkout may neither impose a cap on the operator's turns nor
            // lift one. A present value that is not a ceiling refuses the launch,
            // as `$SUGARCRUSH_MAX_COST` does.
            SettingDefinition::new(Bootstrap::MAX_COST_SETTING, SettingType::Float)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar('SUGARCRUSH_MAX_COST')
                ->withValidators(SpendCapValidator::new())
                ->withLabel('Spend cap (USD)')
                ->withHelp('Refuse new turns once the provider-reported spend of a launch reaches this many US dollars; unset is no cap. /budget changes it for the running launch only.')
                ->withDefaultText('unset (no cap)')
                ->withReaderSymbol(Bootstrap::class . '::maxCostUsd')
                ->withReadBy('`Bootstrap::workspace()` → `maxCostUsd()` → `persistedMaxCostUsd()`'),
        ];
    }
}
