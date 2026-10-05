<?php

declare(strict_types=1);

namespace SugarCraft\Crush\Config\Settings\Definitions;

use SugarCraft\Crush\Config\Settings\ApplyMode;
use SugarCraft\Crush\Config\Settings\RiskClass;
use SugarCraft\Crush\Config\Settings\SettingCategory;
use SugarCraft\Crush\Config\Settings\SettingDefinition;
use SugarCraft\Crush\Config\Settings\SettingDefinitionSet;
use SugarCraft\Crush\Config\Settings\SettingType;
use SugarCraft\Crush\Config\Settings\UiEditability;
use SugarCraft\Crush\Config\Settings\Validator\ThresholdOrderValidator;
use SugarCraft\Crush\Context\CompactorConfig;
use SugarCraft\Crush\Context\Pruning\PruningMode;
use SugarCraft\Crush\Context\SymbolMapBlock;
use SugarCraft\Crush\Providers\ProviderFactory;

/**
 * The "Context" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Crush\Config\Settings\SettingsSchema}
 * collects every set.
 *
 * THE COMPACTION KEYS (roadmap N-P4b, Appendix N §2.2) are dotted flat keys
 * on disk (`"compaction.autoPercent": 80`), Appendix N §5.5's recommendation,
 * so the shallow layer merge never replaces one with another. Each default IS
 * the {@see CompactorConfig} constructor default it describes, read off a
 * fresh {@see CompactorConfig::new()} rather than restated. All are read once
 * at launch (`Bootstrap::chat()` hands the session one config; a backend
 * handed none reads them itself), so they apply at restart. Every one is
 * Tuning a trusted project may set (Appendix N §2.2): a tier moved either way
 * costs the session time or context quality, never a refusal it could not
 * get out of — only the blocking percentage refuses, and it is bounded at 99%.
 */
final class ContextSettings implements SettingDefinitionSet
{
    public static function category(): SettingCategory
    {
        return SettingCategory::Context;
    }

    public static function definitions(): array
    {
        $defaults = CompactorConfig::new();
        $order = ThresholdOrderValidator::new([
            CompactorConfig::SETTING_REMINDER_PERCENT,
            CompactorConfig::SETTING_AUTO_PERCENT,
            CompactorConfig::SETTING_BLOCK_PERCENT,
        ]);
        $readBy = '`Bootstrap::chat()`, `EngineBackend::compactorConfig()` → `CompactorConfig::fromSettings()`';
        $tier = static fn (string $key, int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::Restart)
            ->withRange(1, 99)
            ->withValidators($order)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
            ->withReadBy($readBy);
        $tuning = static fn (string $key, int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::Restart)
            ->withRange(1)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
            ->withReadBy($readBy);
        $cap = static fn (string $key, ?int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::Restart)
            ->withRange(0)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
            ->withReadBy($readBy);

        return [
            $tier(CompactorConfig::SETTING_REMINDER_PERCENT, $defaults->reminderThreshold, 'Reminder at (%)', 'Window share at which the "consider compacting" reminder and the ahead-of-need background summary start; below the automatic tier.'),
            $tier(CompactorConfig::SETTING_AUTO_PERCENT, $defaults->backgroundCompactionThreshold, 'Auto-compact at (%)', 'Window share at which older exchanges are condensed automatically before the prompt is sent.'),
            $tier(CompactorConfig::SETTING_BLOCK_PERCENT, $defaults->foregroundBlockingThreshold, 'Block input at (%)', 'Window share past which a prompt is refused until space is freed; the only tier that refuses.'),
            $tuning(CompactorConfig::SETTING_KEEP_RECENT, $defaults->recentPreserveCount, 'Keep recent exchanges', 'Most recent user/assistant exchanges a compaction keeps in full.'),
            $tuning(CompactorConfig::SETTING_SUMMARY_USER_CHARS, $defaults->summaryUserMaxChars, 'Summary: user chars', 'Characters of a user message the heuristic summary line keeps.'),
            $tuning(CompactorConfig::SETTING_SUMMARY_ASSISTANT_CHARS, $defaults->summaryAssistantMaxChars, 'Summary: assistant chars', 'Assistant replies longer than this become "[exchanged information]" in a heuristic summary line.'),
            $tuning(CompactorConfig::SETTING_TOOL_OUTPUT_CHARS, $defaults->toolOutputMaxChars, 'Summary: tool output chars', 'Characters of a condensed exchange\'s assistant half the summariser model is shown.'),
            $cap(CompactorConfig::SETTING_REMINDER_TOKENS, $defaults->reminderTokens, 'Reminder cap (tokens)', 'Absolute cap on the reminder tier: it fires at min(percentage, this). 0 turns the cap off.'),
            $cap(CompactorConfig::SETTING_AUTO_TOKENS, $defaults->backgroundCompactionTokens, 'Auto-compact cap (tokens)', 'Absolute cap on the automatic tier and the in-turn step budget. 0 turns the cap off.'),
            $cap(CompactorConfig::SETTING_BLOCK_TOKENS, $defaults->foregroundBlockingTokens, 'Block cap (tokens)', 'Absolute cap on the blocking tier; unset by default. Setting it lets a cap refuse prompts.')
                ->withDefaultText('unset (no cap)'),
            SettingDefinition::new(CompactorConfig::SETTING_MODEL_TOKEN_CAPS, SettingType::Map, [])
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Restart)
                ->withUi(UiEditability::Complex)
                ->withLabel('Per-model caps')
                ->withHelp('{"<model>" or "<provider>/<model>": {"reminderTokens", "autoTokens", "blockTokens"}} overriding the three caps; 0 clears one.')
                ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
                ->withReadBy($readBy . ' → `forModel()`'),
            // Roadmap N-P4d: the persisted form of SUGARCRUSH_DISABLE_SYMBOL_MAP.
            // config.json only, like the env switch it mirrors; captured once
            // per session, so it applies to the next one.
            SettingDefinition::new(SymbolMapBlock::SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Narrowing)
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV)
                ->withLabel('Symbol map')
                ->withHelp('Put the ranked symbol-level repo map in the system prompt, captured once per session.')
                ->withReaderSymbol(SymbolMapBlock::class . '::disabledBySettings')
                ->withReadBy('`SymbolMapBlock::capture()` → `disabledBySettings()`, at the session\'s first turn'),
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
