<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\OptionsSource;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;

/**
 * The "Memory & Rules" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class MemoryRuleSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::MemoryRules;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('instructions', SettingType::StringList, [])
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withLabel('Forced instructions')
                ->withHelp('Globs of files whose contents become authoritative system-prompt text.')
                ->withReaderSymbol(Bootstrap::class . '::forcedInstructions'),
            SettingDefinition::new('disabledRules', SettingType::StringList, [])
                ->withCategory(SettingCategory::MemoryRules)
                ->withRiskClass(RiskClass::Prompt)
                ->withLayered()
                ->withUi(UiEditability::List)
                ->withOptionsSource(OptionsSource::RulePacks)
                ->withLabel('Disabled rule packs')
                ->withHelp('User-tier rule packs kept out of the prompt from the first turn.')
                ->withReaderSymbol(Bootstrap::class . '::rulePacksToDisable')
                ->withReadBy('`Bootstrap::chat()` → `RulesState::new()`'),
        ];
    }
}
