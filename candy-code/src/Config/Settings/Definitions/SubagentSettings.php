<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Agents\AgentPoolConfig;
use SugarCraft\Code\Agents\EngineExecutor;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Tools\BuiltIn\TaskTool;
use SugarCraft\Code\Tools\ToolLimits;

/**
 * The "Sub-agents" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
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
                ->withLabel(Lang::t('settings.subagentModel.label'))
                ->withHelp(Lang::t('settings.subagentModel.help'))
                ->withReaderSymbol(Bootstrap::class . '::agentManager'),
            SettingDefinition::new(EngineExecutor::MAX_TURNS_SETTINGS_KEY, SettingType::Int, EngineExecutor::DEFAULT_MAX_TURNS)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1)
                ->withLabel(Lang::t('settings.subagentMaxTurns.label'))
                ->withHelp(Lang::t('settings.subagentMaxTurns.help'))
                ->withReaderSymbol(EngineExecutor::class . '::defaultMaxTurns')
                ->withReadBy('`TaskTool` (engine path) and `EngineExecutor::execute()` → `EngineExecutor::defaultMaxTurns()`, as each run starts'),
            SettingDefinition::new(AgentPoolConfig::MAX_CONCURRENT_SETTINGS_KEY, SettingType::Int)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withRange(0)
                ->withLabel(Lang::t('settings.subagentMaxConcurrent.label'))
                ->withHelp(Lang::t('settings.subagentMaxConcurrent.help'))
                ->withDefaultText('no cap')
                ->withReaderSymbol(AgentPoolConfig::class . '::withSettings')
                ->withReadBy('`Bootstrap::agentPoolConfig()` → `AgentPoolConfig::withSettings()`'),
            SettingDefinition::new(ToolLimits::SUBAGENT_MAX_DEPTH_KEY, SettingType::Int, TaskTool::MAX_DELEGATION_DEPTH)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1, 5)
                ->withLabel(Lang::t('settings.subagentMaxDepth.label'))
                ->withHelp(Lang::t('settings.subagentMaxDepth.help'))
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()` → `TaskTool::withDelegationLimits()`'),
            SettingDefinition::new(ToolLimits::SUBAGENT_MAX_ACTIVE_KEY, SettingType::Int)
                ->withCategory(SettingCategory::Subagents)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(0)
                ->withLabel(Lang::t('settings.subagentMaxActive.label'))
                ->withHelp(Lang::t('settings.subagentMaxActive.help'))
                ->withDefaultText('no cap')
                ->withReaderSymbol(ToolLimits::class . '::applyTo')
                ->withReadBy('`EngineBackend::turnTools()` → `ToolLimits::applyTo()` → `TaskTool::withDelegationLimits()`'),
        ];
    }
}
