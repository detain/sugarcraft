<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * The "Context" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class ContextSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Context;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('contextWindow', SettingType::Json)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withLabel('Context window')
                ->withHelp('Token window override: a count for the provider\'s model, or {"<model>": tokens}.')
                ->withReaderSymbol(ProviderFactory::class . '::createOpenAI')
                ->withReadBy('`ProviderFactory::createOpenAI()` → `OpenAIProvider::contextWindow()`'),
        ];
    }
}
