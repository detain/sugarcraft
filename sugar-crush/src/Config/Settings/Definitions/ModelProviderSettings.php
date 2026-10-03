<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Backend\EngineBackend;
use SugarCraft\Crush\Cli\Bootstrap;
use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\OptionsSource;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * The "Model & Provider" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 */
final class ModelProviderSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::ModelProvider;
    }

    public static function definitions(): array
    {
        return [
            SettingDefinition::new('provider', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withEnvVar('SUGARCRUSH_PROVIDER')
                ->withOptionsSource(OptionsSource::Providers)
                ->withLabel('Provider')
                ->withHelp('Which LLM provider the session talks to; every prompt is sent to its host.')
                ->withReaderSymbol(Bootstrap::class . '::selectedProviderName')
                ->withReadBy('`Bootstrap::selectedProviderName()`, `backend()`'),
            SettingDefinition::new('titleModel', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('SUGARCRUSH_TITLE_MODEL')
                ->withLabel('Title model')
                ->withHelp('Model that names sessions and writes prompt suggestions; unset uses the provider default.')
                ->withReaderSymbol(Bootstrap::class . '::titleBackend'),
            SettingDefinition::new('summaryModel', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('SUGARCRUSH_SUMMARY_MODEL')
                ->withLabel('Summary model')
                ->withHelp('Model that writes /compact summaries; unset uses the provider default.')
                ->withReaderSymbol(Bootstrap::class . '::summaryBackend'),
            SettingDefinition::new('maxOutputTokens', SettingType::Int)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1)
                ->withLabel('Max output tokens')
                ->withHelp('Per-request output ceiling; unset sends no override and the provider default applies.')
                ->withReaderSymbol(EngineBackend::class . '::complete'),
            SettingDefinition::new('modelPrices', SettingType::Map, [])
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel('Model prices')
                ->withHelp('USD per 1M tokens per model ({"input": …, "output": …}), for the spend total and cap.')
                ->withReaderSymbol(ProviderFactory::class . '::userTierModelPrices')
                ->withReadBy('`ProviderFactory::createOpenAI()`, `createVertex()`, `createBedrock()` → `userTierModelPrices()`'),
            SettingDefinition::new('extraBody', SettingType::Map)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel('Extra request body')
                ->withHelp('Top-level request fields added to every `custom` provider request.')
                ->withReaderSymbol(ProviderFactory::class . '::createCustom')
                ->withReadBy('`ProviderFactory::createCustom()` → `CustomProvider`'),
            SettingDefinition::new('thinkingBudget', SettingType::Int)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withRange(0)
                ->withLabel('Thinking budget')
                ->withHelp('Gemini thinking-token budget on the `vertex` provider; thinking tokens bill as output.')
                ->withReaderSymbol(ProviderFactory::class . '::createVertex')
                ->withReadBy('`ProviderFactory::createVertex()` → `VertexProvider`'),
            SettingDefinition::new('promptCache', SettingType::Bool, true)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('SUGARCRUSH_DISABLE_PROMPT_CACHE')
                ->withLabel('Prompt cache')
                ->withHelp('Whether the `vertex` and `bedrock` providers mark prompt-cache breakpoints.')
                ->withReaderSymbol(ProviderFactory::class . '::promptCacheEnabled')
                ->withReadBy('`ProviderFactory::createVertex()`, `createBedrock()` → `promptCacheEnabled()`'),
        ];
    }
}
