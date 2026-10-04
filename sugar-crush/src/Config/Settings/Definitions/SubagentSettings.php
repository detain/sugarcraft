<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;

/**
 * The "Sub-agents" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
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
        ];
    }
}
