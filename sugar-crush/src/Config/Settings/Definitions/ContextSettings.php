<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Context\Pruning\PruningMode;
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
                ->withReadBy('`ProviderFactory::createOpenAI()`, `createAnthropic()`, `createCustom()` → each provider\'s `contextWindow()`'),
            // Roadmap 3.B-2: the default for every session that has not set
            // its own with `/pruning`. User config only — a cloned project
            // must not be able to turn a person's pruning on or off.
            SettingDefinition::new(PruningMode::SETTING, SettingType::Enum, PruningMode::DEFAULT->value)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar(PruningMode::ENV)
                ->withEnumValues(array_map(static fn (PruningMode $m): string => $m->value, PruningMode::cases()))
                ->withLabel('Context pruning')
                ->withHelp('auto prunes superseded rows at each turn start; manual only on /sweep; off shows no ref tags. /pruning overrides it per session.')
                ->withReaderSymbol(PruningMode::class . '::configured')
                ->withReadBy('`Host\\TurnRunner::start()` and `/pruning` → `PruningMode::configured()`'),
        ];
    }
}
