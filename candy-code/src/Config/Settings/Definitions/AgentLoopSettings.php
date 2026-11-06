<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Agents\Live\RunLiveness;
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
use SugarCraft\Code\Tools\BuiltIn\Bash;

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
            // The wall-clock ceiling of a parallel batch's group deadline. It
            // tracks Bash::MAX_TIMEOUT_SECONDS — the per-call ceiling of the
            // longest legitimate group member — so an operator can lift a whole
            // batch to the hour scale (tool-timeouts follow-up). The reader
            // honours a value only below `turnIdleTimeoutSeconds`: a group
            // deadline has to fit inside the turn's idle window. That key is
            // user-tier with a 120 s default, so an hour-scale deadline takes
            // effect paired with a raised idle ceiling; an unpaired one falls
            // back to the default below rather than being refused at save.
            SettingDefinition::new('parallelToolDeadlineSeconds', SettingType::Int, Runtime::PARALLEL_TOOL_DEADLINE_SECONDS)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar('CANDY_CODE_PARALLEL_TOOL_DEADLINE')
                ->withRange(1, Bash::MAX_TIMEOUT_SECONDS)
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
            // RS-AGENT-BACKLOG A1: the agent strip's stall HINT. User tier only
            // (no layered/project arm): a shared checkout must not be able to
            // silence the operator's own liveness flag. 0 is off and keeps the
            // strip byte-identical; a set value under the 30 s floor rises to
            // the floor AT READ (`RunLiveness::clampThreshold()`), never as a
            // save-time refusal. Detector only — a flagged row is a hint, not a
            // intervention; acting on a stall is the B5 follow-up.
            SettingDefinition::new(RunLiveness::SETTING_KEY, SettingType::Int, RunLiveness::OFF)
                ->withCategory(SettingCategory::AgentLoop)
                ->withRiskClass(RiskClass::Tuning)
                // Live: the strip re-reads the threshold per render through the
                // `UiSettings` memo, which `Chat::applySettings()` flushes on save.
                ->withApplyMode(ApplyMode::Live)
                ->withRange(0)
                ->withLabel(Lang::t('settings.agentStallSeconds.label'))
                ->withHelp(Lang::t('settings.agentStallSeconds.help'))
                ->withDefaultText('0 (off)')
                ->withReaderSymbol(RunLiveness::class . '::thresholdSeconds')
                ->withReadBy('`AgentStatusBar::render()` → `RunLiveness::thresholdSeconds()` → `UiSettings::int()`'),
        ];
    }
}
