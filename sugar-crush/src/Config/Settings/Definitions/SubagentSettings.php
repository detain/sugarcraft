<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Agents\AgentPoolConfig;
use SugarCraft\Crush\Agents\EngineExecutor;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Tools\BuiltIn\TaskTool;
use SugarCraft\Crush\Tools\ToolLimits;

/**
 * The "Sub-agents" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 *
 * THE DELEGATION BOUNDS (roadmap N-P4f, design Appendix N §2.2). Each default
 * is the constant it replaced, cited rather than restated. All four are
 * Spend and user tier only: every one of them multiplies the provider calls a
 * turn may fan out on the operator's credential, so a checked-out repository
 * cannot raise them.
 */
final class SubagentSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Subagents;
    }

    public static function definitions(): array
    {
        return [
            // Roadmap 4.1-1. Spend, like titleModel/summaryModel: it picks
            // which model every inheriting delegation bills against, so it is
            // a user-tier key a cloned project cannot set.
            SettingDefinition::new('subagentModel', SettingType::String)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withLabel('Sub-agent model')
                ->withHelp('Model a delegated sub-agent runs on when its preset says `inherit`; unset follows the session\'s model.')
                ->withReaderSymbol(Bootstrap::class . '::agentManager'),
            SettingDefinition::new(EngineExecutor::MAX_TURNS_SETTINGS_KEY, SettingType::Int, EngineExecutor::DEFAULT_MAX_TURNS)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1)
                ->withLabel('Sub-agent max turns')
                ->withHelp('Step cap for a workflow-stage or `executeAgents` sub-agent whose preset declares no `maxTurns`.')
                ->withReaderSymbol(EngineExecutor::class . '::defaultMaxTurns')
                ->withReadBy('`EngineExecutor::execute()` → `defaultMaxTurns()`, as each run starts'),
            SettingDefinition::new(AgentPoolConfig::MAX_CONCURRENT_SETTINGS_KEY, SettingType::Int, AgentPoolConfig::DEFAULT_MAX_CONCURRENT)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withRange(1, 16)
                ->withLabel('Sub-agent fan-out')
                ->withHelp('`Task` calls of one batch that run at once (the rest wait for a free slot), and the width of every agent pool.')
                ->withReaderSymbol(AgentPoolConfig::class . '::withSettings')
                ->withReadBy('`Bootstrap::agentPoolConfig()` → `AgentPoolConfig::withSettings()`'),
            SettingDefinition::new(ToolLimits::SUBAGENT_MAX_DEPTH_KEY, SettingType::Int, TaskTool::MAX_DELEGATION_DEPTH)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 5)
                ->withLabel('Delegation depth')
                ->withHelp('Levels below the session a delegated run may be; a run at the last level gets no `Task`. 1 lets sub-agents delegate no further.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()` → `TaskTool::withDelegationLimits()`'),
            SettingDefinition::new(ToolLimits::SUBAGENT_MAX_ACTIVE_KEY, SettingType::Int, TaskTool::MAX_CONCURRENT_AGENTS)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 32)
                ->withLabel('Sub-agents running at once')
                ->withHelp('Delegated runs one session may have going at once, every level, member and background agent counted; past it a `Task` call is refused.')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()` → `TaskTool::withDelegationLimits()`'),
        ];
    }
}
