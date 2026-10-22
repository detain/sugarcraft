<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Backend\EngineBackend;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\OptionsSource;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderFactory;

/**
 * The "Model & Provider" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
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
                ->withEnvVar('CANDY_CODE_PROVIDER')
                ->withOptionsSource(OptionsSource::Providers)
                ->withLabel(Lang::t('settings.provider.label'))
                ->withHelp(Lang::t('settings.provider.help'))
                ->withReaderSymbol(Bootstrap::class . '::selectedProviderName')
                ->withReadBy('`Bootstrap::selectedProviderName()`, `backend()`'),
            // D9: the model choice persists per provider, user tier only, so a
            // provider switch lands on the model last chosen FOR THAT provider
            // and a model id is never sent to a provider it was not chosen for.
            // `--model` and $CANDY_CODE_MODEL still outrank it for every provider.
            SettingDefinition::new('models', SettingType::Map, [])
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('CANDY_CODE_MODEL')
                ->withCliFlag('--model')
                ->withLabel(Lang::t('settings.models.label'))
                ->withHelp(Lang::t('settings.models.help'))
                ->withReaderSymbol(Bootstrap::class . '::selectedModelName')
                ->withReadBy('`Bootstrap::selectedModelName()`, `backendFor()`, `selectedProviderLabel()`'),
            // rv-enduser-1: the endpoint lockout fix. A map of provider NAME
            // => partial provider config ({type, baseUrl, apiKey, model?}),
            // layered over the built-in defaults by
            // `Bootstrap::providerConfigFor()` on every wire path. User tier
            // only — the whole map's direction is Egress (it decides where
            // prompts and credentials go), and the doctrine behind
            // `RiskClass::projectSettableAllowed()` refuses to let a
            // checked-out repository redirect the operator's key. Project-side
            // provider NAMES keep flowing through the package's own
            // .candy-code/config.dev.json fixture, unchanged.
            SettingDefinition::new('providers', SettingType::Map, [])
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.providers.label'))
                ->withHelp(Lang::t('settings.providers.help'))
                ->withReaderSymbol(Bootstrap::class . '::providerOverrides')
                ->withReadBy('`Bootstrap::providerOverrides()`, `providerConfigFor()` → `provider()`, `backendFor()`, `workerProviderSpec()`, `toollessBackend()`, `selectedProviderLabel()`, `availableProviders()`'),
            SettingDefinition::new('titleModel', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('CANDY_CODE_TITLE_MODEL')
                ->withLabel(Lang::t('settings.titleModel.label'))
                ->withHelp(Lang::t('settings.titleModel.help'))
                ->withReaderSymbol(Bootstrap::class . '::titleBackend'),
            SettingDefinition::new('summaryModel', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('CANDY_CODE_SUMMARY_MODEL')
                ->withLabel(Lang::t('settings.summaryModel.label'))
                ->withHelp(Lang::t('settings.summaryModel.help'))
                ->withReaderSymbol(Bootstrap::class . '::summaryModel')
                ->withReadBy('`Bootstrap::summaryModel()`, `summaryBackend()`'),
            SettingDefinition::new('maxOutputTokens', SettingType::Int)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(1)
                ->withLabel(Lang::t('settings.maxOutputTokens.label'))
                ->withHelp(Lang::t('settings.maxOutputTokens.help'))
                ->withReaderSymbol(EngineBackend::class . '::complete'),
            SettingDefinition::new('modelPrices', SettingType::Map, [])
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.modelPrices.label'))
                ->withHelp(Lang::t('settings.modelPrices.help'))
                ->withReaderSymbol(ProviderFactory::class . '::userTierModelPrices')
                ->withReadBy('`ProviderFactory::createOpenAI()`, `createAnthropic()`, `createVertex()`, `createBedrock()`, `createCustom()` → `userTierModelPrices()`'),
            SettingDefinition::new('extraBody', SettingType::Map)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Egress)
                ->withLayered()
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.extraBody.label'))
                ->withHelp(Lang::t('settings.extraBody.help'))
                ->withReaderSymbol(ProviderFactory::class . '::createCustom')
                ->withReadBy('`ProviderFactory::createCustom()` → `CustomProvider`'),
            SettingDefinition::new('thinkingBudget', SettingType::Int)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withRange(0)
                ->withLabel(Lang::t('settings.thinkingBudget.label'))
                ->withHelp(Lang::t('settings.thinkingBudget.help'))
                ->withReaderSymbol(ProviderFactory::class . '::createVertex')
                ->withReadBy('`ProviderFactory::createVertex()` → `VertexProvider`, `createAnthropic()` → `AnthropicProvider`'),
            // PRV-2 (§6.5): the reasoning-effort tier, named in words instead
            // of tokens. The number a `thinkingBudget` is for the Anthropic
            // family, this is for every family: each shaped provider maps the
            // tier to its own control (Anthropic/Vertex-anthropic arms map it
            // to a thinking budget, Gemini derives its `thinkingConfig` from
            // it when `thinkingBudget` is unset, OpenAI sends its
            // `reasoning_effort` string, SGLang its validated level), and a
            // provider that shapes nothing refuses `/effort` loudly rather
            // than storing a knob no wire reads.
            SettingDefinition::new('reasoningEffort', SettingType::String)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                // Live: a save re-applies it to the running engine
                // (`Chat::applySettings()` → `EngineBackend::withReasoningEffort()`),
                // held until a running turn ends — the same door `maxToolSteps`
                // walks, so `/effort` and the settings panel cannot disagree.
                ->withApplyMode(ApplyMode::Live)
                ->withEnumValues(array_map(static fn (\SugarCraft\Code\Agents\Effort $level): string => $level->value, \SugarCraft\Code\Agents\Effort::cases()))
                ->withLabel(Lang::t('settings.reasoningEffort.label'))
                ->withHelp(Lang::t('settings.reasoningEffort.help'))
                ->withReaderSymbol(Bootstrap::class . '::resolvedReasoningEffort')
                ->withReadBy('`Bootstrap::backend()`, `backendFor()`, `Chat::applySettings()` → `resolvedReasoningEffort()`'),
            SettingDefinition::new('promptCache', SettingType::Bool, true)
                ->withCategory(SettingCategory::ModelProvider)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withEnvVar('CANDY_CODE_DISABLE_PROMPT_CACHE')
                ->withLabel(Lang::t('settings.promptCache.label'))
                ->withHelp(Lang::t('settings.promptCache.help'))
                ->withReaderSymbol(ProviderFactory::class . '::promptCacheEnabled')
                ->withReadBy('`ProviderFactory::createVertex()`, `createBedrock()` → `promptCacheEnabled()`'),
        ];
    }
}
