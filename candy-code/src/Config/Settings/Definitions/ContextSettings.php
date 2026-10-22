<?php

declare(strict_types=1);

namespace SugarCraft\Code\Config\Settings\Definitions;

use SugarCraft\Code\Config\Settings\ApplyMode;
use SugarCraft\Code\Config\Settings\RiskClass;
use SugarCraft\Code\Config\Settings\SettingCategory;
use SugarCraft\Code\Config\Settings\SettingDefinition;
use SugarCraft\Code\Config\Settings\SettingDefinitionSet;
use SugarCraft\Code\Config\Settings\SettingType;
use SugarCraft\Code\Config\Settings\UiEditability;
use SugarCraft\Code\Config\Settings\Validator\ThresholdOrderValidator;
use SugarCraft\Code\Cli\Bootstrap;
use SugarCraft\Code\Context\CompactorConfig;
use SugarCraft\Code\Context\EnvironmentBlock;
use SugarCraft\Code\Context\IdleCompactionPolicy;
use SugarCraft\Code\Context\Pruning\PruningMode;
use SugarCraft\Code\Context\RepoMapBlock;
use SugarCraft\Code\Context\SymbolMapBlock;
use SugarCraft\Code\Lang;
use SugarCraft\Code\Providers\ProviderFactory;

/**
 * The "Context" category's keys. One file per category so a step adding a
 * key edits only its category's file (DH-KEYS); {@see \SugarCraft\Code\Config\Settings\SettingsSchema}
 * collects every set.
 *
 * THE COMPACTION KEYS (roadmap N-P4b, Appendix N §2.2) are dotted flat keys
 * on disk (`"compaction.autoPercent": 80`), Appendix N §5.5's recommendation,
 * so the shallow layer merge never replaces one with another. Each default IS
 * the {@see CompactorConfig} constructor default it describes, read off a
 * fresh {@see CompactorConfig::new()} rather than restated. `Bootstrap::chat()`
 * hands the session one config built from them and a save rebuilds it
 * (`Chat::applySettings()`), so in the TUI they apply LIVE, to the next
 * prompt; a backend handed none — `-p`, an embedder — reads them itself, and
 * a server session reads them when it starts. The `contextPruning.*`
 * reminders ride the same config into the engine with each dispatch, so
 * they apply NEXT TURN. Every one but the breaker's limit is Tuning a
 * trusted project may set (Appendix N §2.2): a tier moved either way costs
 * the session time or context quality, never a refusal it could not get out
 * of — only the blocking percentage refuses, it is bounded at 99%, and
 * `compaction.mode: off` still leaves `/compact` to the person. The
 * breaker's limit is Spend and the operator's: raised, it lets a session
 * pay for more compactions that buy nothing.
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
        $readBy = '`Bootstrap::chat()`, `Chat::applySettings()`, `EngineBackend::compactorConfig()` → `CompactorConfig::fromSettings()`';
        $tier = static fn (string $key, int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::Live)
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
            ->withApplyMode(ApplyMode::Live)
            ->withRange(1)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
            ->withReadBy($readBy);
        $nudge = static fn (string $key, int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::NextTurn)
            ->withRange(1)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::nudgePolicy')
            ->withReadBy($readBy . ' → `nudgePolicy()`, the engine\'s step loop');
        $cap = static fn (string $key, ?int $default, string $label, string $help): SettingDefinition => SettingDefinition::new($key, SettingType::Int, $default)
            ->withCategory(SettingCategory::Context)
            ->withRiskClass(RiskClass::Tuning)
            ->withLayered()
            ->withProjectSettable()
            ->withApplyMode(ApplyMode::Live)
            ->withRange(0)
            ->withLabel($label)
            ->withHelp($help)
            ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
            ->withReadBy($readBy);

        return [
            $tier(CompactorConfig::SETTING_REMINDER_PERCENT, $defaults->reminderThreshold, Lang::t('settings.compaction.reminderPercent.label'), Lang::t('settings.compaction.reminderPercent.help')),
            $tier(CompactorConfig::SETTING_AUTO_PERCENT, $defaults->backgroundCompactionThreshold, Lang::t('settings.compaction.autoPercent.label'), Lang::t('settings.compaction.autoPercent.help')),
            $tier(CompactorConfig::SETTING_BLOCK_PERCENT, $defaults->foregroundBlockingThreshold, Lang::t('settings.compaction.blockPercent.label'), Lang::t('settings.compaction.blockPercent.help')),
            $tuning(CompactorConfig::SETTING_KEEP_RECENT, $defaults->recentPreserveCount, Lang::t('settings.compaction.keepRecent.label'), Lang::t('settings.compaction.keepRecent.help')),
            $tuning(CompactorConfig::SETTING_SUMMARY_USER_CHARS, $defaults->summaryUserMaxChars, Lang::t('settings.compaction.summaryUserChars.label'), Lang::t('settings.compaction.summaryUserChars.help')),
            $tuning(CompactorConfig::SETTING_SUMMARY_ASSISTANT_CHARS, $defaults->summaryAssistantMaxChars, Lang::t('settings.compaction.summaryAssistantChars.label'), Lang::t('settings.compaction.summaryAssistantChars.help')),
            $tuning(CompactorConfig::SETTING_TOOL_OUTPUT_CHARS, $defaults->toolOutputMaxChars, Lang::t('settings.compaction.toolOutputChars.label'), Lang::t('settings.compaction.toolOutputChars.help')),
            $cap(CompactorConfig::SETTING_REMINDER_TOKENS, $defaults->reminderTokens, Lang::t('settings.compaction.reminderTokens.label'), Lang::t('settings.compaction.reminderTokens.help')),
            $cap(CompactorConfig::SETTING_AUTO_TOKENS, $defaults->backgroundCompactionTokens, Lang::t('settings.compaction.autoTokens.label'), Lang::t('settings.compaction.autoTokens.help')),
            $cap(CompactorConfig::SETTING_BLOCK_TOKENS, $defaults->foregroundBlockingTokens, Lang::t('settings.compaction.blockTokens.label'), Lang::t('settings.compaction.blockTokens.help'))
                ->withDefaultText('unset (no cap)'),
            SettingDefinition::new(CompactorConfig::SETTING_MODEL_TOKEN_CAPS, SettingType::Map, [])
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withUi(UiEditability::Complex)
                ->withLabel(Lang::t('settings.compaction.modelTokenCaps.label'))
                ->withHelp(Lang::t('settings.compaction.modelTokenCaps.help'))
                ->withReaderSymbol(CompactorConfig::class . '::fromSettings')
                ->withReadBy($readBy . ' → `forModel()`'),
            // Roadmap N-P4b remainder: the idle offer, the summary mode and
            // the thrash breaker, each the constant or behaviour it replaced.
            SettingDefinition::new(CompactorConfig::SETTING_IDLE_OFFER_SECONDS, SettingType::Int, $defaults->idleOfferSeconds)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(0)
                ->withLabel(Lang::t('settings.compaction.idleOfferSeconds.label'))
                ->withHelp(Lang::t('settings.compaction.idleOfferSeconds.help'))
                ->withReaderSymbol(IdleCompactionPolicy::class . '::shouldPrompt')
                ->withReadBy($readBy . ' → `Chat::shouldPromptIdleCompaction()` → `IdleCompactionPolicy::shouldPrompt()`'),
            SettingDefinition::new(CompactorConfig::SETTING_MODE, SettingType::Enum, $defaults->mode)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::Live)
                ->withEnumValues(CompactorConfig::MODES)
                ->withLabel(Lang::t('settings.compaction.mode.label'))
                ->withHelp(Lang::t('settings.compaction.mode.help'))
                ->withReaderSymbol(CompactorConfig::class . '::summarisesWithModel')
                ->withReadBy($readBy . ' → `autoCompacts()`, `summarisesWithModel()`'),
            SettingDefinition::new(CompactorConfig::SETTING_REFILL_LIMIT, SettingType::Int, $defaults->refillLimit)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withRange(1)
                ->withLabel(Lang::t('settings.compaction.refillLimit.label'))
                ->withHelp(Lang::t('settings.compaction.refillLimit.help'))
                ->withReaderSymbol(IdleCompactionPolicy::class . '::thrashTripped')
                ->withReadBy($readBy . ' → `IdleCompactionPolicy::thrashTripped()`'),
            // CTX-5: the opt-in that turns the idle window from an offer into a
            // fold. User-tier like the breaker above it — the money side is the
            // existing spend cap, so the class stays Tuning with the offer — and
            // default-off because it bills a summarisation round-trip to a
            // session nobody is watching. Both hosts read it through the policy.
            SettingDefinition::new(CompactorConfig::SETTING_IDLE_AUTO, SettingType::Bool, false)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withApplyMode(ApplyMode::Live)
                ->withLabel(Lang::t('settings.compaction.idleAuto.label'))
                ->withHelp(Lang::t('settings.compaction.idleAuto.help'))
                ->withReaderSymbol(IdleCompactionPolicy::class . '::shouldAutoCompact')
                ->withReadBy($readBy . ' → `IdleCompactionPolicy::shouldAutoCompact()`'),
            // Roadmap 3.B-4 / N-P4b: the model's context reminders. Read with
            // the compaction keys and handed to the engine's step loop through
            // `CompactorConfig::nudgePolicy()`; a min above the max is ignored
            // as a pair, so neither half of a typo moves alone.
            $nudge(CompactorConfig::SETTING_NUDGE_MIN_TOKENS, $defaults->nudgeMinContextTokens, Lang::t('settings.contextPruning.minContextTokens.label'), Lang::t('settings.contextPruning.minContextTokens.help')),
            $nudge(CompactorConfig::SETTING_NUDGE_MAX_TOKENS, $defaults->nudgeMaxContextTokens, Lang::t('settings.contextPruning.maxContextTokens.label'), Lang::t('settings.contextPruning.maxContextTokens.help')),
            $nudge(CompactorConfig::SETTING_NUDGE_FREQUENCY, $defaults->nudgeFrequency, Lang::t('settings.contextPruning.nudgeFrequency.label'), Lang::t('settings.contextPruning.nudgeFrequency.help')),
            $nudge(CompactorConfig::SETTING_NUDGE_ITERATIONS, $defaults->nudgeIterationThreshold, Lang::t('settings.contextPruning.iterationNudgeThreshold.label'), Lang::t('settings.contextPruning.iterationNudgeThreshold.help')),
            // Whether the model's Compress is offered unprompted. config.json
            // only, like contextPruning.mode: DCP #611 — under pressure a model
            // compresses content still needed — so only the person turns it on.
            SettingDefinition::new(CompactorConfig::SETTING_COMPRESS, SettingType::Enum, \SugarCraft\Code\Tools\BuiltIn\Compress::MODE_DEFAULT)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnumValues(CompactorConfig::COMPRESS_MODES)
                ->withLabel(Lang::t('settings.contextPruning.compress.label'))
                ->withHelp(Lang::t('settings.contextPruning.compress.help'))
                ->withReaderSymbol(CompactorConfig::class . '::offersCompressUnprompted')
                ->withReadBy('`Chat::applySettings()`, `EngineBackend::gatedLedgerTools()` → `CompactorConfig::offersCompressUnprompted()`'),
            // Roadmap N-P4d: the persisted form of CANDY_CODE_DISABLE_SYMBOL_MAP.
            // config.json only, like the env switch it mirrors; captured once
            // per session, so it applies to the next one.
            SettingDefinition::new(SymbolMapBlock::SETTING, SettingType::Bool, true)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Narrowing)
                ->withApplyMode(ApplyMode::Restart)
                ->withEnvVar(SymbolMapBlock::SYMBOL_MAP_OPT_OUT_ENV)
                ->withLabel(Lang::t('settings.symbolMap.enabled.label'))
                ->withHelp(Lang::t('settings.symbolMap.enabled.help'))
                ->withReaderSymbol(SymbolMapBlock::class . '::disabledBySettings')
                ->withReadBy('`SymbolMapBlock::capture()` → `disabledBySettings()`, at the session\'s first turn'),
            // Roadmap N-P4d: the repo map and the `<env>` git sections. Each is
            // laid over the session's capture once per turn by Runtime, so a
            // saved change applies next turn. Switching a block off only
            // removes text, so a trusted project may; raising a byte cap
            // bills more input on every request, so that half is the
            // operator's (the toolOutputCapBytes argument).
            SettingDefinition::new(RepoMapBlock::SETTING_ENABLED, SettingType::Bool, true)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel(Lang::t('settings.repoMap.enabled.label'))
                ->withHelp(Lang::t('settings.repoMap.enabled.help'))
                ->withReaderSymbol(RepoMapBlock::class . '::enabledBySettings')
                ->withReadBy('`Runtime::repoMapSnapshot()` → `RepoMapBlock::enabledBySettings()`, per turn'),
            SettingDefinition::new(RepoMapBlock::SETTING_MAX_BYTES, SettingType::Int, RepoMapBlock::MAX_SECTION_BYTES)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(RepoMapBlock::MAX_ENTRY_BYTES)
                ->withLabel(Lang::t('settings.repoMap.maxBytes.label'))
                ->withHelp(Lang::t('settings.repoMap.maxBytes.help'))
                ->withReaderSymbol(RepoMapBlock::class . '::withSettings')
                ->withReadBy('`Runtime::repoMapSnapshot()` → `RepoMapBlock::withSettings()`, per turn'),
            SettingDefinition::new(EnvironmentBlock::SETTING_GIT_DIFF_AFTER_WRITES, SettingType::Bool, true)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Narrowing)
                ->withLayered()
                ->withProjectSettable()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withLabel(Lang::t('settings.env.gitDiffAfterWrites.label'))
                ->withHelp(Lang::t('settings.env.gitDiffAfterWrites.help'))
                ->withReaderSymbol(EnvironmentBlock::class . '::withSettings')
                ->withReadBy('`Runtime::environmentSnapshot()` → `EnvironmentBlock::withSettings()`, per turn'),
            SettingDefinition::new(EnvironmentBlock::SETTING_DIFF_MAX_BYTES, SettingType::Int, EnvironmentBlock::DIFF_MAX_BYTES)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Spend)
                ->withLayered()
                ->withApplyMode(ApplyMode::NextTurn)
                ->withRange(EnvironmentBlock::MIN_DIFF_MAX_BYTES)
                ->withLabel(Lang::t('settings.env.diffMaxBytes.label'))
                ->withHelp(Lang::t('settings.env.diffMaxBytes.help'))
                ->withReaderSymbol(EnvironmentBlock::class . '::withSettings')
                ->withReadBy('`Runtime::environmentSnapshot()` → `EnvironmentBlock::withSettings()`, per turn'),
            // Roadmap N-P4d: the launch-notice shelf. config.json only, read
            // while the launch records its notices, so it applies at restart.
            SettingDefinition::new(Bootstrap::SETTING_LAUNCH_NOTICE_LIMIT, SettingType::Int, Bootstrap::LAUNCH_NOTICE_LIMIT)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::Restart)
                ->withRange(0)
                ->withLabel(Lang::t('settings.notices.transcriptLimit.label'))
                ->withHelp(Lang::t('settings.notices.transcriptLimit.help'))
                ->withReaderSymbol(Bootstrap::class . '::launchNoticeLimit')
                ->withReadBy('`Bootstrap::warnPermissionConfigInTranscript()` → `launchNoticeLimit()`, at launch'),
            SettingDefinition::new('contextWindow', SettingType::Json)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withLayered()
                ->withLabel(Lang::t('settings.contextWindow.label'))
                ->withHelp(Lang::t('settings.contextWindow.help'))
                ->withReaderSymbol(ProviderFactory::class . '::createOpenAI')
                ->withReadBy('`ProviderFactory::createOpenAI()`, `createAnthropic()`, `createBedrock()`, `createSglang()`, `createVertex()`, `createCustom()` → each provider\'s `contextWindow()`'),
            // Roadmap 3.B-2: the default for every session that has not set
            // its own with `/pruning`. User config only — a cloned project
            // must not be able to turn a person's pruning on or off.
            SettingDefinition::new(PruningMode::SETTING, SettingType::Enum, PruningMode::DEFAULT->value)
                ->withCategory(SettingCategory::Context)
                ->withRiskClass(RiskClass::Tuning)
                ->withApplyMode(ApplyMode::NextTurn)
                ->withEnvVar(PruningMode::ENV)
                ->withEnumValues(array_map(static fn (PruningMode $m): string => $m->value, PruningMode::cases()))
                ->withLabel(Lang::t('settings.contextPruning.mode.label'))
                ->withHelp(Lang::t('settings.contextPruning.mode.help'))
                ->withReaderSymbol(PruningMode::class . '::configured')
                ->withReadBy('`Host\\TurnRunner::start()` and `/pruning` → `PruningMode::configured()`'),
        ];
    }
}
