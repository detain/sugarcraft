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
                ->withRange(1)
                ->withLabel('Max tool steps')
                ->withHelp('Provider calls one turn may make; unset keeps the engine default.')
                ->withReaderSymbol(Bootstrap::class . '::resolvedMaxToolSteps')
                ->withReadBy('`Bootstrap::backend()` → `resolvedMaxToolSteps()`'),
        ];
    }
}
